<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Jobs\GenerateTemplateVersionPreview;
use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\SnapService;
use App\Services\TemplateVersioning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class TemplateVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Publish/revert now render a version preview image eagerly — keep every
        // test in this file hermetic (no real Chrome, no real disk).
        Storage::fake('public');
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    private function actingUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    private function template(User $user, string $layers = '[{"key":"a","properties":[]}]'): Template
    {
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'layers' => $layers,
        ]);
    }

    public function test_publish_creates_published_version_and_sets_fk()
    {
        $user = $this->actingUser();
        $template = $this->template($user);

        $response = $this->post("/templates/{$template->id}/publish");

        $response->assertOk();
        $template->refresh();
        $this->assertNotNull($template->published_version_id);
        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id,
            'version_number' => 1,
            'published' => true,
        ]);
    }

    public function test_publish_normalizes_v2_image_sources_and_projects_tenant_assets()
    {
        $user = $this->actingUser();
        $template = $this->template($user);
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $dataUri = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        imagedestroy($image);

        $template->forceFill([
            'editor_document' => [
                'version' => 2,
                'layers' => [[
                    'id' => 'hero',
                    'type' => 'image',
                    'source' => ['url' => $dataUri],
                ]],
            ],
            'editor_schema_version' => 2,
            'editor_revision' => 1,
        ])->save();

        $this->post("/templates/{$template->id}/publish")->assertOk();

        $version = TemplateVersion::where('template_id', $template->id)->firstOrFail();
        $source = $version->editor_document['layers'][0]['source'];
        $this->assertArrayNotHasKey('url', $source);
        $this->assertSame(1, $version->editorAssets()->count());
        $this->assertSame($source['asset_id'], $version->editorAssets()->first()->uuid);
    }

    public function test_publish_reloads_the_template_before_allocating_the_next_version()
    {
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"v1","properties":[]}]');
        $versioning = app(TemplateVersioning::class);

        $versioning->publish($template);

        // Another writer changes the draft. `$template` still holds the state it
        // had before the first publish, and the service receives that stale
        // instance on purpose.
        $template->fresh()->forceFill(['layers' => '[{"key":"v2","properties":[]}]'])->save();

        // The service must re-read and lock the row inside its transaction
        // before it calculates MAX + 1, and must snapshot the locked row.
        $versioning->publish($template);

        $this->assertSame([1, 2], TemplateVersion::where('template_id', $template->id)
            ->orderBy('version_number')
            ->pluck('version_number')
            ->all());
        $this->assertStringContainsString(
            'v2',
            (string) TemplateVersion::where('template_id', $template->id)
                ->where('version_number', 2)
                ->value('layers')
        );
    }

    public function test_publishing_an_unchanged_draft_twice_creates_one_version()
    {
        Bus::fake();
        $user = $this->actingUser();
        $template = $this->template($user);
        $versioning = app(TemplateVersioning::class);

        $first = $versioning->publish($template);
        $second = $versioning->publish($template->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TemplateVersion::where('template_id', $template->id)->count());
        Bus::assertDispatchedTimes(GenerateTemplateVersionPreview::class, 1);
    }

    public function test_publishing_a_changed_draft_still_creates_a_new_version()
    {
        Bus::fake();
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"v1","properties":[]}]');
        $versioning = app(TemplateVersioning::class);

        $versioning->publish($template);
        $template->fresh()->forceFill(['layers' => '[{"key":"v2","properties":[]}]'])->save();
        $second = $versioning->publish($template->fresh());

        $this->assertSame(2, $second->version_number);
        $this->assertSame(2, TemplateVersion::where('template_id', $template->id)->count());
        Bus::assertDispatchedTimes(GenerateTemplateVersionPreview::class, 2);
    }

    public function test_a_resized_canvas_alone_still_creates_a_new_version()
    {
        // The render path reads width and height from the published version, so
        // a size change with the same layers is an unpublished edit.
        Bus::fake();
        $user = $this->actingUser();
        $template = $this->template($user);
        $versioning = app(TemplateVersioning::class);

        $versioning->publish($template);
        $template->fresh()->forceFill(['width' => '640', 'height' => '480'])->save();
        $second = $versioning->publish($template->fresh());

        $this->assertSame(2, $second->version_number);
        $this->assertSame(640, (int) $second->width);
    }

    public function test_an_editor_document_change_the_projection_hides_still_creates_a_new_version()
    {
        // The projector copies `layers` and `groups` only, so a change outside
        // them leaves the draft layers identical to the published layers.
        Bus::fake();
        $user = $this->actingUser();
        $template = $this->template($user);
        $versioning = app(TemplateVersioning::class);

        $versioning->publish($template);
        $template->fresh()->forceFill([
            'editor_document' => ['version' => 2, 'layers' => [], 'canvas' => ['background' => '#fff']],
            'editor_schema_version' => 2,
        ])->save();
        $second = $versioning->publish($template->fresh());

        $this->assertSame(2, $second->version_number);
    }

    public function test_version_number_is_unique_per_template()
    {
        $user = $this->actingUser();
        $template = $this->template($user);

        TemplateVersion::factory()->create([
            'template_id' => $template->id,
            'version_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        TemplateVersion::factory()->create([
            'template_id' => $template->id,
            'version_number' => 1,
        ]);
    }

    public function test_editor_document_backfill_is_resumable_and_idempotent()
    {
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"legacy","properties":[]}]');
        $template->forceFill(['groups' => '[{"id":"group-1"}]'])->save();

        $this->artisan('editor:backfill-template-documents', [
            '--table' => 'templates',
            '--after-id' => $template->id - 1,
            '--limit' => 1,
        ])->expectsOutputToContain('Backfilled 1 templates record(s).')->assertSuccessful();

        $template->refresh();
        $this->assertSame(2, $template->editor_schema_version);
        $this->assertSame(1, $template->editor_revision);
        $this->assertSame('legacy', $template->editor_document['layers'][0]['key']);
        $this->assertSame('group-1', $template->editor_document['groups'][0]['id']);

        $this->artisan('editor:backfill-template-documents', [
            '--table' => 'templates',
            '--after-id' => $template->id - 1,
            '--limit' => 1,
        ])->expectsOutputToContain('Backfilled 0 templates record(s).')->assertSuccessful();
    }

    public function test_snapshot_schema_migration_is_idempotent_after_online_ddl()
    {
        // FreshDatabase has already applied the migration. A production DBA can
        // apply its exact online DDL first, after which Artisan must be able to
        // run the migration solely to record it without duplicate-object errors.
        $migration = require database_path('migrations/2026_09_15_000000_add_editor_document_snapshot_integrity.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('templates', [
            'editor_document',
            'editor_schema_version',
            'editor_revision',
        ]));
    }

    public function test_renderable_layers_returns_published_not_draft()
    {
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"published","properties":[]}]');

        $this->post("/templates/{$template->id}/publish");

        // Edit the draft after publishing.
        $template->refresh()->forceFill(['layers' => '[{"key":"draft","properties":[]}]'])->save();

        $this->assertStringContainsString('published', $template->fresh()->renderableLayers());
        $this->assertStringNotContainsString('draft', $template->fresh()->renderableLayers());
    }

    public function test_renderable_layers_falls_back_to_draft_when_never_published()
    {
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"draftonly","properties":[]}]');

        $this->assertStringContainsString('draftonly', $template->renderableLayers());
    }

    public function test_revert_is_non_destructive_and_restores_layers()
    {
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"v1","properties":[]}]');
        $this->post("/templates/{$template->id}/publish");
        $v1 = TemplateVersion::where('template_id', $template->id)->first();

        // Change the draft, then revert to v1.
        $template->refresh()->forceFill(['layers' => '[{"key":"v2draft","properties":[]}]'])->save();

        $response = $this->post("/templates/{$template->id}/versions/{$v1->id}/revert");

        $response->assertOk();
        // Draft restored to v1 content.
        $this->assertStringContainsString('v1', $template->fresh()->layers);
        // Non-destructive: the v2 draft was snapshotted to history before reverting.
        $this->assertTrue(
            TemplateVersion::where('template_id', $template->id)->get()
                ->contains(fn ($version) => str_contains((string) $version->layers, 'v2draft'))
        );
    }

    public function test_backfill_migration_creates_v1_for_existing_template()
    {
        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"existing","properties":[]}]');
        // Simulate a pre-versioning template: no version, no FK.
        TemplateVersion::where('template_id', $template->id)->delete();
        $template->forceFill(['published_version_id' => null])->save();

        // Run the data backfill migration directly against the real driver.
        $migration = require database_path('migrations/2026_06_23_000400_backfill_template_versions.php');
        $migration->up();

        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id,
            'version_number' => 1,
            'published' => true,
        ]);
        $this->assertNotNull($template->fresh()->published_version_id);
        $this->assertStringContainsString('existing', $template->fresh()->renderableLayers());
    }

    public function test_snap_render_uses_published_layers_not_draft()
    {
        // T-707: prove the contract at the render boundary, not just the model.
        Storage::fake('public');
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $user = $this->actingUser();
        $template = $this->template($user, '[{"key":"k","type":"text","properties":[{"name":"textContent","value":"PUBLISHED"}]}]');
        $this->post("/templates/{$template->id}/publish");

        // Edit the draft AFTER publishing.
        $template->fresh()->forceFill([
            'layers' => '[{"key":"k","type":"text","properties":[{"name":"textContent","value":"DRAFTEDIT"}]}]',
        ])->save();

        $snap = app(SnapService::class)->create(
            $template->fresh(), [], 'image', false, null, $user->id, $user->company_id
        );

        $content = $snap->fresh()->content;
        $this->assertStringContainsString('PUBLISHED', $content);
        $this->assertStringNotContainsString('DRAFTEDIT', $content);
    }

    public function test_queued_snap_renders_its_dispatch_snapshot_after_the_template_changes()
    {
        Bus::fake();
        $fake = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $fake);

        $user = $this->actingUser();
        $template = $this->template(
            $user,
            '[{"key":"k","type":"text","properties":[{"name":"textContent","value":"QUEUED"}]}]'
        );
        $template->forceFill(['width' => 640, 'height' => 360])->save();

        $snap = app(SnapService::class)->create(
            $template, [], 'image', true, null, $user->id, $user->company_id
        );

        $this->assertNotNull($snap->source_template_version_id);
        $this->assertDatabaseHas('template_versions', [
            'id' => $snap->source_template_version_id,
            'template_id' => $template->id,
        ]);

        // This happens after queue dispatch, before the worker starts.
        $template->forceFill([
            'layers' => '[{"key":"k","type":"text","properties":[{"name":"textContent","value":"MUTATED"}]}]',
            'width' => 1200,
            'height' => 628,
        ])->save();

        (new ProcessSnap($snap))->handle();

        $rendered = $snap->fresh()->content;
        $this->assertStringContainsString('QUEUED', $rendered);
        $this->assertStringNotContainsString('MUTATED', $rendered);
        $this->assertSame(640, $fake->screenshotCalls[0]['width']);
        $this->assertSame(360, $fake->screenshotCalls[0]['height']);
    }

    public function test_cannot_publish_another_companys_template()
    {
        $this->actingUser();
        $otherTemplate = Template::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $response = $this->post("/templates/{$otherTemplate->id}/publish");

        $response->assertNotFound();
        $this->assertDatabaseMissing('template_versions', ['template_id' => $otherTemplate->id]);
        $this->assertNull($otherTemplate->fresh()->published_version_id);
    }

    public function test_cannot_revert_to_a_version_from_another_template()
    {
        $user = $this->actingUser();
        $mine = $this->template($user);
        $other = $this->template($user);
        $this->post("/templates/{$other->id}/publish");
        $otherVersion = TemplateVersion::where('template_id', $other->id)->first();

        // Try to revert MY template to ANOTHER template's version id.
        $response = $this->post("/templates/{$mine->id}/versions/{$otherVersion->id}/revert");

        $response->assertNotFound();
    }

    public function test_publish_queues_preview_generation_off_the_request()
    {
        Bus::fake();

        $user = $this->actingUser();
        $template = $this->template($user);

        $this->post("/templates/{$template->id}/publish")->assertOk();

        // Publish must not render inline — the slow work is queued.
        Bus::assertDispatched(GenerateTemplateVersionPreview::class);
    }

    public function test_revert_queues_preview_for_the_new_snapshot()
    {
        $user = $this->actingUser();
        $template = $this->template($user);
        $this->post("/templates/{$template->id}/publish");
        $v1 = TemplateVersion::where('template_id', $template->id)->first();

        Bus::fake();
        $template->fresh()->forceFill(['layers' => '[{"key":"e","properties":[]}]'])->save();
        $this->post("/templates/{$template->id}/versions/{$v1->id}/revert")->assertOk();

        Bus::assertDispatched(GenerateTemplateVersionPreview::class);
    }

    public function test_only_imageless_versions_are_selected_for_preview_backfill()
    {
        // The backfill migration queues a render for exactly this set — versions
        // with no stored preview image yet. (The migration body is skipped under
        // tests, where the sync queue would render inline; the selection query is
        // the part worth asserting.)
        $user = $this->actingUser();

        $withImage = $this->template($user);
        $this->post("/templates/{$withImage->id}/publish");
        $imagedVersion = TemplateVersion::where('template_id', $withImage->id)->first();

        $legacy = $this->template($user);
        $this->post("/templates/{$legacy->id}/publish");
        $legacyVersion = TemplateVersion::where('template_id', $legacy->id)->first();
        $legacyVersion->clearMediaCollection('preview');

        $selected = TemplateVersion::query()
            ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'preview'))
            ->pluck('id');

        $this->assertTrue($selected->contains($legacyVersion->id));
        $this->assertFalse($selected->contains($imagedVersion->id));
    }

    public function test_preview_job_is_idempotent_when_image_already_exists()
    {
        $fake = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $fake);

        $user = $this->actingUser();
        $template = $this->template($user);
        $this->post("/templates/{$template->id}/publish"); // sync job renders once
        $version = TemplateVersion::where('template_id', $template->id)->first();

        // Re-running the job must not render again.
        (new GenerateTemplateVersionPreview($version))->handle();

        $this->assertCount(1, $fake->screenshotCalls);
    }

    public function test_publish_generates_a_version_preview_image()
    {
        $fake = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $fake);

        $user = $this->actingUser();
        $template = $this->template($user);

        $this->post("/templates/{$template->id}/publish");

        $version = TemplateVersion::where('template_id', $template->id)->first();
        $this->assertTrue($version->hasMedia('preview'));
        // Rendered exactly once, at publish — not on every later view.
        $this->assertCount(1, $fake->screenshotCalls);
        // The throwaway render snap is cleaned up; a preview never bills.
        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_revert_snapshot_gets_its_own_preview_image()
    {
        $user = $this->actingUser();
        $template = $this->template($user);
        $this->post("/templates/{$template->id}/publish");
        $v1 = TemplateVersion::where('template_id', $template->id)->first();

        // Edit the draft, then revert — this captures the current draft as a new
        // snapshot version, which must get its own image.
        $template->fresh()->forceFill(['layers' => '[{"key":"edited","properties":[]}]'])->save();
        $this->post("/templates/{$template->id}/versions/{$v1->id}/revert");

        $snapshot = TemplateVersion::where('template_id', $template->id)
            ->where('id', '!=', $v1->id)->first();
        $this->assertTrue($snapshot->hasMedia('preview'));
    }

    public function test_index_returns_stored_preview_image()
    {
        $user = $this->actingUser();
        $template = $this->template($user);
        $this->post("/templates/{$template->id}/publish");

        $data = $this->get("/templates/{$template->id}/versions")->json('data');

        $this->assertNotNull($data[0]['preview_image']);
    }

    public function test_index_uses_a_bounded_version_cursor_for_the_owned_template()
    {
        $user = $this->actingUser();
        $template = $this->template($user);

        foreach (range(1, 52) as $number) {
            TemplateVersion::factory()->create([
                'template_id' => $template->id,
                'version_number' => $number,
            ]);
        }

        $firstPage = $this->getJson("/templates/{$template->id}/versions");

        $firstPage->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.version_number', 52)
            ->assertJsonPath('data.49.version_number', 3)
            ->assertJsonPath('next_cursor', 3);

        $secondPage = $this->getJson("/templates/{$template->id}/versions?cursor=3");

        $secondPage->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version_number', 2)
            ->assertJsonPath('data.1.version_number', 1)
            ->assertJsonPath('next_cursor', null);
    }

    public function test_snap_source_version_must_belong_to_the_same_template()
    {
        $user = $this->actingUser();
        $template = $this->template($user);
        $otherTemplate = $this->template($user);
        $source = TemplateVersion::factory()->create(['template_id' => $otherTemplate->id]);

        $this->expectException(QueryException::class);

        Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'source_template_version_id' => $source->id,
        ]);
    }

    public function test_preview_endpoint_serves_stored_image_without_rerendering()
    {
        $fake = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $fake);

        $user = $this->actingUser();
        $template = $this->template($user);
        $this->post("/templates/{$template->id}/publish"); // renders once (eager)
        $version = TemplateVersion::where('template_id', $template->id)->first();

        $response = $this->get("/templates/{$template->id}/versions/{$version->id}/preview");

        $response->assertOk();
        $this->assertNotEmpty($response->json('image'));
        $this->assertCount(1, $fake->screenshotCalls); // endpoint did NOT re-render
    }

    public function test_preview_endpoint_lazily_generates_for_a_legacy_version()
    {
        $user = $this->actingUser();
        $template = $this->template($user);
        $this->post("/templates/{$template->id}/publish");
        $version = TemplateVersion::where('template_id', $template->id)->first();

        // Simulate a version created before previews existed (e.g. backfilled v1).
        $version->clearMediaCollection('preview');
        $this->assertFalse($version->fresh()->hasMedia('preview'));

        $this->get("/templates/{$template->id}/versions/{$version->id}/preview")->assertOk();

        $this->assertTrue($version->fresh()->hasMedia('preview'));
    }

    public function test_cannot_preview_a_version_from_another_template()
    {
        $user = $this->actingUser();
        $mine = $this->template($user);
        $other = $this->template($user);
        $this->post("/templates/{$other->id}/publish");
        $otherVersion = TemplateVersion::where('template_id', $other->id)->first();

        $response = $this->get("/templates/{$mine->id}/versions/{$otherVersion->id}/preview");

        $response->assertNotFound();
    }
}
