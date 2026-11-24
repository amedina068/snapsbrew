<?php

namespace Tests\Feature;

use App\Rules\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SafeUrl trusts the host of APP_URL without a DNS check, because a self-hosted
 * instance often runs on a private address. The trust is for the origin of
 * APP_URL only: the same scheme, host and port. Another port on the same host
 * can be an internal service.
 */
class SafeUrlFirstPartyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `.invalid` never resolves, so only the first-party rule can pass it.
        config(['app.url' => 'https://snapsbrew.invalid']);
    }

    public static function firstPartyOrigin(): array
    {
        return [
            'no port' => ['https://snapsbrew.invalid/storage/a.png'],
            'default port written out' => ['https://snapsbrew.invalid:443/storage/a.png'],
            'host in capitals' => ['https://SNAPSBREW.invalid/storage/a.png'],
        ];
    }

    #[DataProvider('firstPartyOrigin')]
    public function test_it_allows_the_origin_of_the_app_url(string $url)
    {
        $this->assertTrue((new SafeUrl)->passes('url', $url));
    }

    public static function otherOrigins(): array
    {
        return [
            'another port' => ['https://snapsbrew.invalid:6379/'],
            'another port on http' => ['http://snapsbrew.invalid:8080/'],
            'another scheme' => ['http://snapsbrew.invalid/'],
        ];
    }

    #[DataProvider('otherOrigins')]
    public function test_it_checks_another_port_or_scheme_of_the_app_host_like_any_host(string $url)
    {
        $this->assertFalse((new SafeUrl)->passes('url', $url));
    }

    public function test_an_app_url_with_a_port_allows_only_that_port()
    {
        config(['app.url' => 'http://snapsbrew.invalid:8080']);

        $this->assertTrue((new SafeUrl)->passes('url', 'http://snapsbrew.invalid:8080/a.png'));
        $this->assertFalse((new SafeUrl)->passes('url', 'http://snapsbrew.invalid/a.png'));
        $this->assertFalse((new SafeUrl)->passes('url', 'http://snapsbrew.invalid:9000/a.png'));
    }
}
