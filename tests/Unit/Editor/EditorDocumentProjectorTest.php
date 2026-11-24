<?php

namespace Tests\Unit\Editor;

use App\Editor\EditorDocumentProjector;
use Tests\TestCase;

class EditorDocumentProjectorTest extends TestCase
{
    public function test_it_preserves_direct_v2_layers_and_groups_as_legacy_renderer_json(): void
    {
        $document = [
            'version' => 2,
            'layers' => [[
                'key' => 'hero',
                'type' => 'image',
                'properties' => [
                    ['name' => 'backgroundImage', 'value' => 'https://cdn.example.test/hero.png'],
                    ['name' => 'imageFit', 'value' => 'cover'],
                    ['name' => 'imageFocalX', 'value' => 31],
                    ['name' => 'imageFocalY', 'value' => 67],
                ],
            ]],
            'groups' => [['id' => 'hero-group', 'visible' => true]],
        ];

        $projected = (new EditorDocumentProjector)->project($document);

        $this->assertSame($document['layers'], json_decode($projected['layers'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($document['groups'], json_decode($projected['groups'], true, 512, JSON_THROW_ON_ERROR));
    }
}
