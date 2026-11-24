<?php

namespace Tests\Unit\Models;

use App\Contracts\SnapRenderer;
use App\Models\TemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class TemplateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_preview_is_a_no_op_for_a_template_less_version()
    {
        $fake = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $fake);

        $version = TemplateVersion::factory()->inline()->create();

        $version->generatePreview();

        $this->assertCount(0, $fake->screenshotCalls);
        $this->assertNull($version->getFirstMedia('preview'));
        $this->assertDatabaseCount('snaps', 0);
    }
}
