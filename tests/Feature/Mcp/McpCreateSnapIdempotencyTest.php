<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\CreateSnapTool;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The backlog line names agents: "Agents retry on timeouts." This is that path.
 */
class McpCreateSnapIdempotencyTest extends TestCase
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
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
    }

    public function test_a_repeated_key_returns_the_same_snap_and_records_no_second_render()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        $arguments = [
            'template_id' => $template->uuid,
            'idempotency_key' => 'agent-retry-1',
        ];

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, $arguments)
            ->assertOk()
            ->assertSee('"replayed": false');

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, $arguments)
            ->assertOk()
            ->assertSee('"replayed": true')
            // The note is the one line an agent reads to decide whether it just
            // made a new snap. A replay that says "Snap created." is a bug.
            ->assertSee('This snap already existed for that idempotency_key');

        $this->assertSame(1, Snap::query()->count());

        // One usage row, not two. This is the double count the key prevents.
        $this->assertSame(1, ApiLog::query()->where('feature', UsageRecorder::FEATURE_MCP)->count());
    }

    public function test_a_call_without_a_key_creates_a_snap_every_time()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, ['template_id' => $template->uuid])->assertOk();
        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, ['template_id' => $template->uuid])->assertOk();

        // Recorded on purpose. The key is optional, so a keyless retry renders
        // again. That is why CreateSnapTool must not claim idempotency.
        $this->assertSame(2, Snap::query()->count());
        $this->assertSame(2, ApiLog::query()->where('feature', UsageRecorder::FEATURE_MCP)->count());
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_the_tool_does_not_claim_to_be_idempotent()
    {
        // The key is optional, so the tool is not idempotent for every call.
        // Adding the annotation would state something false for a keyless call.
        $annotations = app(CreateSnapTool::class)->toArray()['annotations'];

        $this->assertFalse(
            $annotations['idempotentHint'] ?? false,
            'create-snap must not claim idempotency while idempotency_key is optional.'
        );
    }

    public function test_a_claim_still_in_progress_returns_an_error_and_creates_nothing()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'in-flight',
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        ]);

        SnapsbrewServer::actingAs($user)
            ->tool(CreateSnapTool::class, [
                'template_id' => $template->uuid,
                'idempotency_key' => 'in-flight',
            ])
            ->assertHasErrors();

        $this->assertSame(0, Snap::query()->count());
        $this->assertSame(0, ApiLog::query()->count());
    }

    /**
     * A key that a batch owns is not "in progress". The error must carry its
     * own code and must not say that a retry is safe.
     */
    public function test_a_key_that_belongs_to_a_batch_returns_its_own_code_and_creates_nothing()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'batch-key',
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'snap_import_id' => SnapImport::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'template_id' => $template->id,
                'title' => 'API batch',
                'status' => 'pending',
                'total_rows' => 0,
            ])->id,
        ]);

        SnapsbrewServer::actingAs($user)
            ->tool(CreateSnapTool::class, [
                'template_id' => $template->uuid,
                'idempotency_key' => 'batch-key',
            ])
            ->assertHasErrors()
            ->assertSee('idempotency_key_belongs_to_batch')
            ->assertDontSee('idempotency_key_in_progress');

        $this->assertSame(0, Snap::query()->count());
    }
}
