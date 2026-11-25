<?php

namespace App\Support;

/**
 * Derived defaults for an install with few or no variables, for example the
 * Docker image. The config files read the variables and pass them in, so
 * `config:cache` stores the result. A variable that an install sets always
 * wins.
 */
final class RuntimeDefaults
{
    /** The URL of the Docker image when neither APP_URL nor APP_DOMAIN is set. */
    public const DEFAULT_URL = 'http://localhost:8080';

    /**
     * APP_URL, then https://APP_DOMAIN, then the default of the image.
     */
    public static function appUrl(?string $url, ?string $domain): string
    {
        if ($url) {
            return $url;
        }

        if ($domain = self::domain($domain)) {
            return "https://{$domain}";
        }

        return self::DEFAULT_URL;
    }

    /**
     * APP_KEY, then the content of the file in APP_KEY_FILE. The Docker image
     * writes that file on the first start, because an entrypoint script
     * cannot export a variable to PHP.
     */
    public static function appKey(?string $key, ?string $keyFile): ?string
    {
        if ($key) {
            return $key;
        }

        if (! $keyFile || ! is_readable($keyFile)) {
            return null;
        }

        return trim((string) file_get_contents($keyFile)) ?: null;
    }

    /**
     * TRUSTED_PROXIES, then 127.0.0.1 when APP_DOMAIN is set: the Caddy of the
     * container sends each HTTPS request to NGINX from that address.
     */
    public static function trustedProxies(?string $proxies, ?string $domain): ?string
    {
        if ($proxies) {
            return $proxies;
        }

        return self::domain($domain) ? '127.0.0.1' : null;
    }

    private static function domain(?string $domain): ?string
    {
        $domain = trim((string) $domain);

        return $domain === '' ? null : strtolower($domain);
    }
}
