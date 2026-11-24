<?php

namespace App\Support;

use App\Models\Template;
use Illuminate\Http\JsonResponse;

/**
 * The template of a free tool comes from the configuration, never from the
 * request. A public request without a token must not render the template of
 * a company.
 */
final class FreeToolTemplate
{
    /**
     * @param  'certificate'|'testimonial'|'twitter_graph'  $tool
     */
    public static function find(string $tool): ?Template
    {
        $uuid = config("services.free_tools.templates.$tool");

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Template::query()->where('uuid', $uuid)->first();
    }

    public static function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'This tool is not available. The operator did not set its template.',
        ], 503);
    }
}
