<?php

namespace App\Mcp\Tools;

use App\Support\LayerSchema;
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

#[Name('describe-layers')]
#[Title('Describe layer structure')]
#[Description('Returns the structure of a template layer: the layer object shape (key, type, properties), the full property-name vocabulary, the value shape for each property, and a worked example. Call this BEFORE create-template or edit-template so the `layers` you pass are valid. This is the design-time vocabulary (how a layer is built); use list-variant-properties for the run-time override vocabulary.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class DescribeLayersTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text(json_encode([
            'usage' => '`layers` is an array of layer objects. Each layer is '
                .'{ "key": "<unique string>", "type"?: "<string>", "properties": [ { "name": "<string>", "value": <mixed> } ] }. '
                .'Keys must be unique within a template.',
            'schema' => LayerSchema::catalog(),
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
