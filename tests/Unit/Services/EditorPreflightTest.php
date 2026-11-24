<?php

namespace Tests\Unit\Services;

use App\Services\EditorPreflight;
use Tests\TestCase;

class EditorPreflightTest extends TestCase
{
    public function test_reports_unpinned_image_before_render(): void
    {
        $issues = (new EditorPreflight)->inspect(['layers' => [['type' => 'image', 'source' => ['url' => 'https://example.test/a.png']]]]);

        $this->assertSame('image_asset_missing', $issues[0]['code']);
    }
}
