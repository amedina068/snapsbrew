<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class SnapBatchCancelApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
        Mail::fake();
        Http::fake();
    }

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$user, $user->createToken('test', ['api_requests'])->plainTextToken];
    }

    private function asApi(string $token)
    {
        // A test-process artifact. One PHP process serves every request here and
        // the sanctum guard caches the user it resolved first.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function template(User $user): Template
    {
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'layers' => json_encode([[
                'key' => 'headline',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'X']],
            ]]),
        ]);
    }

    private function startBatch(string $token, Template $template, int $count = 3): string
    {
        return $this->asApi($token)->postJson('/api/v1/snaps/batches', [
            'template_id' => $template->uuid,
            'items' => collect(range(1, $count))->map(fn ($n) => [
                'identifier' => 'row-'.$n,
                'variants' => [['layer' => 'headline', 'text' => 'Row '.$n]],
            ])->all(),
        ])->assertStatus(202)->json('uuid');
    }

    public function test_cancel_stops_a_running_batch()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $uuid = $this->startBatch($token, $this->template($user));

        $response = $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('status', SnapImport::STATUS_CANCELED)
            ->assertJsonPath('canceled', 3)
            ->assertJsonPath('pending', 0);

        $this->assertSame(3, Snap::where('status', Snap::STATUS_CANCELED)->count());
    }

    public function test_a_canceled_item_records_no_render()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $uuid = $this->startBatch($token, $this->template($user));

        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")->assertStatus(200);

        $this->assertSame(
            0,
            (int) ApiLog::where('url', 'snap-import:'.$uuid)->sum('renders'),
            'Nothing rendered, so nothing is recorded.'
        );
    }

    public function test_a_worker_that_reaches_a_canceled_item_does_not_render_it()
    {
        config(['queue.default' => 'database']);
        [$user, $token] = $this->createApiUser();
        $uuid = $this->startBatch($token, $this->template($user));

        // Cancel while every job is still queued, then let the worker run.
        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")->assertStatus(200);

        $this->artisan('queue:work', [
            '--queue' => Queues::IMPORTS,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--memory' => 512,
        ]);

        $this->assertSame(
            0,
            Snap::where('status', Snap::STATUS_COMPLETED)->count(),
            'ProcessSnap reads the cancel flag and must stop before it renders.'
        );
        $this->assertSame(
            0,
            (int) ApiLog::where('url', 'snap-import:'.$uuid)->sum('renders')
        );
        $this->assertSame(SnapImport::STATUS_CANCELED, SnapImport::first()->fresh()->status);
    }

    public function test_an_item_that_already_rendered_stays_completed_and_stays_recorded()
    {
        config(['queue.default' => 'database']);
        [$user, $token] = $this->createApiUser();
        $uuid = $this->startBatch($token, $this->template($user), 3);

        // Run the queue first so every item renders, then cancel.
        $this->artisan('queue:work', [
            '--queue' => Queues::IMPORTS,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--memory' => 512,
        ]);

        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")->assertStatus(200);

        $this->assertSame(3, Snap::where('status', Snap::STATUS_COMPLETED)->count());
        $this->assertSame(
            3,
            (int) ApiLog::where('url', 'snap-import:'.$uuid)->sum('renders'),
            'Work that was done stays in the usage log.'
        );
        $this->assertSame(
            SnapImport::STATUS_COMPLETED,
            SnapImport::first()->fresh()->status,
            'A finished batch is left alone.'
        );
    }

    public function test_cancel_is_safe_to_repeat()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $uuid = $this->startBatch($token, $this->template($user));

        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")->assertStatus(200);
        $canceledAt = SnapImport::first()->fresh()->canceled_at;

        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('status', SnapImport::STATUS_CANCELED);

        $this->assertEquals(
            $canceledAt,
            SnapImport::first()->fresh()->canceled_at,
            'A second cancel must change nothing.'
        );
    }

    public function test_cancel_posts_one_webhook()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $uuid = $this->asApi($token)->postJson('/api/v1/snaps/batches', [
            'template_id' => $template->uuid,
            'webhook_url' => 'https://example.com/hooks/batch',
            'items' => [['variants' => [['layer' => 'headline', 'text' => 'One']]]],
        ])->assertStatus(202)->json('uuid');

        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")->assertStatus(200);

        $this->assertSame(
            1,
            Http::recorded(fn ($request) => $request->url() === 'https://example.com/hooks/batch')->count()
        );
        Http::assertSent(fn ($request) => $request['event'] === 'snap-import.canceled');
    }

    public function test_cancel_returns_the_item_list()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $uuid = $this->startBatch($token, $this->template($user), 2);

        $this->asApi($token)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")
            ->assertStatus(200)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.status', Snap::STATUS_CANCELED);
    }

    public function test_one_company_cannot_cancel_another_companys_batch()
    {
        Bus::fake();
        [$userA, $tokenA] = $this->createApiUser();
        [$userB, $tokenB] = $this->createApiUser();

        $uuid = $this->startBatch($tokenA, $this->template($userA));

        $this->asApi($tokenB)->postJson("/api/v1/snaps/batches/{$uuid}/cancel")->assertStatus(404);

        $this->assertSame(
            SnapImport::STATUS_PROCESSING,
            SnapImport::first()->fresh()->status,
            'The batch must be untouched.'
        );
    }

    public function test_an_unknown_batch_id_returns_404()
    {
        [$user, $token] = $this->createApiUser();

        $this->asApi($token)
            ->postJson('/api/v1/snaps/batches/'.Str::uuid().'/cancel')
            ->assertStatus(404);
    }
}
