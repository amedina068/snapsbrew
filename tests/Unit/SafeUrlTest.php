<?php

namespace Tests\Unit;

use App\Rules\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeUrlTest extends TestCase
{
    #[DataProvider('blocked')]
    public function test_it_blocks_unsafe_urls(string $url)
    {
        $this->assertFalse((new SafeUrl)->passes('url', $url), "expected blocked: {$url}");
    }

    #[DataProvider('allowed')]
    public function test_it_allows_safe_public_urls(string $url)
    {
        $this->assertTrue((new SafeUrl)->passes('url', $url), "expected allowed: {$url}");
    }

    public static function blocked(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'no scheme' => ['example.com/foo'],
            'garbage' => ['definitely not a url'],
            'loopback v4' => ['http://127.0.0.1/'],
            'loopback name' => ['http://localhost/'],
            'link-local / metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 172.16/12' => ['http://172.16.0.1/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            // userinfo before "@" must not be mistaken for the host (this test
            // has no booted app, so the app host is unset and not first-party).
            'userinfo trick' => ['http://snapsbrew.test@127.0.0.1/'],
            // Ranges that NO_PRIV_RANGE and NO_RES_RANGE do not cover.
            'carrier-grade nat 100.64/10' => ['http://100.64.0.1/'],
            'carrier-grade nat top' => ['http://100.127.255.254/'],
            'benchmark 198.18/15' => ['http://198.18.0.1/'],
            'ietf protocol 192.0.0/24' => ['http://192.0.0.8/'],
            'nat64 well-known prefix to loopback' => ['http://[64:ff9b::7f00:1]/'],
            'nat64 well-known prefix to public' => ['http://[64:ff9b::808:808]/'],
            'nat64 local-use prefix' => ['http://[64:ff9b:1::a00:5]/'],
            '6to4 to loopback' => ['http://[2002:7f00:1::]/'],
            '6to4 to private' => ['http://[2002:c0a8:101::1]/'],
            'teredo' => ['http://[2001::1]/'],
        ];
    }

    public static function allowed(): array
    {
        // Public IP literals keep the test deterministic (no DNS lookups).
        return [
            'public ipv4 http' => ['http://93.184.216.34/'],
            'public ipv4 https with path' => ['https://93.184.216.34/page?x=1'],
            'public ipv6' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/'],
        ];
    }
}
