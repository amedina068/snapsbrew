<?php

namespace App\Support;

use App\Models\ApiKey;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The valid signed URL keys of one company, with a short cache.
 *
 * A template belongs to its company, so any active key of the company signs
 * its URLs. A key of a deactivated user does not sign.
 *
 * The signed URL middleware checks the signature on each request that does
 * not hit the snap cache. Without this cache, each of these requests loads the
 * keys and their access tokens from the database, also a request with a bad
 * signature.
 *
 * The cache holds the encrypted key column, never the plain key. It is the same
 * ciphertext that the database holds. Every change to a key, to its access
 * token or to the activation of its user clears the entry of the company. The TTL is a backstop for a change that
 * does not go through a model, for example a manual SQL update.
 */
class SignedUrlKeys
{
    /**
     * @return array{id: int, token_id: int, user_id: int}|null The key that
     *                                                          signed the fields,
     *                                                          or null when no
     *                                                          valid key did.
     */
    public static function match(int $companyId, string $fields, string $signature): ?array
    {
        foreach (self::forCompany($companyId) as $entry) {
            $expected = hash_hmac('sha256', $fields, decrypt($entry['key']));

            if (hash_equals($expected, $signature)) {
                return ['id' => $entry['id'], 'token_id' => $entry['token_id'], 'user_id' => $entry['user_id']];
            }
        }

        return null;
    }

    public static function forget(?int $companyId): void
    {
        if ($companyId) {
            Cache::forget(self::cacheKey($companyId));
        }
    }

    /**
     * Clear the cache of every company whose signed URL key uses this token.
     */
    public static function forgetToken(PersonalAccessToken $token): void
    {
        ApiKey::query()
            ->where('token_id', $token->getKey())
            ->pluck('company_id')
            ->unique()
            ->each(fn ($companyId) => self::forget((int) $companyId));
    }

    /**
     * The "company" segment keeps these entries apart from the entries of the
     * old cache, which a user ID keyed.
     */
    public static function cacheKey(int $companyId): string
    {
        return 'signed-url-keys:company:'.$companyId;
    }

    /**
     * @return array<int, array{id: int, token_id: int, user_id: int, key: string}>
     */
    private static function forCompany(int $companyId): array
    {
        $seconds = (int) config('snapsbrew.signed_url_key_cache_seconds', 60);

        return Cache::remember(self::cacheKey($companyId), $seconds, function () use ($companyId) {
            return ApiKey::query()
                ->signedUrl()
                ->with(['accessToken'])
                ->where('company_id', $companyId)
                ->whereNull('invalidated_at')
                ->whereHas('user', fn ($user) => $user->whereNull('deactivated_at'))
                ->get()
                ->filter(fn (ApiKey $apiKey) => $apiKey->accessToken && $apiKey->accessToken->can('signed_url'))
                ->map(fn (ApiKey $apiKey) => [
                    'id' => $apiKey->id,
                    'token_id' => $apiKey->token_id,
                    'user_id' => $apiKey->user_id,
                    'key' => $apiKey->getRawOriginal('key'),
                ])
                ->values()
                ->all();
        });
    }
}
