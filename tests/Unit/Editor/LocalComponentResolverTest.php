<?php

namespace Tests\Unit\Editor;

use App\Editor\LocalComponentResolver;
use Tests\TestCase;

class LocalComponentResolverTest extends TestCase
{
    public function test_resolves_only_document_local_component_with_overrides(): void
    {
        $document = (new LocalComponentResolver)->resolve([
            'components' => [['id' => 'badge', 'layers' => [['id' => 'label', 'text' => 'Base']]]],
            'layers' => [['component_id' => 'badge', 'overrides' => ['label' => ['text' => 'Local']]]],
        ]);

        $this->assertSame('Local', $document['layers'][0]['resolved_layers'][0]['text']);
    }
}
