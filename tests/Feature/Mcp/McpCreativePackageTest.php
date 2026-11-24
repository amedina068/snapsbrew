<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\User;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\CallsCreativePackageMcpTools;
use Tests\Support\FakeRenderer;
use Tests\Support\InteractsWithMcp;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * The two package tools over the real `/mcp` route with a bearer token.
 *
 * The queue runs on the `database` driver where a test needs the export to
 * return before the render, which is the shape a real client sees. The
 * `sync` driver would render inside the export call and hide the `building`
 * state that the client polls on.
 */
class McpCreativePackageTest extends TestCase
{
    use BuildsCreativePackages;
    use CallsCreativePackageMcpTools;
    use InteractsWithMcp;
    use RefreshDatabase;
    use WithPassportKeys;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        // A bad bearer token reaches Passport's guard, which needs the key pair.
        $this->setUpWithPassportKeys();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
        config(['queue.default' => 'database']);
    }

    // ------------------------------------------------------------------ auth

    public function test_both_tools_need_a_valid_token_that_carries_the_mcp_ability(): void
    {
        $actor = $this->member();
        $wrongAbility = $actor->createToken('test', ['other_ability'])->plainTextToken;

        foreach (['export-creative-package', 'get-creative-package'] as $tool) {
            $this->forgetTheCaller()->postJson('/mcp', $this->envelope($tool))->assertStatus(401);
            $this->forgetTheCaller()->withHeader('Authorization', 'Bearer 1|not-a-token')->postJson('/mcp', $this->envelope($tool))->assertStatus(401);
            $this->forgetTheCaller()->withHeader('Authorization', "Bearer {$wrongAbility}")->postJson('/mcp', $this->envelope($tool))->assertStatus(403);
        }

        $this->assertSame(0, CreativePackage::count());
    }

    // --------------------------------------------------------------- journey

    public function test_export_returns_building_before_the_queue_runs_and_get_returns_ready_after(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $request = $this->request('launch', [$this->source20($actor)]);

        $export = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));

        $this->assertSame(array_keys(array_flip(CreativePackageContract::RESPONSE_FIELDS)), array_keys($export));
        $this->assertSame(['building', false, 'poll', 30, null, null], [$export['status'], $export['replayed'], $export['next_action'], $export['poll_after_seconds'], $export['url_expires_at'], $export['manifest']]);
        $this->assertSame(['total' => 1, 'pending' => 1, 'rendering' => 0, 'ready' => 0, 'failed' => 0], $export['counts']);
        $this->assertSame(0, ApiLog::count());

        $this->work();

        $get = $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => $export['package_id']]));

        $this->assertSame(['ready', false, 'download', null], [$get['status'], $get['replayed'], $get['next_action'], $get['poll_after_seconds']]);
        $this->assertSame($this->now()->addSeconds(600)->toIso8601ZuluString(), $get['url_expires_at']);
        $item = $get['manifest']['items'][0];
        $this->assertSame(['creative-0', 'ready', null], [$item['creative_id'], $item['status'], $item['failure']]);
        $this->assertStringContainsString("/{$export['package_id']}/items/", $item['file_url']);
        $this->assertStringNotContainsString('\/', $this->tool($token, 'get-creative-package', ['package_id' => $export['package_id']])->json('result.content.0.text'));
        $this->get($item['file_url'])->assertOk();
        $this->assertSame(1, ApiLog::sole()->renders);
    }

    public function test_a_partial_package_replays_with_the_same_key_and_reads_the_same_state(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        // The fake renderer answers 20x20, so the 1080x1080 source fails its output check.
        $request = $this->request('mixed', [$this->source20($actor), $this->source($actor, 1)]);
        $started = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));
        $this->work();

        $replay = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));
        $read = $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => $started['package_id']]));

        $this->assertSame([$started['package_id'], 'partial', true, 'retry_incomplete'], [$replay['package_id'], $replay['status'], $replay['replayed'], $replay['next_action']]);
        $this->assertSame(['total' => 2, 'pending' => 0, 'rendering' => 0, 'ready' => 1, 'failed' => 1], $replay['counts']);
        $this->assertSame(['ready', 'failed'], array_column($replay['manifest']['items'], 'status'));
        $this->assertSame('creative_package_output_invalid', $replay['manifest']['items'][1]['failure']['code']);
        $this->assertNull($replay['manifest']['items'][1]['file_url']);
        // A partial package signs the ready item. Its URLs are not null.
        $this->assertNotNull($replay['url_expires_at']);
        $this->assertNotNull($replay['manifest']['items'][0]['file_url']);
        $this->assertSame(['replayed' => false] + $replay, ['replayed' => false] + $read);
        $this->assertSame(1, CreativePackage::count());
    }

    public function test_repeated_polling_records_no_usage_renders_nothing_and_signs_fresh_urls(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $packageId = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $this->request('poll', [$this->source20($actor)])))['package_id'];
        $this->work();
        $logged = ApiLog::count();
        $renders = count($this->renderer->screenshotCalls);

        $first = $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => $packageId]));
        $later = $this->now()->addMinutes(5);
        CarbonImmutable::setTestNow($later);
        $expiries = [];
        foreach (range(1, 3) as $poll) {
            $expiries[] = $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => $packageId]))['url_expires_at'];
        }

        $this->assertSame([$logged, $renders], [ApiLog::count(), count($this->renderer->screenshotCalls)]);
        $this->assertSame($this->now()->addSeconds(600)->toIso8601ZuluString(), $first['url_expires_at']);
        $this->assertSame(array_fill(0, 3, $later->addSeconds(600)->toIso8601ZuluString()), $expiries);
    }

    public function test_get_reads_an_upper_case_id_as_the_same_package(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $packageId = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $this->request('case', [$this->source20($actor)])))['package_id'];

        $lower = $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => $packageId]));
        $upper = $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => strtoupper($packageId)]));

        $this->assertSame($packageId, $lower['package_id']);
        $this->assertSame($lower, $upper);
    }

    public function test_a_package_whose_every_item_fails_reads_as_failed_with_no_url(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        // Both 1080x1080 sources fail the output check against the 20x20 fake renderer.
        $packageId = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $this->request('doomed', [$this->source($actor, 1), $this->source($actor, 2)])))['package_id'];
        $this->work();

        $response = $this->tool($token, 'get-creative-package', ['package_id' => $packageId]);
        $get = $this->mcpToolPayload($response);

        $this->assertSame(['failed', 'inspect_failures', null, null], [$get['status'], $get['next_action'], $get['url_expires_at'], $get['manifest']]);
        $this->assertSame(0, ApiLog::count());
        $this->assertSame(['total' => 2, 'pending' => 0, 'rendering' => 0, 'ready' => 0, 'failed' => 2], $get['counts']);
        $this->assertStringNotContainsString('file_url', $response->json('result.content.0.text'));
        $this->assertStringNotContainsString('preview_url', $response->json('result.content.0.text'));
    }

    // ---------------------------------------------------------------- errors

    public function test_export_answers_a_decoded_failure_as_a_tool_error_with_bounded_fields(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $request = $this->request('shape', [$this->source($actor, 1)]);
        unset($request['items'][0]['alt_text']);

        $error = $this->mcpToolError($this->tool($token, 'export-creative-package', $request));

        $this->assertSame(['creative_package_invalid_request', false, ['items.0.alt_text']], [$error['code'], $error['retryable'], $error['details']['fields']]);
        $this->assertSame(0, CreativePackage::count());
    }

    public function test_export_names_every_claim_refusal_with_its_registry_code(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $request = $this->request('first', [$this->source($actor, 1)]);
        $this->tool($token, 'export-creative-package', $request);

        $foreign = $this->request('foreign', [$this->source(User::factory()->for(Company::factory())->create(), 1)]);
        $conflict = $this->request('first', [$this->source($actor, 2)]);
        $cases = [
            'creative_package_source_not_found' => $foreign,
            'creative_package_idempotency_conflict' => $conflict,
        ];
        foreach ($cases as $code => $arguments) {
            $error = $this->mcpToolError($this->tool($token, 'export-creative-package', $arguments));
            $this->assertSame($code, $error['code']);
            $this->assertArrayHasKey('details', $error);
        }
        $this->assertSame(['first', CreativePackage::sole()->uuid], array_values($this->mcpToolError($this->tool($token, 'export-creative-package', $conflict))['details']));

        foreach (range(1, 2) as $slot) {
            CreativePackage::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id, 'status' => CreativePackage::STATUS_BUILDING, 'lease_expires_at' => $this->now()->addMinutes(5)]);
        }
        $limited = $this->mcpToolError($this->tool($token, 'export-creative-package', $this->request('fourth', [$this->source($actor, 3)])));
        $this->assertSame(['creative_package_rate_limited', true, 30], [$limited['code'], $limited['retryable'], $limited['details']['poll_after_seconds']]);

        $viewer = $this->memberOf(Company::factory()->create(), role: 'viewer');
        $denied = $this->mcpToolError($this->tool($this->token($viewer), 'export-creative-package', $this->request('viewer', [$this->source($viewer, 1)])));
        $this->assertSame(['creative_package_entitlement_required', false], [$denied['code'], $denied['retryable']]);
        $this->assertArrayNotHasKey('details', $denied);
    }

    public function test_get_answers_an_absent_id_and_a_foreign_id_with_the_same_bytes(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $foreign = CreativePackage::factory()->create();
        $absent = 'a0000000-0000-4000-8000-000000000000';

        $foreignText = $this->tool($token, 'get-creative-package', ['package_id' => $foreign->uuid])->json('result.content.0.text');
        $absentText = $this->tool($token, 'get-creative-package', ['package_id' => $absent])->json('result.content.0.text');

        $this->assertSame('creative_package_not_found', json_decode($foreignText, true)['error']['code']);
        $this->assertSame($foreignText, str_replace($absent, $foreign->uuid, $absentText));
        $this->assertSame(0, ApiLog::count());
    }

    public function test_get_accepts_exactly_one_uuid_and_nothing_else(): void
    {
        $token = $this->token($this->member());
        $cases = [
            'not a uuid' => ['package_id' => 'launch'],
            'not a string' => ['package_id' => 42],
            'a second field' => ['package_id' => 'a0000000-0000-4000-8000-000000000000', 'replayed' => true],
            'no field' => [],
        ];

        foreach ($cases as $case => $arguments) {
            $error = $this->mcpToolError($this->tool($token, 'get-creative-package', $arguments));
            $this->assertSame(['creative_package_invalid_request', ['package_id']], [$error['code'], $error['details']['fields']], $case);
        }
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function envelope(string $tool): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]];
    }
}
