<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Support\TemplateSizes;
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

#[Name('list-template-sizes')]
#[Title('List canvas size presets')]
#[Description('Lists every canvas size preset you can pass as `size` to create-template and edit-template — Instagram, Facebook, X, LinkedIn, Threads, Pinterest, TikTok, YouTube, Snapchat, WhatsApp, web and email, display ads, and print. Each preset gives its width, height, aspect ratio, and the safe margin to keep content away from the edges. Call it before create-template so you can name a preset instead of guessing pixel dimensions.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListTemplateSizesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'platform' => ['nullable', 'string', 'max:64'],
        ]);

        $platform = $validated['platform'] ?? null;
        $presets = TemplateSizes::catalog();

        if ($platform) {
            $needle = strtolower($platform);

            $presets = array_values(array_filter(
                $presets,
                fn (array $preset) => str_contains(strtolower($preset['platform']), $needle)
                    || str_contains(strtolower($preset['size']), $needle)
            ));

            if ($presets === []) {
                return ToolError::noSizePresetMatch($platform);
            }
        }

        return Response::text(json_encode([
            'how_to_use' => 'Pass a preset `size` (for example "instagram_story") to create-template or '
                .'edit-template and the canvas dimensions are set for you. Pass `width` and `height` '
                .'instead only when you need a size no preset covers. Keep text, logos and buttons '
                .'inside safe_margin_px of every edge.',
            'platforms' => array_values(TemplateSizes::PLATFORMS),
            'presets' => $presets,
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->description('Optional filter, for example "instagram", "youtube" or "print". Matches the platform name and the preset name. Leave it out to get every preset.'),
        ];
    }
}
