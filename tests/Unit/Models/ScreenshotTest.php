<?php

namespace Tests\Unit\Models;

use App\Models\Screenshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_screenshot_has_uuid()
    {
        $screenshot = Screenshot::factory()->create();

        $this->assertNotNull($screenshot->uuid);
    }

    public function test_screenshot_factory_defaults()
    {
        $screenshot = Screenshot::factory()->create();

        $this->assertNotNull($screenshot->url);
        $this->assertNotNull($screenshot->width);
        $this->assertNotNull($screenshot->height);
        $this->assertFalse((bool) $screenshot->full_page);
    }

    public function test_the_image_url_is_null_when_the_screenshot_has_no_image()
    {
        $screenshot = Screenshot::factory()->create();

        $this->assertNull($screenshot->imageUrl);
    }

    public function test_full_page_state()
    {
        $screenshot = Screenshot::factory()->fullPage()->create();

        $this->assertTrue((bool) $screenshot->full_page);
    }
}
