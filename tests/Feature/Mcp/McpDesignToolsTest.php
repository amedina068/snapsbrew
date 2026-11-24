<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Jobs\ProcessSnap;
use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\CreateSnapFromDesignTool;
use App\Mcp\Tools\CreateSnapTool;
use App\Mcp\Tools\PreviewDesignTool;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Rules\ValidLayers;
use App\Services\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeRenderer;
use Tests\Support\ReadsMcpToolErrors;
use Tests\TestCase;

/**
 * The design tools take a canvas and a layers definition on the request, with
 * no template row behind them. `preview-design` writes nothing.
 * `create-snap-from-design` runs the checks that `create-snap` runs and records
 * one render one time.
 */
class McpDesignToolsTest extends TestCase
{
    use ReadsMcpToolErrors, RefreshDatabase;

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

    /** @return array<int, array<string, mixed>> */
    private function layers(string $text = 'Inline headline'): array
    {
        return [
            ['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => $text],
            ]],
        ];
    }

    public function test_preview_design_returns_watermarked_html_and_echoes_the_design()
    {
        $user = $this->makeUser();

        $response = SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'width' => 640,
            'height' => 360,
            'layers' => $this->layers('Echo me'),
        ])->assertOk();

        $response
            ->assertSee('PREVIEW')
            ->assertSee('Echo me')
            ->assertSee('"preview": true')
            ->assertDontSee('credits_charged')
            ->assertSee('"width": 640')
            ->assertSee('"height": 360')
            ->assertSee('"size": "custom"')
            ->assertSee('"key": "headline"')
            ->assertSee('"textContent"')
            ->assertSee('create-snap-from-design');
    }

    public function test_preview_design_accepts_a_size_preset()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'size' => 'instagram_story',
            'layers' => $this->layers(),
        ])->assertOk()
            ->assertSee('"size": "instagram_story"')
            ->assertSee('"width": 1080')
            ->assertSee('"height": 1920');
    }

    public function test_preview_design_accepts_an_empty_canvas_with_no_layers()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'width' => 100,
            'height' => 100,
        ])->assertOk()->assertSee('"layers": []');
    }

    public function test_preview_design_rejects_a_layer_without_key()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'width' => 100,
            'height' => 100,
            'layers' => [['properties' => []]],
        ])->assertHasErrors()->assertSee('missing a non-empty string `key`');
    }

    public function test_preview_design_rejects_an_internal_background_image()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'width' => 100,
            'height' => 100,
            'layers' => [['key' => 'bg', 'properties' => [
                ['name' => 'backgroundImage', 'value' => 'http://10.0.0.1/x.png'],
            ]]],
        ])->assertHasErrors()->assertSee('internal, reserved, or unsafe address');
    }

    public function test_preview_design_rejects_an_over_cap_design_before_it_reads_any_background_image()
    {
        $user = $this->makeUser();

        // Every layer carries an unsafe URL. If the layer cap does not stop
        // validation, `SafeLayers` reads all of them and its message appears
        // beside the cap message. The cap exists to bound that scan.
        $layers = [];
        for ($i = 1; $i <= ValidLayers::MAX_LAYERS + 1; $i++) {
            $layers[] = ['key' => "layer_{$i}", 'properties' => [
                ['name' => 'backgroundImage', 'value' => "file:///etc/passwd?{$i}"],
            ]];
        }

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'width' => 100,
            'height' => 100,
            'layers' => $layers,
        ])
            ->assertHasErrors()
            ->assertSee('A template can hold at most 200 layers; this one has 201.')
            ->assertDontSee('internal, reserved, or unsafe address');
    }

    public function test_preview_design_requires_a_canvas()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'layers' => $this->layers(),
        ])->assertHasErrors()->assertSee('Pass a `size` preset or an explicit `width` and `height`.');
    }

    public function test_preview_design_rejects_an_unknown_size_preset()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'size' => 'myspace_banner',
            'layers' => $this->layers(),
        ])->assertHasErrors()->assertSee('list-template-sizes');
    }

    public function test_preview_design_creates_no_api_log_and_no_snap()
    {
        $user = $this->makeUser();
        // A migration seeds the onboarding templates and their versions, so
        // neither table is empty.
        $templates = Template::count();
        $versions = TemplateVersion::count();

        SnapsbrewServer::actingAs($user)->tool(PreviewDesignTool::class, [
            'width' => 100,
            'height' => 100,
            'layers' => $this->layers(),
        ])->assertOk();

        $this->assertDatabaseCount('api_logs', 0);
        $this->assertDatabaseCount('snaps', 0);
        $this->assertSame($templates, Template::count());
        $this->assertSame($versions, TemplateVersion::count());
    }

    // ------------------------------------------------- create-snap-from-design

    private function design(array $extra = []): array
    {
        return $extra + [
            'width' => 320,
            'height' => 200,
            'layers' => $this->layers('Rendered'),
        ];
    }

    public function test_create_snap_from_design_records_one_mcp_render()
    {
        Bus::fake();
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateSnapFromDesignTool::class, $this->design())
            ->assertOk()
            ->assertDontSee('credits_charged')
            ->assertSee('"replayed": false');

        Bus::assertDispatchedSync(ProcessSnap::class, 1);
        $this->assertSame(1, ApiLog::where('feature', UsageRecorder::FEATURE_MCP)->where('renders', 1)->count());
        $this->assertSame(1, Snap::query()->whereNull('template_id')->count());
    }

    public function test_create_snap_from_design_returns_a_null_template_and_says_why()
    {
        $user = $this->makeUser();

        $response = SnapsbrewServer::actingAs($user)->tool(CreateSnapFromDesignTool::class, $this->design())
            ->assertOk()
            ->assertSee('"template_id": null')
            ->assertSee('"template_title": null')
            ->assertSee('"width": 320')
            ->assertSee('"height": 200')
            ->assertSee('"status": "completed"')
            ->assertSee('No template was saved');

        $snap = Snap::query()->first();
        $response->assertSee($snap->uuid);
    }

    public function test_create_snap_from_design_replays_an_idempotency_key_without_a_second_render()
    {
        $user = $this->makeUser();
        $arguments = $this->design(['idempotency_key' => 'design-retry-1']);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapFromDesignTool::class, $arguments)
            ->assertOk()->assertSee('"replayed": false');

        SnapsbrewServer::actingAs($user)->tool(CreateSnapFromDesignTool::class, $arguments)
            ->assertOk()
            ->assertSee('"replayed": true')
            ->assertSee('This snap already existed for that idempotency_key');

        $this->assertSame(1, Snap::query()->count());
        $this->assertSame(1, TemplateVersion::query()->whereNull('template_id')->count());
        $this->assertSame(1, ApiLog::where('feature', UsageRecorder::FEATURE_MCP)->count());
    }

    public function test_a_key_first_used_by_create_snap_replays_that_templated_snap_from_the_design_tool()
    {
        $user = $this->makeUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'shared-key-1',
        ])->assertOk()->assertSee('"replayed": false');

        // All snap-creation tools share one key namespace. The design tool
        // returns the templated snap unchanged and records no render.
        SnapsbrewServer::actingAs($user)
            ->tool(CreateSnapFromDesignTool::class, $this->design(['idempotency_key' => 'shared-key-1']))
            ->assertOk()
            ->assertSee('"replayed": true')
            ->assertSee('"template_id": "'.$template->uuid.'"');

        $this->assertSame(1, Snap::query()->count());
        $this->assertSame(0, TemplateVersion::query()->whereNull('template_id')->count());
        $this->assertSame(1, ApiLog::where('feature', UsageRecorder::FEATURE_MCP)->count());
    }

    public function test_create_snap_from_design_with_a_claim_in_progress_creates_no_version_and_no_snap()
    {
        $user = $this->makeUser();
        $versions = TemplateVersion::count();

        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'in-flight',
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        ]);

        $response = SnapsbrewServer::actingAs($user)
            ->tool(CreateSnapFromDesignTool::class, $this->design(['idempotency_key' => 'in-flight']))
            ->assertHasErrors();

        $this->assertSame('idempotency_key_in_progress', $this->errorFrom($response)['code']);
        $this->assertSame(0, Snap::query()->count());
        $this->assertSame($versions, TemplateVersion::count());
        $this->assertSame(0, ApiLog::query()->count());
    }

    public function test_create_snap_from_design_rejects_jpeg_with_transparency()
    {
        Bus::fake();
        $user = $this->makeUser();

        $response = SnapsbrewServer::actingAs($user)
            ->tool(CreateSnapFromDesignTool::class, $this->design(['format' => 'jpeg', 'transparent' => true]))
            ->assertHasErrors();

        $this->assertSame('transparency_not_supported', $this->errorFrom($response)['code']);
        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_create_snap_from_design_rejects_an_internal_background_image()
    {
        Bus::fake();
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateSnapFromDesignTool::class, $this->design([
            'layers' => [['key' => 'bg', 'properties' => [
                ['name' => 'backgroundImage', 'value' => 'http://10.0.0.1/x.png'],
            ]]],
        ]))->assertHasErrors()->assertSee('internal, reserved, or unsafe address');

        $this->assertDatabaseCount('snaps', 0);
        $this->assertDatabaseCount('api_logs', 0);
    }

    public function test_create_snap_from_design_requires_a_canvas()
    {
        Bus::fake();
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateSnapFromDesignTool::class, [
            'layers' => $this->layers(),
        ])->assertHasErrors()->assertSee('Pass a `size` preset or an explicit `width` and `height`.');

        $this->assertDatabaseCount('snaps', 0);
    }
}
