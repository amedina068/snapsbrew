<?php

namespace App\Support\Instance;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A one-time login URL for a user, made from the CLI.
 *
 * `snapsbrew:install` returns one, so that an agent can give the owner a link
 * that signs in with no password. The URL has a signature, an expiry and a
 * random nonce. The cache holds the nonce until the first visit, and the visit
 * removes it, so the URL works one time.
 *
 * The signature is relative. The CLI signs with APP_URL, and the person can
 * open the same path on another host name (localhost or 127.0.0.1).
 */
class LoginLink
{
    public const MINUTES = 15;

    private const CACHE_PREFIX = 'snapsbrew:login-link:';

    public static function create(User $user, int $minutes = self::MINUTES): string
    {
        $nonce = Str::random(40);

        Cache::put(self::CACHE_PREFIX.$nonce, $user->id, now()->addMinutes($minutes));

        $path = URL::temporarySignedRoute(
            'login.link',
            now()->addMinutes($minutes),
            ['user' => $user->id, 'nonce' => $nonce],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Returns the user when the nonce is valid for this user, and removes the
     * nonce. A second call with the same nonce returns null.
     */
    public static function consume(int $userId, string $nonce): ?User
    {
        $stored = Cache::pull(self::CACHE_PREFIX.$nonce);

        if ((int) $stored !== $userId) {
            return null;
        }

        return User::query()->find($userId);
    }
}
