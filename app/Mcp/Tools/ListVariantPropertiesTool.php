<?php

namespace App\Mcp\Tools;

use App\Support\VariantProperties;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-variant-properties')]
#[Title('List variant properties')]
#[Description('Returns the full catalog of properties you can apply to a template layer in `variants`, each with its value type, which layer types it applies to, and a concrete example. Use this to learn the override vocabulary before building variants for preview-snap or create-snap. Combine with get-template-details, which tells you the layer keys available on a specific template.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListVariantPropertiesTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text(json_encode([
            'usage' => 'Each variants item is { "layer": "<layer key>", "<property>": <value> }. '
                .'Get layer keys from get-template-details.',
            'properties' => VariantProperties::catalog(),
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
