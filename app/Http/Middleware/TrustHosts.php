<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Support\Facades\URL;

/**
 * Accepts only the host of APP_URL, the hosts of APP_PREVIOUS_URLS, the host of
 * RENDER_ASSET_URL and the loopback hosts.
 *
 * Laravel builds absolute URLs, for example the password reset link, from the
 * Host header of the request. A client sets that header, so without this list
 * the reset mail can send the token to a host that the client owns. A request
 * for another host gets a 400. The root URL is also forced to APP_URL, so a
 * link never names the host of the request.
 *
 * The container health check calls `http://localhost/up`, so the loopback
 * hosts stay trusted. A link cannot name them, because the root URL is forced.
 */
class TrustHosts extends Middleware
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string>
     */
    public function hosts()
    {
        // The render browser loads the assets of a snap from RENDER_ASSET_URL,
        // for example http://web:8080 from browserless in Docker Compose.
        $urls = [config('app.url'), ...config('app.previous_urls', []), config('services.render.asset_url')];

        return collect($urls)
            ->map(fn ($url) => parse_url((string) $url, PHP_URL_HOST))
            ->filter()
            ->merge(self::LOOPBACK_HOSTS)
            ->unique()
            ->map(fn (string $host) => '^'.preg_quote(strtolower($host)).'$')
            ->values()
            ->all();
    }

    public function handle($request, $next)
    {
        // A previous host is trusted, so its requests reach the app. The
        // links that the app builds must still name APP_URL.
        $root = rtrim((string) config('app.url'), '/');
        URL::forceRootUrl($root);

        // The generator takes the scheme from the request, not from the
        // forced root, so force the scheme of APP_URL too.
        if ($scheme = parse_url($root, PHP_URL_SCHEME)) {
            URL::forceScheme($scheme);
        }

        return parent::handle($request, $next);
    }

    /**
     * The framework skips the check in tests. The suite runs it, so a test
     * proves the list. A local environment still accepts any host.
     */
    protected function shouldSpecifyTrustedHosts()
    {
        return ! $this->app->environment('local');
    }
}
