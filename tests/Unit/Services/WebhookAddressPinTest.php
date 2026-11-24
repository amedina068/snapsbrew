<?php

namespace Tests\Unit\Services;

use App\Services\PublicEditorAssetUrlResolver;
use App\Services\WebhookSender;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * `SafeUrl` checks a webhook address when the client sends it. The webhook
 * goes out later, from a queue job, and the host can resolve to another
 * address by then. The sender must check the address again at send time and
 * connect to the address that it checked.
 *
 * Every host here is an IP literal or `localhost`, so the tests make no DNS
 * query to the network.
 */
class WebhookAddressPinTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function internalUrls(): array
    {
        return [
            'a host that resolves to loopback' => ['http://localhost:8080/hook'],
            'loopback literal' => ['http://127.0.0.1/hook'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'shared address space' => ['http://100.64.0.1/hook'],
            'non-http scheme' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('internalUrls')]
    public function test_the_sender_refuses_an_internal_address_at_send_time(string $url): void
    {
        Http::fake();

        $error = WebhookSender::deliver($url, ['event' => 'snap.completed'], 'secret');

        $this->assertSame('webhook_address_refused', $error);
        Http::assertNothingSent();
    }

    public function test_the_sender_connects_to_the_address_that_it_checked(): void
    {
        $resolve = [];
        Http::fake(function ($request, array $options) use (&$resolve) {
            $resolve[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return Http::response(null, 204);
        });

        $error = WebhookSender::deliver('https://93.184.216.34:8443/hook', ['event' => 'snap.completed'], 'secret');

        $this->assertNull($error);
        $this->assertSame([['93.184.216.34:8443:93.184.216.34']], $resolve);
    }

    public function test_every_attempt_connects_to_the_same_checked_address(): void
    {
        $resolve = [];
        Http::fake(function ($request, array $options) use (&$resolve) {
            $resolve[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return Http::response(null, 500);
        });

        WebhookSender::deliver('http://93.184.216.34/hook', ['event' => 'snap.completed'], 'secret');

        $this->assertSame(array_fill(0, WebhookSender::MAX_ATTEMPTS, ['93.184.216.34:80:93.184.216.34']), $resolve);
    }

    public function test_the_editor_asset_resolver_refuses_the_shared_address_space(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asset_url_rejected');

        (new PublicEditorAssetUrlResolver)->resolve('http://100.64.0.1/image.png');
    }
}
