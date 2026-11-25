<?php

namespace App\Providers;

use App\Contracts\EditorAssetTransport;
use App\Contracts\EditorAssetUrlResolver;
use App\Contracts\PageScreenshotter;
use App\Contracts\SnapRenderer;
use App\Rendering\RenderWarnings;
use App\Services\BrowserlessRenderer;
use App\Services\BrowsershotRenderer;
use App\Services\CurlEditorAssetTransport;
use App\Services\PublicEditorAssetUrlResolver;
use App\Support\SignedUrlKeys;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $renderer = function () {
            return config('services.render.driver') === 'browserless'
                ? new BrowserlessRenderer
                : new BrowsershotRenderer;
        };
        $this->app->bind(SnapRenderer::class, $renderer);
        $this->app->bind(PageScreenshotter::class, $renderer);
        $this->app->scoped(RenderWarnings::class);
        $this->app->bind(EditorAssetTransport::class, CurlEditorAssetTransport::class);
        $this->app->bind(EditorAssetUrlResolver::class, PublicEditorAssetUrlResolver::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Laravel 13 no longer sends a guest to the login route by default.
        // Passport throws this exception from the OAuth consent screen.
        AuthenticationException::redirectUsing(fn () => route('login'));

        // A signed URL key stops working when its access token is deleted. The
        // signed URL middleware caches the valid keys, so clear that cache.
        // Do not listen to `updated`: Sanctum updates `last_used_at` on every
        // API request.
        PersonalAccessToken::deleted(fn (PersonalAccessToken $token) => SignedUrlKeys::forgetToken($token));
    }
}
