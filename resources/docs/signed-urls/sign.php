<?php

/**
 * Build a Snapsbrew signed URL. Run this on your server only.
 * The signing key is a secret.
 *
 * $options holds the other query parameters, for example
 * ['format' => 'webp', 'transparent' => '1'].
 */
function snapsbrew_signed_url(string $baseUrl, string $signingKey, string $templateId, array $variants = [], array $options = []): string
{
    $params = ['template_id' => $templateId] + $options;

    if ($variants !== []) {
        // Standard base64, with + / and = padding. Do not use base64url.
        $json = json_encode($variants, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params['variants'] = base64_encode($json);
    }

    // Sort by key in byte order. Join the raw values, not URL-encoded values.
    ksort($params, SORT_STRING);
    $canonical = implode('&', array_map(
        fn ($key, $value) => $key.'='.$value,
        array_keys($params),
        $params,
    ));

    $params['signature'] = hash_hmac('sha256', $canonical, $signingKey);

    // URL-encode every value in the final URL.
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return rtrim($baseUrl, '/').'/signed-url/image.jpg?'.$query;
}
