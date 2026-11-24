<?php

namespace App\Support;

use App\Models\User;

/**
 * The first-run state of a self-hosted instance.
 *
 * An instance needs setup while the users table has no rows. The setup token
 * comes from APP_KEY, so the instance stores nothing and the token changes
 * when the key changes.
 */
class InstanceSetup
{
    public static function isNeeded(): bool
    {
        return ! User::query()->exists();
    }

    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'snapsbrew-setup', (string) config('app.key')), 0, 32);
    }

    public static function tokenMatches(?string $token): bool
    {
        return is_string($token) && hash_equals(self::token(), $token);
    }

    public static function url(): string
    {
        return url('/setup?token='.self::token());
    }
}
