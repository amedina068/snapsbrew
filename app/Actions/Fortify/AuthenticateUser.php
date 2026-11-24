<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * The login check of Fortify, plus one rule: a deactivated user cannot log in.
 *
 * The check runs only after the password matches. A wrong password gets the
 * usual Fortify message, so the form does not tell a stranger which accounts
 * are deactivated. The Timebox gives each attempt the same minimum duration,
 * as the default Fortify check does.
 */
class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $provider = Auth::guard(config('fortify.guard'))->getProvider();
        $credentials = $request->only(Fortify::username(), 'password');

        return (new Timebox)->call(function () use ($provider, $credentials) {
            $user = $provider->retrieveByCredentials([Fortify::username() => $credentials[Fortify::username()] ?? null]);

            if (! $user || ! $provider->validateCredentials($user, ['password' => $credentials['password'] ?? ''])) {
                return null;
            }

            if ($user instanceof User && $user->isDeactivated()) {
                throw ValidationException::withMessages([
                    Fortify::username() => User::DEACTIVATED_MESSAGE,
                ]);
            }

            if (config('hashing.rehash_on_login', true)) {
                $provider->rehashPasswordIfRequired($user, ['password' => $credentials['password']]);
            }

            return $user;
        }, 200000);
    }
}
