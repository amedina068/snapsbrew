<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\CompanyInvitation;
use App\Support\Registration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(new AuthenticateUser);

        Fortify::loginView(function () {
            return view('auth.login');
        });

        Fortify::registerView(function (Request $request) {
            $invitation = null;

            if ($token = $request->query('invitation')) {
                $invitation = CompanyInvitation::query()
                    ->pending()
                    ->where('token', $token)
                    ->first();
            }

            // In invite-only mode, only invited people see the form.
            abort_if(! $invitation && ! Registration::isOpen(), 404);

            return view('auth.register', [
                'invitation' => $invitation,
            ]);
        });

        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });

        Fortify::resetPasswordView(function ($request) {
            return view('auth.reset-password', [
                'request' => $request,
            ]);
        });

        Fortify::verifyEmailView(function ($request) {
            return view('auth.verify-email', [
                'request' => $request,
            ]);
        });

        Fortify::confirmPasswordView(function () {
            return view('auth.confirm-password');
        });

        Fortify::twoFactorChallengeView(function () {
            return view('auth.two-factor-challenge');
        });

        // Two limits. The email plus IP limit stops one client. The email limit
        // stops many IPs that try one account, because a client can change its
        // IP and a wrong proxy setting lets it choose one.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(10)->by('login-email:'.$email),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // `ThrottleAuthRequests` applies the limiters below to the Fortify
        // routes that have no limiter of their own.
        //
        // A reset link request sends a mail. The IP limit stops one client.
        // The email limit stops many IPs that send mails to one inbox.
        RateLimiter::for('password-reset-link', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::email())));

            return [
                Limit::perMinute(5)->by('password-reset-link:ip:'.$request->ip()),
                Limit::perHour(5)->by('password-reset-link:email:'.$email),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by('password-reset:ip:'.$request->ip());
        });

        // A sign-up creates a company. Open registration is a mode that the
        // owner turns on, so one IP gets a few accounts.
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(5)->by('register:minute:'.$request->ip()),
                Limit::perDay(20)->by('register:day:'.$request->ip()),
            ];
        });

        // These two routes take the password of a signed-in user. The limit is
        // per user, so a stolen session cannot guess the password from many IPs.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(5)->by('password-confirm:'.$request->user()?->getAuthIdentifier());
        });

        RateLimiter::for('password-change', function (Request $request) {
            return Limit::perMinute(5)->by('password-change:'.$request->user()?->getAuthIdentifier());
        });
    }
}
