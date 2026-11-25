<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Http\Resources\SnapResource;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Rendering\RenderWarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A render that could not load a font must say so. The warning goes to the
 * image metadata of the snap and to the `warnings` field of the API.
 */
class RenderWarningsTest extends TestCase
{
    use RefreshDatabase;

    private const WARNING = ['code' => 'font_unavailable', 'family' => 'Fancy Script', 'message' => 'The font "Fancy Script" is not available on this server. The image can show a fallback font.'];

    public function test_a_render_warning_is_stored_on_the_image_and_returned_by_the_api()
    {
        $this->app->instance(SnapRenderer::class, new class extends FakeRenderer
        {
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                app(RenderWarnings::class)->add(RenderWarningsTest::warning());

                return parent::screenshotBase64($html, $width, $height, $format, $transparent);
            }
        });

        $snap = $this->snap();
        $snap->getImage();

        $this->assertSame([self::WARNING], $snap->fresh()->getFirstMedia('image')->getCustomProperty('render_warnings'));
        $this->assertSame([self::WARNING], (new SnapResource($snap->fresh()))->resolve()['warnings']);
        $this->assertSame([], app(RenderWarnings::class)->all());
    }

    public function test_a_clean_render_has_no_warnings_and_drops_an_old_one()
    {
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
        app(RenderWarnings::class)->add(['code' => 'font_unavailable', 'family' => 'Other', 'message' => 'From an earlier render in the same worker.']);

        $snap = $this->snap();
        $snap->getImage();

        $this->assertNull($snap->fresh()->getFirstMedia('image')->getCustomProperty('render_warnings'));
        $this->assertSame([], (new SnapResource($snap->fresh()))->resolve()['warnings']);
    }

    public function test_a_snap_with_no_image_has_no_warnings()
    {
        $this->assertSame([], (new SnapResource($this->snap()))->resolve()['warnings']);
    }

    public function test_the_collector_is_new_for_each_job_or_request()
    {
        app(RenderWarnings::class)->add(['code' => 'font_unavailable', 'family' => 'Other', 'message' => 'x']);

        $this->app->forgetScopedInstances();

        $this->assertSame([], app(RenderWarnings::class)->all());
    }

    /** @return array{code: string, family: string, message: string} */
    public static function warning(): array
    {
        return self::WARNING;
    }

    private function snap(): Snap
    {
        $template = Template::factory()->create(['width' => '20', 'height' => '20']);
        $version = TemplateVersion::factory()->create(['template_id' => $template->id, 'width' => 20, 'height' => 20]);

        return Snap::factory()->create(['template_id' => $template->id, 'source_template_version_id' => $version->id, 'format' => 'png']);
    }
}
