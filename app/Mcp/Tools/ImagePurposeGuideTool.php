<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Support\ImagePurposeGuide;
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

#[Name('image-purpose-guide')]
#[Title('Image purpose guide')]
#[Description('Call this whenever the human says what the image is FOR rather than where it goes — "an og:image / meta tag image", "a carousel", "a gallery", "a photo for the feed", "a profile picture", "a banner", "a YouTube thumbnail", "a product photo", "an email header", "a blog hero", "an ad". Returns the job that kind of image has to do, the size presets that fit it, the rules and failure modes specific to it, a layer recipe in the Snapsbrew vocabulary, an anti-pattern list and a preflight checklist. The `link_preview` purpose also returns the exact og: and twitter: meta tags to put in the page head. Pair it with platform-guide: this tool says what the image must achieve, platform-guide says what the platform will do to it.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ImagePurposeGuideTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'purpose' => ['nullable', 'string', 'max:64'],
        ]);

        $input = $validated['purpose'] ?? null;

        if ($input === null || trim($input) === '') {
            return Response::text(json_encode([
                'how_to_use' => 'Pick the purpose that matches what the human asked for and call this tool '
                    .'again with it to get the full rules, the layer recipe and the checklist.',
                'purposes' => ImagePurposeGuide::index(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $key = ImagePurposeGuide::resolve($input);

        if ($key === null) {
            return ToolError::noPurposeGuideMatch($input, ImagePurposeGuide::keys());
        }

        return Response::text(json_encode(ImagePurposeGuide::find($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'purpose' => $schema->string()->description(
                'What the image is for. Accepts a key ('.implode(', ', ImagePurposeGuide::keys()).') '
                .'or a common alias ("og image", "meta tag", "gallery", "slides", "avatar", "banner", '
                .'"thumbnail", "product photo", "newsletter"). Leave it out to list every purpose.'
            ),
        ];
    }
}
