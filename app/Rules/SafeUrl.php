<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Blocks SSRF / local-file vectors before a URL is handed to Browsershot.
 *
 * Accepts only http(s) URLs whose host resolves entirely to global IP
 * addresses. Rejects other schemes (file://, gopher://, …) and any host that
 * resolves to a private (RFC1918), loopback, link-local (incl. the cloud
 * metadata 169.254.169.254), shared (100.64.0.0/10), NAT64, 6to4 or otherwise
 * reserved range.
 *
 * This is a guard, not full DNS-rebinding protection — Browsershot resolves the
 * host again at fetch time. It closes the trivial vectors; defense in depth
 * (egress firewall on the render host) is still warranted.
 */
class SafeUrl implements Rule
{
    public function passes($attribute, $value): bool
    {
        // Presence/empty is left to the `required` / `url` rules so this rule
        // composes cleanly with optional fields.
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        return self::destination($value) !== null;
    }

    /**
     * Resolve the host of a URL and check every address that it resolves to.
     *
     * The result names one checked address. A client that connects later must
     * connect to that address, not resolve the host again: the host can
     * resolve to another address by then. `ip` is null for the origin of
     * APP_URL, which is trusted without a DNS check.
     *
     * @return array{host: string, port: int, ip: string|null}|null null when the URL is not safe
     */
    public static function destination(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $destination = [
            'host' => (string) $parts['host'],
            'port' => self::port($parts, $scheme),
            'ip' => null,
        ];

        if (self::isFirstPartyOrigin($parts)) {
            return $destination;
        }

        $ips = self::resolveIps($parts['host']);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        $destination['ip'] = $ips[0];

        return $destination;
    }

    public function message(): string
    {
        return 'The :attribute must be a public http or https URL and may not point to an internal or reserved address.';
    }

    /**
     * @return array<int, string>
     */
    private static function resolveIps(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host);
        $ips = $ips === false ? [] : $ips;

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * The origin of APP_URL is trusted without a DNS check, because a
     * self-hosted instance often runs on a private address. Only the same
     * scheme, host and port pass. Another port on the same host can be an
     * internal service, so it gets the same checks as any other host.
     *
     * @param  array<string, mixed>  $parts
     */
    private static function isFirstPartyOrigin(array $parts): bool
    {
        if (! app()->bound('config')) {
            return false;
        }

        $app = parse_url((string) config('app.url'));

        if (! is_array($app) || empty($app['host']) || empty($app['scheme'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $appScheme = strtolower((string) $app['scheme']);

        return strcasecmp((string) $parts['host'], (string) $app['host']) === 0
            && $scheme === $appScheme
            && self::port($parts, $scheme) === self::port($app, $appScheme);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function port(array $parts, string $scheme): int
    {
        return (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * FILTER_FLAG_GLOBAL_RANGE also refuses the ranges that the private and
     * reserved flags miss, for example 100.64.0.0/10, 198.18.0.0/15,
     * 2002::/16 (6to4) and 2001::/23. It accepts the NAT64 prefixes, because
     * RFC 6890 calls them global. A NAT64 address reaches the IPv4 address
     * that it holds, so the list below refuses them.
     */
    private const TRANSLATED_RANGES = ['64:ff9b::/96', '64:ff9b:1::/48'];

    public static function isPublicIp(string $ip): bool
    {
        $public = (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        );

        return $public && ! IpUtils::checkIp($ip, self::TRANSLATED_RANGES);
    }
}
