<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\SnapResource;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `SnapResource` is the one shape a snap takes on the wire: the MCP create
 * tools, the outbound webhook and the batch item list all serialize it.
 */
class SnapResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inline_snap_reports_the_size_of_its_render_source_and_no_template()
    {
        $snap = Snap::factory()->inline()->create([
            'source_template_version_id' => TemplateVersion::factory()->inline()->create(['width' => 640, 'height' => 360])->id,
        ]);

        $data = (new SnapResource($snap))->resolve();

        $this->assertSame(640, $data['width']);
        $this->assertSame(360, $data['height']);
        $this->assertNull($data['template_id']);
        $this->assertNull($data['template_title']);
    }

    public function test_a_templated_snap_reports_the_size_it_rendered_at_not_the_template_size_today()
    {
        $template = Template::factory()->create(['width' => '1000', 'height' => '500', 'title' => 'Card']);
        $version = TemplateVersion::factory()->create(['template_id' => $template->id, 'width' => 1000, 'height' => 500]);
        $snap = Snap::factory()->create(['template_id' => $template->id, 'source_template_version_id' => $version->id]);

        $template->update(['width' => '2000', 'height' => '1000']);

        $data = (new SnapResource($snap->fresh()))->resolve();

        $this->assertSame(1000, $data['width']);
        $this->assertSame(500, $data['height']);
        $this->assertSame($template->uuid, $data['template_id']);
        $this->assertSame('Card', $data['template_title']);
    }

    public function test_a_legacy_snap_with_no_source_version_falls_back_to_the_template_size()
    {
        $template = Template::factory()->create(['width' => '300', 'height' => '150']);
        $snap = Snap::factory()->create(['template_id' => $template->id, 'source_template_version_id' => null]);

        $data = (new SnapResource($snap))->resolve();

        $this->assertSame(300, $data['width']);
        $this->assertSame(150, $data['height']);
    }
}
