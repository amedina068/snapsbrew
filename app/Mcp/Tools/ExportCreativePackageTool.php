<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\RespondsWithCreativePackage;
use App\Services\CreativePackageClaim;
use App\Services\CreativePackageClaimException;
use App\Support\CreativePackageContract;
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

#[Name('export-creative-package')]
#[Title('Export a creative package')]
#[Description('Renders up to 10 FINAL images from published template versions into one portable package. Returns a package id right away and renders in the background. Every item needs a template_id, a published template_version, a label and alt_text. Poll get-creative-package with the package id to read progress and collect the signed file URLs. A retry with the same idempotency_key returns the same package and starts no second one.')]
// destructiveHint stays true although an export is additive. Final renders
// cannot be undone, and this hint makes a client ask the human first.
#[IsDestructive]
// The request carries no caller-supplied URL, and every source is a template
// version this account owns, so the world is closed.
#[IsOpenWorld(false)]
#[IsReadOnly(false)]
// `idempotency_key` is required, so every retry returns the same package.
#[IsIdempotent]
class ExportCreativePackageTool extends Tool
{
    use RespondsWithCreativePackage;

    /**
     * The tool validates nothing itself. `CreativePackageClaim` normalizes the
     * decoded arguments and names every failing field, so one validator serves
     * the tool and every later surface. A `ValidationException` raised here
     * would answer as prose, and the contract answers as
     * `creative_package_invalid_request` with bounded `fields`.
     */
    public function handle(Request $request, CreativePackageClaim $claim): Response
    {
        try {
            $result = $claim->claim($request->user(), $request->all());
        } catch (CreativePackageClaimException $exception) {
            return ToolError::creativePackage($exception);
        }

        return $this->packageResponse($result->package, $result->replayed);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()
                ->description('Required. Send the same key when you retry. The retry returns the same package and starts no second one. Letters, digits, underscores and hyphens, at most '.CreativePackageContract::MAX_KEY_LENGTH.' characters.')
                ->required(),
            'items' => $schema->array()
                ->description('Ordered list of 1 to '.CreativePackageContract::MAX_ITEMS.' creatives. The response keeps this order.')
                ->items($schema->object([
                    'client_creative_id' => $schema->string()->description('Your own stable id for this creative. Unique within the request.')->required(),
                    'client_family_id' => $schema->string()->description('Optional. Groups creatives that belong together.'),
                    'template_id' => $schema->string()->description('UUID of a template in your account.')->required(),
                    'template_version' => $schema->integer()->description('A published version number of that template. Read it with get-template-details.')->required(),
                    'label' => $schema->string()->description('Display label for this creative.')->required(),
                    'placement_key' => $schema->string()->description('Optional provider-neutral placement, for example instagram.feed.organic.'),
                    'caption' => $schema->string()->description('Optional caption to carry with the image.'),
                    'alt_text' => $schema->string()->description('Required alternative text for the image.')->required(),
                    'metadata' => $schema->object()->description('Optional JSON object of your own fields. At most '.CreativePackageContract::MAX_METADATA_BYTES.' bytes and '.CreativePackageContract::MAX_METADATA_DEPTH.' levels deep.'),
                    'format' => $schema->string()->enum(CreativePackageContract::FORMATS)->description('Image output format.')->required(),
                ]))
                ->required(),
        ];
    }
}
