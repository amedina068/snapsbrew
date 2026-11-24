<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\CancelSnapsBatchTool;
use App\Mcp\Tools\CreateSnapsBatchTool;
use App\Mcp\Tools\GetSnapsBatchTool;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapBatchService;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeRenderer;
use Tests\Support\SelectivelyFailingRenderer;
use Tests\TestCase;

class McpSnapsBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    private function makeUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user;
    }

    private function template(User $user): Template
    {
        return Template::factory()->withLayers([
            ['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => 'Hi'],
            ]],
        ])->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => '100',
            'height' => '100',
        ]);
    }

    /** @param array<int, string> $headlines */
    private function items(array $headlines): array
    {
        return collect($headlines)->map(fn ($headline, $index) => [
            'identifier' => 'row-'.($index + 1),
            'variants' => [['layer' => 'headline', 'text' => $headline]],
        ])->all();
    }

    private function work(): void
    {
        // The worker runs inside the PHPUnit process, which sits near the
        // default 128 MB limit late in a full run. Without --memory, the
        // worker stops after one job and the later items stay pending.
        $this->artisan('queue:work', [
            '--queue' => Queues::IMPORTS,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--memory' => 512,
        ]);
    }

    // ---------------------------------------------------------------- create

    public function test_create_starts_a_batch_and_records_no_render_yet()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', 'Two', 'Three']),
        ])
            ->assertOk()
            ->assertDontSee('credits_charged')
            ->assertSee('Batch started with 3 items.');

        $this->assertSame(3, Snap::count());
        $this->assertSame(0, (int) ApiLog::sum('renders'));
    }

    public function test_create_names_the_poll_tool_and_the_stop_condition()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One']),
        ])
            ->assertOk()
            ->assertSee('get-snaps-batch')
            ->assertSee('completed, failed or canceled');
    }

    public function test_create_refuses_a_template_from_another_company()
    {
        Bus::fake();
        $user = $this->makeUser();
        $other = Template::factory()->create();

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $other->uuid,
            'items' => $this->items(['One']),
        ])->assertHasErrors();

        $this->assertSame(0, Snap::count());
    }

    public function test_create_refuses_more_items_than_the_cap()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(array_fill(0, SnapBatchService::MAX_ITEMS + 1, 'Row')),
        ])->assertHasErrors();

        $this->assertSame(0, Snap::count());
    }

    public function test_create_refuses_an_internal_webhook_url()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
            'items' => $this->items(['One']),
        ])->assertHasErrors();

        $this->assertSame(0, Snap::count());
    }

    public function test_a_repeated_key_returns_the_same_batch_and_says_so()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        $payload = [
            'template_id' => $template->uuid,
            'idempotency_key' => 'batch-retry-1',
            'items' => $this->items(['One', 'Two']),
        ];

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, $payload)
            ->assertOk()
            ->assertSee('"replayed": false');

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, $payload)
            ->assertOk()
            ->assertSee('"replayed": true')
            ->assertSee('It was returned, not started again');

        $this->assertSame(1, SnapImport::count());
        $this->assertSame(2, Snap::count());
    }

    // ------------------------------------------------------------------ read

    public function test_get_reports_progress_while_the_batch_runs()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', 'Two']),
        ])->assertOk();

        SnapsbrewServer::actingAs($user)->tool(GetSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])
            ->assertOk()
            ->assertSee('"pending": 2')
            ->assertSee('Poll again in about 5 seconds');
    }

    public function test_get_reports_a_partial_result_in_one_true_sentence()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', SelectivelyFailingRenderer::MARKER, 'Three']),
        ])->assertOk();

        $this->work();

        SnapsbrewServer::actingAs($user)->tool(GetSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])
            ->assertOk()
            ->assertSee('"status": "completed"')
            ->assertDontSee('credits_charged')
            ->assertSee('2 of 3 items rendered.')
            ->assertSee('1 failed.')
            ->assertSee('This is final.');
    }

    public function test_get_does_not_claim_a_render_when_every_item_failed()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items([
                SelectivelyFailingRenderer::MARKER.' one',
                SelectivelyFailingRenderer::MARKER.' two',
            ]),
        ])->assertOk();

        $this->work();

        SnapsbrewServer::actingAs($user)->tool(GetSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])
            ->assertOk()
            ->assertSee('"status": "failed"')
            ->assertSee('no item rendered')
            ->assertSee('This is final.');
    }

    public function test_get_returns_every_item_with_its_status()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', SelectivelyFailingRenderer::MARKER]),
        ])->assertOk();

        $this->work();

        SnapsbrewServer::actingAs($user)->tool(GetSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])
            ->assertOk()
            ->assertSee('"identifier": "row-1"')
            ->assertSee('"identifier": "row-2"')
            // The failed item carries the fixed code. The renderer message
            // stays in the log.
            ->assertSee('render_failed')
            ->assertDontSee('render exploded on purpose');
    }

    // ---------------------------------------------------------------- cancel

    public function test_cancel_stops_a_running_batch_and_says_what_rendered()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', 'Two', 'Three']),
        ])->assertOk();

        SnapsbrewServer::actingAs($user)->tool(CancelSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])
            ->assertOk()
            ->assertSee('"stopped": true')
            ->assertSee('"status": "canceled"')
            ->assertSee('0 of 3 items rendered.')
            ->assertSee('3 were stopped.')
            ->assertSee('cannot be undone');

        $this->assertSame(3, Snap::where('status', Snap::STATUS_CANCELED)->count());
        $this->assertSame(0, (int) ApiLog::sum('renders'));
    }

    public function test_cancel_leaves_a_finished_batch_alone_and_says_so()
    {
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', 'Two']),
        ])->assertOk();

        $this->work();

        SnapsbrewServer::actingAs($user)->tool(CancelSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])
            ->assertOk()
            ->assertSee('"stopped": false')
            ->assertSee('had already finished with status completed')
            ->assertSee('Nothing changed.');

        $this->assertSame(2, Snap::where('status', Snap::STATUS_COMPLETED)->count());
    }

    public function test_cancel_refuses_a_batch_from_another_company()
    {
        Bus::fake();
        $user = $this->makeUser();
        $other = $this->makeUser();

        SnapsbrewServer::actingAs($other)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $this->template($other)->uuid,
            'items' => $this->items(['One']),
        ])->assertOk();

        SnapsbrewServer::actingAs($user)->tool(CancelSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])->assertHasErrors();

        $this->assertSame(
            SnapImport::STATUS_PROCESSING,
            SnapImport::first()->fresh()->status,
            'The batch must be untouched.'
        );
    }

    public function test_get_refuses_a_batch_from_another_company()
    {
        Bus::fake();
        $user = $this->makeUser();
        $other = $this->makeUser();

        SnapsbrewServer::actingAs($other)->tool(CreateSnapsBatchTool::class, [
            'template_id' => $this->template($other)->uuid,
            'items' => $this->items(['One']),
        ])->assertOk();

        SnapsbrewServer::actingAs($user)->tool(GetSnapsBatchTool::class, [
            'batch_id' => SnapImport::first()->uuid,
        ])->assertHasErrors();
    }
}
