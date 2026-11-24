<?php

namespace Tests\Unit\Editor;

use App\Editor\ResponsiveCanvasResolver;
use Tests\TestCase;

class ResponsiveCanvasResolverTest extends TestCase
{
    public function test_named_canvas_inherits_default_values(): void
    {
        $resolved = (new ResponsiveCanvasResolver)->resolve([
            'default_canvas' => 'story',
            'canvases' => [
                'default' => ['width' => 1200, 'height' => 630, 'layers' => ['base']],
                'story' => ['width' => 1080, 'height' => 1920],
            ],
        ]);

        $this->assertSame(1080, $resolved['width']);
        $this->assertSame(['base'], $resolved['layers']);
        $this->assertSame('story', $resolved['name']);
    }
}
