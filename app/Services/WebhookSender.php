<?php

namespace App\Services;

use App\Rules\SafeUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts one signed webhook payload with three attempts and a linear backoff.
 *
 * The signature is the HMAC-SHA256 of "{timestamp}.{body}" with the secret of
 * the company. The timestamp lets a receiver reject a replayed request. All
 * attempts of one delivery carry the same delivery ID, so a receiver can
 * ignore a duplicate.
 *
 * The client does not follow a redirect. A customer controls the receiver,
 * and a redirect to an internal address made the app call that address. The
 * result is a fixed code, never the response body or the exception message,
 * because the caller stores it where the customer reads it.
 *
 * `SafeUrl` checked the address when the client sent it, but the host can
 * resolve to another address when the job runs. So the sender checks the
 * address again before the first attempt, and every attempt connects to the
 * address that it checked.
 */
final class WebhookSender
{
    public const MAX_ATTEMPTS = 3;

    public const RETRY_BACKOFF_MICROSECONDS = 200_000;

    public const ERROR_REDIRECT = 'webhook_redirect_refused';

    public const ERROR_UNREACHABLE = 'webhook_unreachable';

    public const ERROR_ADDRESS_REFUSED = 'webhook_address_refused';

    /**
     * @return string|null null when the receiver answered 2xx, or the code of the last failure
     */
    public static function deliver(string $url, array $payload, string $secret): ?string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        $headers = [
            'X-Snapsbrew-Delivery' => (string) Str::uuid(),
            'X-Snapsbrew-Timestamp' => $timestamp,
            'X-Snapsbrew-Signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
        ];

        $destination = SafeUrl::destination($url);

        if ($destination === null) {
            return self::ERROR_ADDRESS_REFUSED;
        }

        $options = self::pinnedOptions($destination);
        $error = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $error = self::attempt($url, $body, $headers, $options);

            if ($error === null) {
                return null;
            }

            if ($attempt < self::MAX_ATTEMPTS && ! app()->runningUnitTests()) {
                usleep(self::RETRY_BACKOFF_MICROSECONDS * $attempt);
            }
        }

        return $error;
    }

    /**
     * Make curl connect to the checked address. The TLS check still reads
     * the host name of the URL.
     *
     * @param  array{host: string, port: int, ip: string|null}  $destination
     * @return array<string, mixed>
     */
    private static function pinnedOptions(array $destination): array
    {
        if ($destination['ip'] === null) {
            return [];
        }

        $ip = str_contains($destination['ip'], ':') ? "[{$destination['ip']}]" : $destination['ip'];
        $host = trim($destination['host'], '[]');

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$destination['port']}:{$ip}"]]];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function attempt(string $url, string $body, array $headers, array $options): ?string
    {
        try {
            $response = Http::withoutRedirecting()
                ->withOptions($options)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (Throwable) {
            return self::ERROR_UNREACHABLE;
        }

        if ($response->successful()) {
            return null;
        }

        return $response->redirect() ? self::ERROR_REDIRECT : 'webhook_http_'.$response->status();
    }
}
