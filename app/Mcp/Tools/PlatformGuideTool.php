<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Support\PlatformGuide;
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

#[Name('platform-guide')]
#[Title('Platform image rules')]
#[Description('Call this FIRST whenever the human names a platform — "an image for Instagram", "a post for LinkedIn", "an X card", "a TikTok". Returns what that platform does to an image after upload and the design rules that follow: which size preset to start from and why, how the platform crops the image, the DIRECTIONAL UI safe zones where the platform paints its own controls over the frame (Story, Reel, TikTok, Shorts, banners), accepted file formats and size limits, the export width and colour profile, plus platform-specific rules and anti-patterns. Covers Instagram, Facebook, X, LinkedIn, Threads, Pinterest, TikTok, YouTube, Snapchat, WhatsApp, web and email, display ads, and print. Accepts the names people actually type ("insta", "twitter", "og").')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class PlatformGuideTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'platform' => ['nullable', 'string', 'max:64'],
        ]);

        $input = $validated['platform'] ?? null;

        if ($input === null || trim($input) === '') {
            return Response::text(json_encode([
                'how_to_use' => 'Call this tool again with a `platform` for the full rules. Pick the platform '
                    .'the human named; `start_here` is the size preset to use when they name nothing else.',
                'specs_reviewed' => PlatformGuide::SPECS_REVIEWED,
                'platforms' => PlatformGuide::index(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $key = PlatformGuide::resolve($input);

        if ($key === null) {
            return ToolError::noPlatformGuideMatch($input, PlatformGuide::keys());
        }

        return Response::text(json_encode(PlatformGuide::find($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->description(
                'The platform the image is for. Accepts a key ('.implode(', ', PlatformGuide::keys()).') '
                .'or a common alias ("insta", "ig", "twitter", "fb", "og", "email", "shorts"), and a phrase '
                .'like "instagram story". Leave it out to list every platform with its default size.'
            ),
        ];
    }
}
