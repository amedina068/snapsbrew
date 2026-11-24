<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class TemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    public function test_can_list_templates()
    {
        [$user, $token] = $this->createApiUser();

        Template::factory()->count(3)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/templates');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function test_list_templates_only_shows_own_company()
    {
        [$user, $token] = $this->createApiUser();

        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        // Template from another company
        Template::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/templates');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_show_template_by_uuid()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Test Template',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}");

        $response->assertStatus(200);
        // `assertJsonFragment` searches every depth, so it passes on the flat
        // body and on the wrapped one. The path names the depth.
        $response->assertJsonPath('data.title', 'Test Template');
    }

    public function test_cannot_show_other_company_template()
    {
        [$user, $token] = $this->createApiUser();
        $otherTemplate = Template::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$otherTemplate->uuid}");

        $response->assertStatus(404);
    }

    public function test_can_delete_own_template()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$template->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_cannot_delete_other_company_template()
    {
        [$user, $token] = $this->createApiUser();
        $otherTemplate = Template::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$otherTemplate->id}");

        $response->assertStatus(404);
    }

    public function test_unauthenticated_cannot_list_templates()
    {
        $response = $this->getJson('/api/v1/templates');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_create_a_template()
    {
        $response = $this->postJson('/api/v1/templates', [
            'title' => 'New Template',
        ]);

        $response->assertStatus(401);
    }

    public function test_admin_can_create_a_template()
    {
        // Admins act as customers everywhere a customer can act (they are just
        // never charged), so template creation is allowed.
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $token = $admin->createToken('test', ['api_requests'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'New Template',
                'width' => 800,
                'height' => 600,
                'layers' => [],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', [
            'title' => 'New Template',
            'user_id' => $admin->id,
        ]);
    }

    public function test_can_list_size_presets()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/templates/sizes');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['size', 'name', 'platform', 'width', 'height', 'aspect_ratio', 'safe_margin_px']],
        ]);
        $response->assertJsonFragment(['size' => 'instagram_story', 'width' => 1080, 'height' => 1920]);
    }

    public function test_unauthenticated_cannot_list_size_presets()
    {
        $this->getJson('/api/v1/templates/sizes')->assertStatus(401);
    }

    public function test_create_a_template_with_a_size_preset()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Preset Template',
                'size' => 'open_graph',
                'layers' => [],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', [
            'title' => 'Preset Template',
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
        ]);
    }

    public function test_update_keeps_a_size_preset_the_template_already_names()
    {
        // 1200x630 is a Facebook Feed Link and an Open Graph image. A caller
        // that repeats the preset the row already names must keep it.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 100,
            'height' => 100,
            'size' => 'open_graph',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Open Graph card',
                'size' => 'open_graph',
                'layers' => [],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
        ]);
    }

    public function test_update_with_no_size_keeps_the_preset_the_template_already_names()
    {
        // The path with the most traffic. 1080x1920 is seven presets and
        // `instagram_story` is listed first. A caller that sends the dimensions
        // and no `size` must keep the preset the row already names.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 1080,
            'height' => 1920,
            'size' => 'tiktok_vertical',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'TikTok cover',
                'width' => 1080,
                'height' => 1920,
                'layers' => [],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'size' => 'tiktok_vertical',
            'width' => 1080,
            'height' => 1920,
        ]);
    }

    public function test_update_with_no_size_resolves_a_preset_the_new_dimensions_match()
    {
        // The other half of the rule. The row names a preset of a different
        // shape, so the resize must move the template to the new shape.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 600,
            'height' => 200,
            'size' => 'email_header',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Thumbnail',
                'width' => 1280,
                'height' => 720,
                'layers' => [],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'size' => 'youtube_thumbnail',
        ]);
    }

    public function test_create_with_no_size_resolves_a_preset_from_the_dimensions()
    {
        // A create has no stored preset to keep, so the model hook resolves it.
        [$user, $token] = $this->createApiUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Thumbnail',
                'width' => 1280,
                'height' => 720,
                'layers' => [],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('templates', [
            'title' => 'Thumbnail',
            'size' => 'youtube_thumbnail',
        ]);
    }

    public function test_create_a_template_rejects_an_unknown_size_preset()
    {
        [$user, $token] = $this->createApiUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Bad preset',
                'size' => 'myspace_banner',
                'layers' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_unauthenticated_cannot_update_a_template()
    {
        $template = Template::factory()->create();

        $response = $this->patchJson("/api/v1/templates/{$template->id}", [
            'title' => 'Updated',
        ]);

        $response->assertStatus(401);
    }

    public function test_cannot_update_other_company_template()
    {
        [$user, $token] = $this->createApiUser();
        $otherTemplate = Template::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$otherTemplate->id}", [
                'title' => 'Hijacked',
                'width' => 800,
                'height' => 600,
                'layers' => [],
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('templates', [
            'id' => $otherTemplate->id,
            'title' => 'Hijacked',
        ]);
    }

    public function test_non_owner_company_member_can_update_template()
    {
        // Templates are company-shared: any customer in the owning company may
        // edit, not only the original creator.
        [$owner, $ownerToken] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $owner->id,
            'company_id' => $owner->company_id,
            'title' => 'Owned',
        ]);

        // A second customer in the SAME company who is not the template owner.
        $otherMember = User::factory()->create(['company_id' => $owner->company_id]);
        $otherToken = $otherMember->createToken('test', ['api_requests'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Edited by teammate',
                'width' => 800,
                'height' => 600,
                'layers' => [],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'title' => 'Edited by teammate',
        ]);
    }

    public function test_admin_in_company_can_update_template()
    {
        // Admins may edit templates within their own company.
        [$owner, $ownerToken] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $owner->id,
            'company_id' => $owner->company_id,
            'title' => 'Owned',
        ]);

        $admin = User::factory()->admin()->create(['company_id' => $owner->company_id]);
        $adminToken = $admin->createToken('test', ['api_requests'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Edited by admin',
                'width' => 800,
                'height' => 600,
                'layers' => [],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'title' => 'Edited by admin',
        ]);
    }

    public function test_create_template_rejects_oversized_dimensions()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Huge',
                'width' => 99999,
                'height' => 99999,
                'layers' => [],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('templates', ['title' => 'Huge']);
    }

    public function test_create_template_rejects_internal_background_image_in_layers()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Sneaky',
                'width' => 800,
                'height' => 600,
                'layers' => [[
                    'key' => 'bg',
                    'type' => 'image',
                    'properties' => [
                        ['name' => 'backgroundImage', 'value' => 'http://169.254.169.254/latest/meta-data/'],
                    ],
                ]],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('templates', ['title' => 'Sneaky']);
    }

    public function test_create_template_rejects_file_scheme_background_image()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'FileScheme',
                'width' => 800,
                'height' => 600,
                'layers' => [[
                    'key' => 'bg',
                    'type' => 'image',
                    'properties' => [
                        ['name' => 'backgroundImage', 'value' => 'file:///etc/passwd'],
                    ],
                ]],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('templates', ['title' => 'FileScheme']);
    }

    public function test_create_template_rejects_layer_without_key()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Keyless',
                'width' => 800,
                'height' => 600,
                'layers' => [[
                    'properties' => [
                        ['name' => 'textContent', 'value' => 'Hi'],
                    ],
                ]],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('layers');
        $this->assertDatabaseMissing('templates', ['title' => 'Keyless']);
    }

    public function test_create_template_rejects_property_without_name()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'BadProp',
                'width' => 800,
                'height' => 600,
                'layers' => [[
                    'key' => 'headline',
                    'properties' => [
                        ['value' => 'Hi'],
                    ],
                ]],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('layers');
        $this->assertDatabaseMissing('templates', ['title' => 'BadProp']);
    }

    public function test_create_template_rejects_duplicate_layer_keys()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Dupe',
                'width' => 800,
                'height' => 600,
                'layers' => [
                    ['key' => 'headline', 'properties' => []],
                    ['key' => 'headline', 'properties' => []],
                ],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('layers');
        $this->assertDatabaseMissing('templates', ['title' => 'Dupe']);
    }

    public function test_create_template_accepts_well_formed_layers()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'ValidApi',
                'width' => 800,
                'height' => 600,
                'layers' => [
                    ['key' => 'headline', 'properties' => [
                        ['name' => 'textContent', 'value' => 'Hi'],
                    ]],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('templates', ['title' => 'ValidApi']);
    }

    public function test_create_template_persists_groups()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Grouped',
                'width' => 800,
                'height' => 600,
                'layers' => [['key' => 'a', 'properties' => []]],
                'groups' => [['id' => 'g1', 'name' => 'Group A', 'visible' => true, 'order' => 0]],
            ]);

        $response->assertStatus(200);
        $template = Template::where('title', 'Grouped')->firstOrFail();
        $this->assertSame('g1', json_decode($template->groups, true)[0]['id']);
    }

    public function test_create_template_rejects_group_without_id()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'BadGroups',
                'width' => 800,
                'height' => 600,
                'layers' => [['key' => 'a', 'properties' => []]],
                'groups' => [['name' => 'no id', 'visible' => true]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('groups.0.id');
    }

    /**
     * A create with no `size` and no dimensions must answer 422.
     *
     * The old create rules made `width` and `height` nullable, which is right
     * for a PATCH and wrong for a POST. A create has no stored value to fall
     * back on, so an absent dimension reached the NOT NULL column. A body of
     * only a title answered 500 with a database constraint violation.
     */
    public function test_create_rejects_a_body_with_no_size_and_no_dimensions()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', ['title' => 'No canvas']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['width', 'height']);
        $this->assertStringContainsString(
            'Send a `size` preset or an explicit `width` and `height`.',
            $response->json('errors.width.0')
        );
        $this->assertDatabaseMissing('templates', ['title' => 'No canvas']);
    }

    /**
     * A create that sends one dimension must answer 422 for the other one.
     *
     * `required_without:size` reads each dimension on its own, so a body with
     * a width and no height is rejected on the height alone.
     */
    public function test_create_rejects_a_body_with_a_width_and_no_height()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', ['title' => 'Half a canvas', 'width' => 800]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('height');
        $this->assertDatabaseMissing('templates', ['title' => 'Half a canvas']);
    }

    /**
     * A create with a `size` preset and no dimensions must pass.
     *
     * The preset supplies the canvas, so `required_without:size` must not ask
     * the caller for a width that the server already knows.
     */
    public function test_create_accepts_a_size_preset_with_no_dimensions()
    {
        [$user, $token] = $this->createApiUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', ['title' => 'Preset only', 'size' => 'open_graph'])
            ->assertStatus(200);

        $this->assertDatabaseHas('templates', [
            'title' => 'Preset only',
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
        ]);
    }

    /**
     * A create that sends no `layers` must store an empty array.
     *
     * `json_encode(null)` returns the four-character string `null`, and the
     * `layers` column accepted it. The render path reads that value as a
     * layer list and crashes, so the malformed template saved clean and
     * failed later. `App\Mcp\Tools\CreateTemplateTool` writes `[]` here.
     */
    public function test_create_with_no_layers_stores_an_empty_array()
    {
        [$user, $token] = $this->createApiUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'No layers',
                'width' => 1200,
                'height' => 630,
            ])
            ->assertStatus(200);

        $template = Template::where('title', 'No layers')->firstOrFail();

        $this->assertSame('[]', $template->layers);
        $this->assertSame([], json_decode($template->layers, true));
    }

    /**
     * A create that sends an explicit null `layers` must store an empty array.
     *
     * The rule is `nullable`, so a null body value is legal input. It must
     * reach the column as an empty list, not as the string `null`.
     */
    public function test_create_with_a_null_layers_value_stores_an_empty_array()
    {
        [$user, $token] = $this->createApiUser();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Null layers',
                'width' => 1200,
                'height' => 630,
                'layers' => null,
            ])
            ->assertStatus(200);

        $this->assertSame('[]', Template::where('title', 'Null layers')->firstOrFail()->layers);
    }

    /**
     * The create response must hold the documented field set and nothing more.
     *
     * The route returned the Eloquent model. `App\Models\Template` declares
     * neither `$hidden` nor `$visible`, so every loaded attribute reached the
     * caller, including `company_id`, `user_id`, the numeric `id`, the raw
     * `layers` JSON and the whole `media` relation.
     *
     * The assertion reads the whole body. A subset check passes on an extra
     * field, and an extra field is the defect.
     */
    public function test_create_returns_the_documented_field_set_and_nothing_more()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Exact shape',
                'size' => 'open_graph',
                'layers' => [],
            ]);

        $response->assertStatus(200);

        $template = Template::where('title', 'Exact shape')->firstOrFail();

        $response->assertExactJson([
            'data' => [
                'uuid' => $template->uuid,
                'title' => 'Exact shape',
                'size' => 'open_graph',
                'width' => 1200,
                'height' => 630,
                'created_at' => $template->created_at->toJSON(),
            ],
            'message' => 'Template created successfully',
        ]);
    }

    /**
     * The read answers one field list with one type per field.
     *
     * `store()` returned `(int) $template->width` and `show()` returned the raw
     * string column, so one resource answered `1200` and `"1200"` for one
     * field. `show()` also left out `size`, which the create returns. The
     * assertion is `assertSame` over the whole decoded body: it reads the key
     * order, the field set and the PHP type of every value. `assertJsonFragment`
     * compares loosely, so it passes on `"1200"` against `1200`.
     */
    public function test_show_returns_the_documented_field_set_with_the_documented_types()
    {
        [$user, $token] = $this->createApiUser();

        // The factory picks a random preset, and `Template::booted()` returns
        // early on a dirty `size`, so the preset is named here.
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Exact read shape',
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
            'layers' => json_encode([]),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}");

        $response->assertStatus(200);

        $this->assertSame([
            'data' => [
                'uuid' => $template->uuid,
                'title' => 'Exact read shape',
                'size' => 'open_graph',
                'width' => 1200,
                'height' => 630,
                'layers' => [],
                'published_version' => null,
                'has_unpublished_edits' => false,
                'created_at' => $template->created_at->toJSON(),
            ],
        ], $response->json());
    }

    /**
     * A canvas that matches no preset reads `custom`.
     *
     * The `ApiSpec` entry for this endpoint promises that value in words. The
     * other tests of `size` all use a template that matches a preset, so the
     * fallback had no test. A change to `Template::booted()` or to
     * `TemplateSizes::resolveValue()` can break the documented contract with
     * no red test.
     */
    public function test_show_reads_custom_when_the_canvas_matches_no_preset()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
            'layers' => json_encode([]),
        ]);

        // The saving hook resolves the preset from the new dimensions. 917x413
        // matches no entry of `TemplateSizes::ALL`.
        //
        // `writeRules()` keeps `title` required, so a PATCH that changes only
        // the canvas must resend it. The dimensions are nullable and the title
        // is not, which makes this route partial for every field except one.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => $template->title,
                'width' => 917,
                'height' => 413,
            ])
            ->assertStatus(200);

        $body = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}")
            ->assertStatus(200)
            ->json();

        $this->assertSame('custom', $body['data']['size']);
        $this->assertSame(917, $body['data']['width']);
        $this->assertSame(413, $body['data']['height']);
    }

    /**
     * The list answers the same canvas type as the read and the create.
     *
     * `index()` selects the string columns straight off the model, so the list
     * was the third shape of one field. A fix of `show()` alone leaves the
     * shared `Template` schema unable to describe both endpoints.
     */
    public function test_list_returns_the_canvas_size_as_integers()
    {
        [$user, $token] = $this->createApiUser();

        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'size' => 'open_graph',
            'width' => 1200,
            'height' => 630,
        ]);

        $row = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/templates')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertSame(1200, $row['width']);
        $this->assertSame(630, $row['height']);
    }

    /**
     * The three write messages read one form.
     *
     * The update and the delete answered `template updated sucessfully` and
     * `template deleted sucessfully`. The word was misspelled and the casing
     * was a third form beside `Template created successfully`.
     *
     * The delete assertion stays exact, because it also guards the one
     * exemption from the `data` envelope: a delete returns no resource.
     */
    public function test_update_and_delete_answer_the_same_message_form_as_create()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", ['title' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Template updated successfully');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$template->id}")
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Template deleted successfully']);
    }

    public function test_update_accepts_null_dimension_and_first_party_image()
    {
        // Mirrors the editor save for an auto-height image layer whose source is
        // our own /storage media. Null height = auto; the app's own host is
        // trusted first-party (not an SSRF target).
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        // The origin of APP_URL, with its port. SafeUrl trusts only that origin.
        $origin = rtrim((string) config('app.url'), '/');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Auto height image',
                'width' => 1080,
                'height' => 1080,
                'layers' => [[
                    'key' => 'layer_1',
                    'properties' => [
                        ['name' => 'backgroundImage', 'value' => "{$origin}/storage/29/conversions/x.jpg"],
                        ['name' => 'height', 'value' => null],
                        ['name' => 'width', 'value' => 450],
                    ],
                ]],
            ]);

        $response->assertStatus(200);
    }

    public function test_a_patch_of_only_the_title_keeps_every_other_stored_field()
    {
        // Every field but `title` is nullable, so a body of only a title is a
        // legal partial update. A field the caller did not send keeps its
        // stored value.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'title' => 'Stored title',
            'width' => 1280,
            'height' => 720,
            'size' => 'youtube_thumbnail',
            'layers' => json_encode([['key' => 'a', 'properties' => []]]),
            'groups' => json_encode([['id' => 'g1', 'name' => 'Group A']]),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Only the title',
            ])
            ->assertStatus(200);

        $template->refresh();

        $this->assertSame('Only the title', $template->title);
        $this->assertSame(1280, (int) $template->width);
        $this->assertSame(720, (int) $template->height);
        $this->assertSame('youtube_thumbnail', $template->size);
        $this->assertSame([['key' => 'a', 'properties' => []]], json_decode($template->layers, true));
        $this->assertSame([['id' => 'g1', 'name' => 'Group A']], json_decode($template->groups, true));
    }

    public function test_a_patch_of_only_the_width_keeps_the_stored_height()
    {
        // `width` and `height` are two fields. A caller that resizes one axis
        // keeps the other.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => 800,
            'height' => 600,
            'size' => 'custom',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Wider',
                'width' => 1200,
            ])
            ->assertStatus(200);

        $template->refresh();

        $this->assertSame(1200, (int) $template->width);
        $this->assertSame(600, (int) $template->height);
    }

    public function test_a_patch_that_sends_groups_null_clears_the_stored_groups()
    {
        // `templates.groups` is a nullable column, so null is a value a caller
        // can write. This is the deliberate clear and it must stay possible.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'groups' => json_encode([['id' => 'g1', 'name' => 'Group A']]),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Groups cleared',
                'groups' => null,
            ])
            ->assertStatus(200);

        $this->assertNull($template->refresh()->groups);
    }

    public function test_a_patch_that_sends_groups_writes_the_new_groups()
    {
        // The null branch above proves a caller can clear the groups. It never
        // reaches the write. This test reaches it, and it is the one shape of
        // the update path that no test in the suite covered.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'groups' => json_encode([['id' => 'g1', 'name' => 'Group A']]),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Groups replaced',
                'groups' => [['id' => 'g2', 'name' => 'Group B']],
            ])
            ->assertStatus(200);

        $this->assertSame(
            [['id' => 'g2', 'name' => 'Group B']],
            json_decode($template->refresh()->groups, true)
        );
    }

    public function test_a_patch_that_sends_layers_null_keeps_the_stored_layers()
    {
        // `templates.layers` is a NOT NULL column, so null is not a value a
        // caller can write. A null `layers` therefore keeps the stored layers.
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'layers' => json_encode([['key' => 'a', 'properties' => []]]),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Layers untouched',
                'layers' => null,
            ])
            ->assertStatus(200);

        $this->assertSame(
            [['key' => 'a', 'properties' => []]],
            json_decode($template->refresh()->layers, true)
        );
    }
}
