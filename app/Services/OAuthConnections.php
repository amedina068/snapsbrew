<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * The OAuth applications that one user authorized, and the revoke of one.
 *
 * Passport keeps no connection row. A connection is the set of access tokens
 * that one user holds for one client. It is live while one of those tokens is
 * not revoked and can still be used: the access token is not expired, or its
 * refresh token is not revoked and not expired.
 *
 * Passport records no last use of a token. A refresh issues a new access
 * token, so the newest `created_at` is the last time the connector got a token.
 */
class OAuthConnections
{
    /**
     * @return Collection<int, object{client_id: string, client_name: string, connected_at: Carbon, last_token_at: Carbon}>
     */
    public function forUser(User $user): Collection
    {
        return $this->liveTokens($user)
            ->with('client:id,name')
            ->get(['id', 'client_id', 'created_at'])
            ->groupBy('client_id')
            ->map(fn (Collection $tokens, string $clientId) => (object) [
                'client_id' => $clientId,
                'client_name' => $tokens->first()->client?->name ?? 'Unknown application',
                'connected_at' => $tokens->min('created_at'),
                'last_token_at' => $tokens->max('created_at'),
            ])
            ->sortByDesc('last_token_at')
            ->values();
    }

    /**
     * Revoke every access token, refresh token and pending authorization code
     * that the user holds for the client.
     *
     * Returns false when the user has no live token for the client, so the
     * caller can answer 404 and not reveal a client of another user.
     */
    public function revoke(User $user, string $clientId): bool
    {
        if (! $this->liveTokens($user)->where('client_id', $clientId)->exists()) {
            return false;
        }

        DB::transaction(function () use ($user, $clientId) {
            $tokenIds = Passport::token()->newQuery()
                ->where('user_id', $user->id)
                ->where('client_id', $clientId)
                ->pluck('id');

            Passport::token()->newQuery()->whereIn('id', $tokenIds)->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            Passport::authCode()->newQuery()
                ->where('user_id', $user->id)
                ->where('client_id', $clientId)
                ->update(['revoked' => true]);
        });

        return true;
    }

    private function liveTokens(User $user): Builder
    {
        $now = now();

        return Passport::token()->newQuery()
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->where(function (Builder $query) use ($now) {
                $query->where('expires_at', '>', $now)
                    ->orWhereHas('refreshToken', fn (Builder $refresh) => $refresh
                        ->where('revoked', false)
                        ->where('expires_at', '>', $now));
            });
    }
}
