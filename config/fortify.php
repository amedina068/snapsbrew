<?php

use App\Http\Middleware\ThrottleAuthRequests;
use App\Providers\RouteServiceProvider;
use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    // `ThrottleAuthRequests` gives a limiter to the routes that Fortify leaves
    // open: the reset link, the reset, the sign-up, the password confirmation
    // and the password change.
    'middleware' => ['web', ThrottleAuthRequests::class],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => RouteServiceProvider::HOME,
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => false,
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],
    'paths' => [
        'login' => null,
        'logout' => null,
        'password' => [
            'request' => null,
            'reset' => null,
            'email' => null,
            'update' => null,
            'confirm' => null,
            'confirmation' => null,
        ],
        'register' => null,
        'verification' => [
            'notice' => null,
            'verify' => null,
            'send' => null,
        ],
        'user-profile-information' => [
            'update' => null,
        ],
        'user-password' => [
            'update' => null,
        ],
        'two-factor' => [
            'login' => null,
            'enable' => null,
            'confirm' => null,
            'disable' => null,
            'qr-code' => null,
            'secret-key' => null,
            'recovery-codes' => null,
        ],
    ],
    'redirects' => [
        'login' => null,
        'logout' => null,
        'password-confirmation' => null,
        'register' => null,
        'email-verification' => null,
        'password-reset' => null,
    ],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
