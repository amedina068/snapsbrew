<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Tag;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class TemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    /**
     * A resize regenerates the preview image, which drives the renderer inside
     * the request. Bind the hermetic renderer so no test launches Chrome, and
     * keep the instance so a test can count the renders it asked for.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
    }

    private function createAuthenticatedUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_guests_cannot_access_templates()
    {
        $response = $this->get('/templates');

        $response->assertRedirect('/login');
    }

    public function test_index_shows_company_templates()
    {
        $user = $this->createAuthenticatedUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'My Template',
        ]);

        // Template from another company — should not appear
        Template::factory()->create(['title' => 'Other Company Template']);

        $response = $this->get('/templates');

        $response->assertStatus(200);
        $response->assertSee('My Template');
        $response->assertDontSee('Other Company Template');
    }

    public function test_index_filters_by_size()
    {
        $user = $this->createAuthenticatedUser();

        Template::factory()->instagram()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Instagram Template',
        ]);
        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Other Template',
            'size' => 'twitter_card',
        ]);

        $response = $this->get('/templates?size=instagram_square_post');

        $response->assertStatus(200);
        $response->assertSee('Instagram Template');
        $response->assertDontSee('Other Template');
    }

    public function test_index_filters_by_search_query()
    {
        $user = $this->createAuthenticatedUser();

        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Marketing Banner',
        ]);
        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Product Card',
        ]);

        $response = $this->get('/templates?q=Marketing');

        $response->assertStatus(200);
        $response->assertSee('Marketing Banner');
        $response->assertDontSee('Product Card');
    }

    public function test_index_filters_by_tag()
    {
        $user = $this->createAuthenticatedUser();

        $tag = Tag::factory()->create([
            'company_id' => $user->company_id,
            'name' => 'Social',
            'slug' => 'social',
        ]);

        $taggedTemplate = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Tagged Template',
        ]);
        $taggedTemplate->tags()->attach($tag);

        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Untagged Template',
        ]);

        $response = $this->get('/templates?tags[]=social');

        $response->assertStatus(200);
        $response->assertSee('Tagged Template');
        $response->assertDontSee('Untagged Template');
    }

    public function test_show_displays_template()
    {
        $user = $this->createAuthenticatedUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Test Template',
        ]);

        $response = $this->get("/templates/{$template->uuid}");

        $response->assertStatus(200);
        $response->assertSee('Test Template');
    }

    public function test_show_returns_404_for_other_company_template()
    {
        $user = $this->createAuthenticatedUser();
        $otherTemplate = Template::factory()->create(['title' => 'Other']);

        $response = $this->get("/templates/{$otherTemplate->uuid}");

        $response->assertStatus(404);
    }

    public function test_create_shows_public_templates()
    {
        $user = $this->createAuthenticatedUser();

        Template::factory()->public()->create(['title' => 'Public Preset']);

        $response = $this->get('/templates/new');

        $response->assertStatus(200);
    }

    public function test_edit_shows_template_form()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->get("/templates/{$template->uuid}/edit");

        $response->assertStatus(200);
    }

    public function test_update_template()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->patch("/templates/{$template->uuid}", [
            'title' => 'Updated Title',
            'size' => 'custom',
            'width' => 800,
            'height' => 600,
        ]);

        $response->assertRedirect("/templates/{$template->uuid}");

        $template->refresh();
        $this->assertEquals('Updated Title', $template->title);
    }

    public function test_update_with_a_preset_sets_the_dimensions_and_stores_the_preset()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 800,
            'height' => 600,
            'size' => 'custom',
        ]);

        $this->patch("/templates/{$template->uuid}", [
            'title' => 'Story',
            'size' => 'instagram_story',
        ])->assertRedirect("/templates/{$template->uuid}");

        $template->refresh();
        $this->assertEquals(1080, $template->width);
        $this->assertEquals(1920, $template->height);
        $this->assertEquals('instagram_story', $template->size);
    }

    public function test_update_with_custom_dimensions_stores_the_preset_they_match()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 800,
            'height' => 600,
            'size' => 'custom',
        ]);

        $this->patch("/templates/{$template->uuid}", [
            'title' => 'Thumbnail',
            'size' => 'custom',
            'width' => 1280,
            'height' => 720,
        ])->assertRedirect("/templates/{$template->uuid}");

        $this->assertEquals('youtube_thumbnail', $template->refresh()->size);
    }

    public function test_update_with_custom_keeps_the_preset_the_template_already_names()
    {
        // The edit form always sends a `size`. "Custom" is the value that makes
        // `TemplateSizes::find()` return null, and the controller then resolved
        // the preset itself. 1080x1920 is seven presets, so that resolve moved
        // the template off the preset it already names.
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 1080,
            'height' => 1920,
            'size' => 'tiktok_vertical',
        ]);

        $this->patch("/templates/{$template->uuid}", [
            'title' => 'TikTok cover',
            'size' => 'custom',
            'width' => 1080,
            'height' => 1920,
        ])->assertRedirect("/templates/{$template->uuid}");

        $this->assertEquals('tiktok_vertical', $template->refresh()->size);
    }

    public function test_update_rejects_an_unknown_preset()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->patch("/templates/{$template->uuid}", [
            'title' => 'Nope',
            'size' => 'myspace_banner',
        ])->assertSessionHasErrors('size');
    }

    public function test_update_requires_title()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->patch("/templates/{$template->uuid}", [
            'size' => 'custom',
            'width' => 800,
            'height' => 600,
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_a_resize_regenerates_the_preview_image()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 800,
            'height' => 600,
            'size' => 'custom',
        ]);

        $this->patch("/templates/{$template->uuid}", [
            'title' => 'Thumbnail',
            'size' => 'custom',
            'width' => 1280,
            'height' => 720,
        ])->assertRedirect("/templates/{$template->uuid}");

        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertNotNull($template->fresh()->getFirstMedia('preview'));
    }

    public function test_a_save_that_changes_no_dimension_does_not_regenerate_the_preview_image()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Stored title',
            'width' => 800,
            'height' => 600,
            'size' => 'custom',
        ]);
        $template->generatePreviewImage();
        $preview = $template->getFirstMedia('preview')->id;
        $this->renderer->screenshotCalls = [];

        $this->patch("/templates/{$template->uuid}", [
            'title' => 'Renamed only',
            'size' => 'custom',
            'width' => 800,
            'height' => 600,
        ])->assertRedirect("/templates/{$template->uuid}");

        $this->assertSame([], $this->renderer->screenshotCalls);
        $this->assertSame($preview, $template->fresh()->getFirstMedia('preview')->id);
        $this->assertSame('Renamed only', $template->fresh()->title);
    }

    public function test_store_duplicates_public_template()
    {
        $user = $this->createAuthenticatedUser();
        $publicTemplate = Template::factory()->public()->create();

        $response = $this->post('/templates', [
            'template_id' => $publicTemplate->id,
        ]);

        // Should create a new template for the user
        $this->assertDatabaseHas('templates', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
    }

    public function test_edit_and_update_of_an_unknown_template_return_404()
    {
        $this->createAuthenticatedUser();
        $other = Template::factory()->create();

        $this->get("/templates/{$other->uuid}/edit")->assertNotFound();
        $this->patch("/templates/{$other->uuid}", ['title' => 'X', 'size' => 'custom', 'width' => 10, 'height' => 10])
            ->assertNotFound();
    }

    public function test_the_edit_page_shows_why_a_save_failed()
    {
        $user = $this->createAuthenticatedUser();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->from("/templates/{$template->uuid}/edit")
            ->patch("/templates/{$template->uuid}", ['title' => '', 'size' => 'custom', 'width' => 0, 'height' => 10])
            ->assertRedirect("/templates/{$template->uuid}/edit");

        $this->get("/templates/{$template->uuid}/edit")
            ->assertSee('The title field is required.')
            ->assertSee('The width must be at least 1.')
            ->assertSee('aria-describedby="title-error"', false);
    }

    public function test_an_empty_filtered_list_offers_to_clear_the_filters()
    {
        $this->createAuthenticatedUser();

        $this->get('/templates?q=nothing-matches')
            ->assertSee('No templates match the current filters.')
            ->assertSee('Clear the filters')
            ->assertDontSee('Create your first template');
    }

    public function test_the_starting_template_gallery_says_when_no_template_has_the_size()
    {
        $this->createAuthenticatedUser();

        $this->get('/templates/new?size=linkedin_banner')
            ->assertOk()
            ->assertSee('No starting template has this size.');
    }
}
