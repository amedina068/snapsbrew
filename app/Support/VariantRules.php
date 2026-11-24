<?php

namespace App\Support;

use App\Rules\SafeUrl;
use App\Rules\ValidCssColor;

/**
 * Shared validation rules for the `variants` payload accepted by snap creation
 * and preview surfaces (REST + MCP).
 *
 * The security-critical rule is SafeUrl on `image_url`: a variant can override a
 * layer's background image with a caller-supplied URL that Browsershot (Chrome)
 * then fetches — an SSRF vector unless the host is constrained to public IPs.
 */
class VariantRules
{
    public static function rules(string $key = 'variants'): array
    {
        return [
            $key => ['nullable', 'array'],
            "$key.*.layer" => ['sometimes', 'string'],
            "$key.*.text" => ['sometimes', 'string'],
            "$key.*.image_url" => ['sometimes', 'url', new SafeUrl],
            "$key.*.background_color" => ['sometimes', 'string', new ValidCssColor],
            "$key.*.qr_content" => ['sometimes', 'string'],
            "$key.*.barcode_content" => ['sometimes', 'string', 'max:1000'],
            "$key.*.hidden" => ['sometimes', 'boolean'],
        ];
    }
}
