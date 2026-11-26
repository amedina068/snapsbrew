<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\EditTemplateTool;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A template write renders a new preview only when the image changes.
 *
 * The preview render reads `layers`, `groups`, `width` and `height`. A `size`
 * preset reaches the render only through `width` and `height`. A write that
 * changes none of the four keeps the stored preview and its URL. A repeat of
 * the same `PATCH` thus costs no Browserless render.
 */
class TemplatePreviewRenderTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    private User $user;

    private string $token;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SnapRenderer::class, $this->renderer = new FakeRenderer);

        $this->user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->token = $this->user->createToken('test', ['api_requests'])->plainTextToken;

        $this->template = Template::factory()->instagram()->withLayers($this->layers('Before'))->create([
            'user_id' => $this->user->id,
            'company_id' => $this->user->company_id,
        ]);

        $this->template->generatePreviewImage();
        $this->renderer->screenshotCalls = [];
    }

    public function test_a_patch_that_changes_the_layers_renders_a_new_preview()
    {
        $before = $this->previewUrl();

        $this->patchTemplate(['layers' => $this->layers('After')])->assertOk();

        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertNotSame($before, $this->previewUrl());
    }

    public function test_a_patch_that_changes_the_groups_renders_a_new_preview()
    {
        $this->patchTemplate(['groups' => [['id' => 'g1', 'name' => 'Group', 'layers' => ['headline']]]])->assertOk();

        $this->assertCount(1, $this->renderer->screenshotCalls);
    }

    public function test_a_patch_that_changes_the_canvas_renders_a_new_preview()
    {
        $this->patchTemplate(['size' => 'instagram_story'])->assertOk();

        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertSame(1080, $this->renderer->screenshotCalls[0]['width']);
        $this->assertSame(1920, $this->renderer->screenshotCalls[0]['height']);
    }

    public function test_a_repeat_of_the_same_patch_renders_nothing_and_keeps_the_preview_url()
    {
        $body = ['layers' => $this->layers('After'), 'width' => 1200, 'height' => 630];

        $this->patchTemplate($body)->assertOk();
        $after = $this->previewUrl();

        $this->patchTemplate($body)->assertOk();

        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertSame($after, $this->previewUrl());
    }

    /**
     * MySQL stores `layers` in a JSON column and returns it with its own
     * spacing and key order. The same layers in another format are not a
     * change of the image.
     */
    public function test_a_patch_with_the_same_layers_in_another_json_format_renders_nothing()
    {
        DB::table('templates')->where('id', $this->template->id)->update([
            'layers' => '[{"properties": [{"value": "Before", "name": "textContent"}], "key": "headline"}]',
        ]);
        $before = $this->previewUrl();

        $this->patchTemplate(['layers' => $this->layers('Before')])->assertOk();

        $this->assertCount(0, $this->renderer->screenshotCalls);
        $this->assertSame($before, $this->previewUrl());
    }

    public function test_a_patch_of_only_the_title_renders_nothing_and_keeps_the_preview_url()
    {
        $before = $this->previewUrl();

        $this->patchTemplate(['title' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $this->template->refresh()->title);
        $this->assertCount(0, $this->renderer->screenshotCalls);
        $this->assertSame($before, $this->previewUrl());
    }

    public function test_a_patch_of_a_template_with_no_preview_renders_one()
    {
        $this->template->clearMediaCollection('preview');

        $this->patchTemplate(['title' => 'Renamed'])->assertOk();

        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertNotSame('', $this->previewUrl());
    }

    public function test_a_failed_render_saves_nothing_so_a_retry_renders_again()
    {
        $before = $this->previewUrl();
        $body = ['title' => 'Renamed', 'layers' => $this->layers('After')];

        $this->app->instance(SnapRenderer::class, new class extends FakeRenderer
        {
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                throw new RuntimeException('Browserless is down.');
            }
        });

        $this->patchTemplate($body)->assertStatus(500);

        $this->template->refresh();
        $this->assertNotSame('Renamed', $this->template->title);
        $this->assertSame($this->layers('Before'), json_decode($this->template->layers, true));
        $this->assertSame($before, $this->previewUrl());
        $this->assertSame(0, $this->template->snaps()->count());

        $this->app->instance(SnapRenderer::class, $this->renderer);

        $this->patchTemplate($body)->assertOk();

        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertSame('Renamed', $this->template->refresh()->title);
        $this->assertNotSame($before, $this->previewUrl());
    }

    public function test_the_mcp_edit_renders_only_when_the_image_changes()
    {
        $edit = fn (array $arguments) => SnapsbrewServer::actingAs($this->user)
            ->tool(EditTemplateTool::class, ['template_id' => $this->template->uuid] + $arguments)
            ->assertOk();

        $edit(['title' => 'Renamed']);
        $this->assertCount(0, $this->renderer->screenshotCalls);

        $edit(['layers' => $this->layers('After')]);
        $edit(['layers' => $this->layers('After')]);
        $this->assertCount(1, $this->renderer->screenshotCalls);
    }

    private function patchTemplate(array $body)
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/v1/templates/{$this->template->uuid}", $body + ['title' => $this->template->title]);
    }

    private function layers(string $text): array
    {
        return [['key' => 'headline', 'properties' => [['name' => 'textContent', 'value' => $text]]]];
    }

    private function previewUrl(): string
    {
        return $this->template->refresh()->getFirstMediaUrl('preview');
    }
}
