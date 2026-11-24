<?php

namespace App\Mcp\Tools;

use App\Support\DesignGuide;
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

#[Name('design-guide')]
#[Title('Template design guide')]
#[Description('Returns opinionated design guidance for building good-looking templates with the Snapsbrew layer vocabulary: canvas-size presets, a back-to-front layer-order convention, typography/color rules, composition recipes (promo, quote, event, product, coupon), anti-patterns, and a preflight checklist. Read this BEFORE create-template to plan the design; pair it with describe-layers, which gives the exact property names and value shapes.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class DesignGuideTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text(json_encode(DesignGuide::catalog(), JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
