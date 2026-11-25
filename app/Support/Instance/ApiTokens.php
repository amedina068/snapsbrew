<?php

namespace App\Support\Instance;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Creates, lists and revokes personal access tokens outside the web UI.
 *
 * A product token is the same token that Account > Developers creates: a
 * Sanctum token with the `api_requests` ability. It opens the REST API and
 * the `/mcp` server.
 *
 * An instance-admin token carries only `instance_admin`. It opens `/mcp/admin`
 * when `MCP_ADMIN_ENABLED=true`. Only an owner can hold one, and it expires
 * in 7 days or less. Only the CLI creates it, never an MCP tool.
 */
class ApiTokens
{
    /** The abilities a product token can carry. */
    public const PRODUCT_ABILITIES = [EnsureMcpAccess::SANCTUM_ABILITY];

    public const ADMIN_ABILITY = 'instance_admin';

    public const ADMIN_MAX_DAYS = 7;

    public const ADMIN_DEFAULT_DAYS = 1;

    /**
     * @param  list<string>  $abilities
     *
     * @throws InvalidArgumentException when the abilities or the expiry break a rule
     */
    public static function create(User $user, string $name, array $abilities = self::PRODUCT_ABILITIES, ?CarbonImmutable $expiresAt = null): NewAccessToken
    {
        $name = trim($name);
        $abilities = array_values(array_unique(array_filter(array_map('trim', $abilities))));

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('The token name must have 1 to 255 characters.');
        }

        if ($abilities === []) {
            throw new InvalidArgumentException('Give at least one ability.');
        }

        $allowed = [...self::PRODUCT_ABILITIES, self::ADMIN_ABILITY];
        if ($unknown = array_diff($abilities, $allowed)) {
            throw new InvalidArgumentException('Unknown ability: '.implode(', ', $unknown).'. Use one of: '.implode(', ', $allowed).'.');
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new InvalidArgumentException('The expiry must be in the future.');
        }

        if (in_array(self::ADMIN_ABILITY, $abilities, true)) {
            if (count($abilities) > 1) {
                throw new InvalidArgumentException('An instance_admin token cannot carry other abilities. Create a second token for the API.');
            }

            if (! $user->isOwner()) {
                throw new InvalidArgumentException("Only an owner can hold an instance_admin token. {$user->email} is not an owner.");
            }

            $expiresAt ??= CarbonImmutable::now()->addDays(self::ADMIN_DEFAULT_DAYS);

            if ($expiresAt->greaterThan(CarbonImmutable::now()->addDays(self::ADMIN_MAX_DAYS)->addMinute())) {
                throw new InvalidArgumentException('An instance_admin token must expire in '.self::ADMIN_MAX_DAYS.' days or less.');
            }
        }

        return $user->createToken($name, $abilities, $expiresAt);
    }

    /**
     * Reads `never`, a duration (`30d`, `12h`, `45m`) or a date.
     *
     * @throws InvalidArgumentException
     */
    public static function parseExpiry(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '' || strtolower($value) === 'never') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([dhm])$/i', $value, $match)) {
            $amount = (int) $match[1];

            return match (strtolower($match[2])) {
                'd' => CarbonImmutable::now()->addDays($amount),
                'h' => CarbonImmutable::now()->addHours($amount),
                default => CarbonImmutable::now()->addMinutes($amount),
            };
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException("The expiry \"{$value}\" is not valid. Use never, a duration like 30d, 12h or 45m, or a date.");
        }
    }

    /**
     * The public fields of a token. Never the hash.
     *
     * @return array<string, mixed>
     */
    public static function describe(PersonalAccessToken $token): array
    {
        $owner = $token->tokenable;

        return [
            'id' => $token->id,
            'name' => $token->name,
            'user' => $owner instanceof User ? ['id' => $owner->id, 'email' => $owner->email] : null,
            'abilities' => array_values((array) $token->abilities),
            'last_used_at' => self::date($token->last_used_at),
            'expires_at' => self::date($token->expires_at),
            'created_at' => self::date($token->created_at),
        ];
    }

    /**
     * Deletes the token through the model, so the `deleted` event clears the
     * signed URL key cache. Returns false when no token has this id.
     */
    public static function revoke(int $id): bool
    {
        $token = PersonalAccessToken::query()->find($id);

        if (! $token) {
            return false;
        }

        $token->delete();

        return true;
    }

    private static function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
