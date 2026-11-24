<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\RespondsWithCreativePackage;
use App\Models\CreativePackage;
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

#[Name('get-creative-package')]
#[Title('Read a creative package')]
#[Description('Reads the progress of a package started by export-creative-package. Renders nothing. Returns the status, the counts, every item with its state, and the manifest with signed file URLs once output exists. Every call signs fresh URLs. Poll about every 30 seconds while status is building.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetCreativePackageTool extends Tool
{
    use RespondsWithCreativePackage;

    /**
     * The tool keeps its own closed-key check and does not route one field
     * through `CreativePackageRequestValidator`, which validates a whole
     * export request.
     */
    public function handle(Request $request): Response
    {
        $arguments = $request->all();
        $packageId = $arguments['package_id'] ?? null;

        if (array_keys($arguments) !== ['package_id'] || ! is_string($packageId) || preg_match(CreativePackageContract::UUID_PATTERN, $packageId) !== 1) {
            return ToolError::creativePackage(new CreativePackageClaimException(
                'creative_package_invalid_request',
                'The creative package request is invalid.',
                ['fields' => ['package_id']],
            ));
        }

        // company_id first, then the uuid. A package that belongs to another
        // company reads as "not found", never as "exists, not yours".
        $package = CreativePackage::query()
            ->where('company_id', $request->user()->company_id)
            ->where('uuid', strtolower($packageId))
            ->first();

        if (! $package) {
            return ToolError::creativePackage(new CreativePackageClaimException(
                'creative_package_not_found',
                "No package with id `{$packageId}` exists in your account.",
                ['package_id' => $packageId],
            ));
        }

        return $this->packageResponse($package, false);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'package_id' => $schema->string()
                ->description('The 36-character package id that export-creative-package returned.')
                ->min(36)
                ->max(36)
                ->required(),
        ];
    }
}
