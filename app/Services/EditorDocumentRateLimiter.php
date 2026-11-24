<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/** Shared MCP throttle: REST uses RouteServiceProvider's equivalent limits. */
class EditorDocumentRateLimiter
{
    public function attempt(User $user): bool
    {
        $keys = [
            'editor-document:mcp:subject:'.$user->id => 30,
            'editor-document:mcp:company:'.$user->company_id => 180,
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }
        }

        foreach ($keys as $key => $max) {
            RateLimiter::hit($key, 60);
        }

        return true;
    }
}
