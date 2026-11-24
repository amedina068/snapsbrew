<?php

namespace App\Support;

/**
 * Builds an HMAC-signed render URL for the public signed-URL endpoint
 * (`/signed-url/image.jpg`). Used to dogfood Snapsbrew for our own marketing OG
 * / social cards: one template, per-page `variants`, no API call at request time.
 *
 * The signature must match App\Http\Middleware\SignedUrlMiddleware exactly: the
 * middleware rebuilds the signed string from the URL-DECODED query params, so we
 * sign over the raw (decoded) values joined "key=value" after ksort, and let
 * http_build_query handle wire-encoding. `variants` is standard base64 of the
 * JSON (the controller decodes it with plain base64_decode), which http_build_query
 * percent-encodes so it survives the round-trip untouched.
 */
class SignedImageUrl
{
    /**
     * @param  array<int,array<string,mixed>>  $variants  e.g. [['layer'=>'headline','text'=>'…']]
     * @return string|null the signed URL, or null when no signing key is configured
     */
    public static function make(array $variants): ?string
    {
        $key = config('services.snapsbrew.signed_url_key');
        $template = config('services.snapsbrew.og_template');

        if (empty($key) || empty($template)) {
            return null;
        }

        $fields = [
            'template_id' => $template,
            'variants' => base64_encode(json_encode(array_values($variants))),
        ];

        ksort($fields);

        $signString = collect($fields)
            ->map(fn ($value, $name) => "{$name}={$value}")
            ->implode('&');

        $signature = hash_hmac('sha256', $signString, $key);

        $base = rtrim(config('services.snapsbrew.og_base'), '/');

        return $base.'/signed-url/image.jpg?'.http_build_query($fields).'&signature='.$signature;
    }
}
