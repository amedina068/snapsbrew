<?php

namespace App\Providers;

use App\Http\Controllers\HealthCheckController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::get('up', HealthCheckController::class);

            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/api.php'));

            Route::prefix('api')
                ->group(base_path('routes/api-embedded-form.php'));

            Route::prefix('api')
                ->group(base_path('routes/api-creative-package-media.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('preview', function (Request $request) {
            return Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip());
        });

        // The embedded form routes are public. A submit renders on the account
        // of the form owner. Each route has one limit per IP and one per form.
        RateLimiter::for('embedded-form-submit', function (Request $request) {
            $limits = config('snapsbrew.embedded_form_rate_limits');
            $form = (string) $request->route('formUuid');
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many submissions. Wait one minute, then send the form again.',
                'code' => 'rate_limited',
            ], 429, $headers);

            return [
                Limit::perMinute($limits['submits_per_ip'])->by('ip:'.$request->ip())->response($tooMany),
                Limit::perMinute($limits['submits_per_form'])->by('form:'.$form)->response($tooMany),
            ];
        });

        // The free tools take no token and each request starts a browser
        // render. One IP gets a few requests each minute and each day.
        RateLimiter::for('free-tools', function (Request $request) {
            $config = config('services.free_tools');

            return [
                Limit::perMinute($config['requests_per_minute_per_ip'])->by('free-tools:minute:'.$request->ip()),
                Limit::perDay($config['requests_per_day_per_ip'])->by('free-tools:day:'.$request->ip()),
            ];
        });

        // `POST /oauth/register` takes no authentication. A connector
        // registers one time, so one IP gets a few registrations.
        RateLimiter::for('oauth-register', function (Request $request) {
            return [
                Limit::perMinute(3)->by('oauth-register:minute:'.$request->ip()),
                Limit::perDay(20)->by('oauth-register:day:'.$request->ip()),
            ];
        });

        RateLimiter::for('signed-url', function (Request $request) {
            return Limit::perMinute(config('snapsbrew.signed_url_requests_per_ip'))->by('signed-url:'.$request->ip());
        });

        RateLimiter::for('embedded-form-view', function (Request $request) {
            $limits = config('snapsbrew.embedded_form_rate_limits');

            return [
                Limit::perMinute($limits['views_per_ip'])->by('ip:'.$request->ip()),
                Limit::perMinute($limits['views_per_form'])->by('form:'.$request->route('formUuid')),
            ];
        });

        RateLimiter::for('editor-document', function (Request $request) {
            $user = $request->user();
            $subject = $user?->id ?? $request->ip();
            $company = $user?->company_id ?? $request->ip();

            return [
                Limit::perMinute(30)->by('editor-document:subject:'.$subject),
                Limit::perMinute(180)->by('editor-document:company:'.$company),
            ];
        });
    }
}
