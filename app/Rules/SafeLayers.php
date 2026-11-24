<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Blocks SSRF stored in a template's layer definition.
 *
 * A layer's `backgroundImage` property value is injected verbatim into a CSS
 * `background-image: url(...)` that Browsershot (Chrome) fetches on EVERY future
 * render of the template. Unlike run-time `variants[].image_url` (guarded by
 * SafeUrl), the stored layer value is otherwise unvalidated — so an internal or
 * file:// URL saved once fires on every subsequent snap/preview.
 *
 * Allows: empty values, relative paths (same-origin), and data: URIs (inline).
 * Validates absolute and scheme-relative URLs through SafeUrl (public IPs only)
 * and rejects non-http(s) schemes such as file:// or gopher://.
 */
class SafeLayers implements Rule
{
    public function passes($attribute, $value): bool
    {
        if (! is_array($value)) {
            return true; // the `array` rule owns the type check
        }

        // Many layers of one template carry one background image URL. `SafeUrl`
        // resolves that host through DNS, and PHP exposes no resolver timeout,
        // so the repeat is a real cost: 50 layers on one URL measured 100
        // resolution calls.
        //
        // The key is the URL string, not the host. Two spellings of one host
        // each resolve, which `SafeLayersTest` records. `SafeUrl` reads the
        // whole URL, so a key on the host alone would answer for a URL it
        // never read.
        //
        // The memo lives in this call and never becomes static. A static cache
        // outlives the request, and the address a host resolves to can change,
        // so a stale entry would pass a URL that is no longer safe.
        //
        // Only a safe value is stored. The loop returns at the first unsafe
        // value, so an unsafe value is never read twice.
        $safe = [];

        foreach ($value as $layer) {
            $properties = is_array($layer) ? ($layer['properties'] ?? []) : [];

            foreach ((array) $properties as $property) {
                if (! is_array($property) || ($property['name'] ?? null) !== 'backgroundImage') {
                    continue;
                }

                $url = $property['value'] ?? '';

                if (is_string($url) && isset($safe[$url])) {
                    continue;
                }

                if (! $this->safeValue($url)) {
                    return false;
                }

                if (is_string($url)) {
                    $safe[$url] = true;
                }
            }
        }

        return true;
    }

    public function message(): string
    {
        return 'A layer background image points to an internal, reserved, or unsafe address.';
    }

    private function safeValue($value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        // The URL parser of Chrome drops leading and trailing control
        // characters and spaces, and every tab and newline. Read the value
        // the same way, or ` //host/x` passes as a relative path.
        $value = preg_replace('/[\t\n\r]/', '', trim($value, "\x00..\x20"));

        $parts = parse_url($value);

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme === 'data') {
            return true; // inline image, no outbound fetch
        }

        // Chrome reads a backslash as a slash in an http(s) or relative URL,
        // so `\\host\x` and `/\host/x` are scheme-relative URLs there.
        // `parse_url()` reads them as paths, so the check refuses them.
        if (str_contains($value, '\\')) {
            return false;
        }

        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return false; // file://, gopher://, etc.
        }

        // `parse_url()` reads `http:169.254.169.254/x` as a path with no host.
        // Chrome reads it as a URL on that host, so an http(s) value must
        // carry `//` and a host.
        if ($scheme !== '' && ! preg_match('#^\s*https?://#i', $value)) {
            return false;
        }

        // Pure relative path (no host) is same-origin and safe.
        if (empty($parts['host'])) {
            return true;
        }

        // Absolute or scheme-relative ("//host/..") URL → enforce public host.
        $url = $scheme === '' ? 'https:'.$value : $value;

        return (new SafeUrl)->passes('', $url);
    }
}
