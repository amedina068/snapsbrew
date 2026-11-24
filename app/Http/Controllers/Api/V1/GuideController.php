<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\DesignGuide;
use App\Support\EditorDocumentContract;
use App\Support\ImagePurposeGuide;
use App\Support\LayerSchema;
use App\Support\PlatformGuide;
use App\Support\VariantProperties;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only guidance catalogs for API clients.
 *
 * Each action returns the one catalog class that the matching MCP tool reads,
 * so the REST surface and the MCP surface cannot drift. No action reads a
 * table or a company.
 */
class GuideController extends Controller
{
    /**
     * Design guidance for a template. The `design-guide` MCP tool reads the
     * same catalog.
     */
    public function design()
    {
        return response()->json([
            'data' => DesignGuide::catalog(),
        ]);
    }

    /**
     * The design-time layer vocabulary. The `describe-layers` MCP tool reads
     * the same catalog.
     */
    public function layers()
    {
        return response()->json([
            'data' => LayerSchema::catalog(),
        ]);
    }

    /**
     * The run-time override vocabulary for `variants`. The
     * `list-variant-properties` MCP tool reads the same catalog.
     */
    public function variantProperties()
    {
        return response()->json([
            'data' => VariantProperties::catalog(),
        ]);
    }

    /**
     * The v2 editor-document contract. The `describe-editor-document` MCP tool
     * reads the same catalog.
     */
    public function editorDocument()
    {
        return response()->json([
            'data' => EditorDocumentContract::catalog(),
        ]);
    }

    /**
     * Every platform with its default size preset. The `platform-guide` MCP
     * tool returns the same index when it is called with no platform.
     *
     * `specs_reviewed` sits beside `data` because the month applies to the
     * whole catalog. `PlatformGuide::find()` carries it inside each entry.
     */
    public function platforms()
    {
        return response()->json([
            'data' => PlatformGuide::index(),
            'specs_reviewed' => PlatformGuide::SPECS_REVIEWED,
        ]);
    }

    /**
     * The full guidance for one platform. The path segment goes through
     * `PlatformGuide::resolve()` first, so "insta", "ig", "twitter" and "og"
     * reach the same guide the MCP tool gives them.
     */
    public function platform(string $platform)
    {
        $this->guardKeyLength('platform', $platform);

        $key = PlatformGuide::resolve($platform);

        if ($key === null) {
            return response()->json([
                'message' => "No platform guide matches `{$platform}`.",
                'available' => PlatformGuide::keys(),
            ], 404);
        }

        return response()->json([
            'data' => PlatformGuide::find($key),
        ]);
    }

    /**
     * Every image purpose. The `image-purpose-guide` MCP tool returns the same
     * index when it is called with no purpose. This catalog carries no
     * reviewed month, so the response carries none either.
     */
    public function purposes()
    {
        return response()->json([
            'data' => ImagePurposeGuide::index(),
        ]);
    }

    /**
     * The full guidance for one purpose. `ImagePurposeGuide::resolve()` accepts
     * the same aliases the MCP tool accepts, for example "og" and "thumbnail".
     */
    public function purpose(string $purpose)
    {
        $this->guardKeyLength('purpose', $purpose);

        $key = ImagePurposeGuide::resolve($purpose);

        if ($key === null) {
            return response()->json([
                'message' => "No purpose guide matches `{$purpose}`.",
                'available' => ImagePurposeGuide::keys(),
            ], 404);
        }

        return response()->json([
            'data' => ImagePurposeGuide::find($key),
        ]);
    }

    /**
     * A path segment is free text of any length. The cap matches the
     * `max:64` rule that `PlatformGuideTool` and `ImagePurposeGuideTool` put on
     * the same argument, so both surfaces reject the same input.
     *
     * A route parameter is not request input, so the value is handed to the
     * validator by hand.
     */
    private function guardKeyLength(string $field, string $value): void
    {
        Validator::make([$field => $value], [$field => ['string', 'max:64']])->validate();
    }
}
