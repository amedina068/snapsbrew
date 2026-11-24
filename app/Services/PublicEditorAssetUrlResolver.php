<?php

namespace App\Services;

use App\Contracts\EditorAssetUrlResolver;
use App\Rules\SafeUrl;
use RuntimeException;

class PublicEditorAssetUrlResolver implements EditorAssetUrlResolver
{
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! is_string($host) || $host === '' || isset($parts['user'], $parts['pass'])
            || ! in_array($scheme, ['http', 'https'], true) || ! in_array($port, [80, 443], true)) {
            throw new RuntimeException('asset_url_rejected');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        $ip = $ips[0] ?? null;
        // The same address check as `SafeUrl`. The private and reserved flags
        // alone accept the shared range 100.64.0.0/10 and other non-global ranges.
        if (! is_string($ip) || ! SafeUrl::isPublicIp($ip)) {
            throw new RuntimeException('asset_url_rejected');
        }

        return ['host' => $host, 'port' => (int) $port, 'ip' => $ip];
    }
}
