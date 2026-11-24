<?php

namespace Tests\Unit\Models;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Tag;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_belongs_to_user()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertInstanceOf(User::class, $template->user);
        $this->assertEquals($user->id, $template->user->id);
    }

    public function test_template_belongs_to_company()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(Company::class, $template->company);
    }

    public function test_template_has_many_snaps()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        Snap::factory()->count(3)->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
        ]);

        $this->assertCount(3, $template->snaps);
    }

    public function test_template_has_many_imports()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        SnapImport::factory()->count(2)->create([
            'template_id' => $template->id,
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertCount(2, $template->imports);
    }

    public function test_template_has_uuid()
    {
        $template = Template::factory()->create();

        $this->assertNotNull($template->uuid);
    }

    public function test_scope_public()
    {
        // Baseline migrations seed onboarding templates as public rows, so
        // compare delta rather than absolute count.
        $baseline = Template::public()->count();

        $created = Template::factory()->public()->create();
        Template::factory()->create(); // user-owned

        $publicTemplates = Template::public()->get();

        $this->assertCount($baseline + 1, $publicTemplates);
        $this->assertTrue($publicTemplates->contains('id', $created->id));
        $this->assertTrue($publicTemplates->every(fn ($t) => $t->user_id === null));
    }

    public function test_scope_user()
    {
        $user = User::factory()->create();
        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        Template::factory()->create(); // different user

        Auth::login($user);

        $this->assertCount(1, Template::query()->user()->get());
    }

    public function test_scope_uuid()
    {
        $template = Template::factory()->create();

        $found = Template::uuid($template->uuid)->first();

        $this->assertNotNull($found);
        $this->assertEquals($template->id, $found->id);
    }

    public function test_readable_size_for_default_size()
    {
        $template = Template::factory()->instagram()->create();

        $this->assertEquals('Instagram Square Post (1080x1080)', $template->readable_size);
    }

    public function test_readable_size_for_custom_size()
    {
        $template = Template::factory()->custom(800, 600)->create();

        $this->assertEquals('Custom (800x600)', $template->readable_size);
    }

    public function test_is_owner_when_authenticated()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        Auth::login($user);

        $this->assertTrue($template->is_owner);
    }

    public function test_is_not_owner_when_guest()
    {
        $template = Template::factory()->create();

        $this->assertFalse($template->is_owner);
    }

    public function test_template_can_have_tags()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $tag = Tag::factory()->create(['company_id' => $user->company_id]);

        $template->tags()->attach($tag);

        $this->assertCount(1, $template->fresh()->tags);
        $this->assertEquals($tag->id, $template->tags->first()->id);
    }

    public function test_default_sizes_constant()
    {
        $this->assertNotEmpty(Template::DEFAULT_SIZES);
        $this->assertArrayHasKey('name', Template::DEFAULT_SIZES[0]);
        $this->assertArrayHasKey('value', Template::DEFAULT_SIZES[0]);
        $this->assertArrayHasKey('width', Template::DEFAULT_SIZES[0]);
        $this->assertArrayHasKey('height', Template::DEFAULT_SIZES[0]);
    }

    public function test_size_is_derived_from_the_dimensions_on_create()
    {
        $user = User::factory()->create();

        $template = Template::create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Derived',
            'width' => 1280,
            'height' => 720,
            'layers' => json_encode([]),
        ]);

        $this->assertEquals('youtube_thumbnail', $template->size);
    }

    public function test_size_falls_back_to_custom_when_no_preset_matches()
    {
        $user = User::factory()->create();

        $template = Template::create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Odd',
            'width' => 801,
            'height' => 601,
            'layers' => json_encode([]),
        ]);

        $this->assertEquals('custom', $template->size);
    }

    public function test_size_follows_a_resize()
    {
        $template = Template::factory()->custom(800, 600)->create();

        $template->update(['width' => 1080, 'height' => 1080]);

        $this->assertEquals('instagram_square_post', $template->refresh()->size);
    }

    public function test_an_explicit_size_is_kept()
    {
        $user = User::factory()->create();

        $template = Template::create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'TikTok',
            'width' => 1080,
            'height' => 1920,
            // 1080x1920 is a Story, a Reel and a TikTok video. The caller decides.
            'size' => 'tiktok_vertical',
            'layers' => json_encode([]),
        ]);

        $this->assertEquals('tiktok_vertical', $template->size);
    }

    public function test_a_resize_keeps_a_size_preset_the_row_already_names()
    {
        // 1200x630 is a Facebook Feed Link and an Open Graph image.
        // `facebook_feed` is listed first, so a resolve from the dimensions
        // moves the row off the preset the caller asked for.
        $template = Template::factory()->create([
            'width' => 100,
            'height' => 100,
            'size' => 'open_graph',
        ]);

        $template->update(['width' => 1200, 'height' => 630, 'size' => 'open_graph']);

        $this->assertEquals('open_graph', $template->refresh()->size);
    }

    public function test_a_resize_keeps_a_repeated_size_preset_of_a_vertical_video()
    {
        // 1080x1920 is seven presets. `instagram_story` is listed first.
        $template = Template::factory()->create([
            'width' => 100,
            'height' => 100,
            'size' => 'tiktok_vertical',
        ]);

        $template->update(['width' => 1080, 'height' => 1920, 'size' => 'tiktok_vertical']);

        $this->assertEquals('tiktok_vertical', $template->refresh()->size);
    }

    public function test_a_resize_with_no_size_resolves_the_preset_from_the_dimensions()
    {
        $template = Template::factory()->create([
            'width' => 600,
            'height' => 200,
            'size' => 'email_header',
        ]);

        $template->update(['width' => 1280, 'height' => 720]);

        $this->assertEquals('youtube_thumbnail', $template->refresh()->size);
    }

    public function test_a_resize_keeps_a_size_preset_that_differs_from_the_stored_one()
    {
        $template = Template::factory()->create([
            'width' => 1200,
            'height' => 630,
            'size' => 'open_graph',
        ]);

        $template->update(['width' => 1080, 'height' => 1920, 'size' => 'youtube_shorts']);

        $this->assertEquals('youtube_shorts', $template->refresh()->size);
    }

    public function test_a_resize_with_no_size_keeps_a_stored_preset_that_matches_the_new_dimensions()
    {
        // The row already names the preset. A save must never move a template
        // between two presets of one shape, with a size or without one.
        $template = Template::factory()->create([
            'width' => 100,
            'height' => 100,
            'size' => 'open_graph',
        ]);

        $template->update(['width' => 1200, 'height' => 630]);

        $this->assertEquals('open_graph', $template->refresh()->size);
    }

    public function test_a_save_with_neither_size_nor_dimensions_changed_leaves_size_untouched()
    {
        // The hook reads `isDirty(['width', 'height'])` so that an ordinary
        // save keeps the size. Without that guard a title rename or a layers
        // edit resolves the preset again from the dimensions.
        $template = Template::factory()->create([
            'width' => 100,
            'height' => 100,
            'size' => 'open_graph',
        ]);

        $template->update(['title' => 'Renamed']);

        $this->assertEquals('open_graph', $template->refresh()->size);
    }

    public function test_a_resize_of_a_row_with_no_size_resolves_the_preset()
    {
        // `templates.size` is nullable and rows predate the column. The hook
        // reads `TemplateSizes::find(null)`, which returns null, so the resize
        // must fall through to the resolve.
        $template = Template::factory()->create([
            'width' => 100,
            'height' => 100,
            'size' => null,
        ]);

        $template->update(['width' => 1280, 'height' => 720]);

        $this->assertEquals('youtube_thumbnail', $template->refresh()->size);
    }

    public function test_generate_preview_image_populates_preview_media_and_leaves_no_snap()
    {
        $this->app->bind(
            SnapRenderer::class,
            FakeRenderer::class
        );

        $template = Template::factory()->create(['width' => '100', 'height' => '100']);

        $template->generatePreviewImage();

        $this->assertNotNull($template->getFirstMedia('preview'), 'preview media should be populated');
        $this->assertNotEmpty($template->preview_image);
        // The transient render snap is discarded.
        $this->assertDatabaseCount('snaps', 0);
    }
}
