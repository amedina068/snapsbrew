<?php

namespace Tests\Unit\Parsers;

use App\Parsers\TemplateLayerParser;
use Tests\TestCase;

class TemplateLayerParserTest extends TestCase
{
    public function test_parses_text_layer_modifications()
    {
        $parsed = (new TemplateLayerParser([
            [
                'key' => 'headline',
                'type' => 'text',
                'properties' => [
                    ['name' => 'textContent', 'value' => 'Hello'],
                ],
            ],
        ]))->parse();

        $this->assertSame('headline', $parsed[0]['layer']);
        $this->assertSame('Hello', $parsed[0]['available_modifications']['text']);
    }

    public function test_layer_without_type_does_not_crash()
    {
        // MCP-created layers omit `type`; parsing them must not fault.
        $parsed = (new TemplateLayerParser([
            [
                'key' => 'headline',
                'properties' => [
                    ['name' => 'textContent', 'value' => 'Hello'],
                ],
            ],
        ]))->parse();

        $this->assertSame('headline', $parsed[0]['layer']);
        $this->assertEmpty($parsed[0]['available_modifications']);
    }

    public function test_layer_without_key_or_properties_does_not_crash()
    {
        $parsed = (new TemplateLayerParser([
            ['type' => 'text'],
        ]))->parse();

        $this->assertNull($parsed[0]['layer']);
        $this->assertEmpty($parsed[0]['available_modifications']);
    }
}
