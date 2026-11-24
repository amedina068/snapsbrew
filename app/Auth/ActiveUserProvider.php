<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The Eloquent user provider, without deactivated users.
 *
 * The session guard (session and remember-me cookie) and the Passport guard
 * (OAuth tokens of the MCP server) find their user through this provider. A
 * deactivated user is not found, so these guards treat the request as a guest.
 *
 * `retrieveByCredentials()` keeps the deactivated user. The login form needs
 * it to tell the person why the login failed (`AuthenticateUser`).
 */
class ActiveUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->onlyActive(parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return $this->onlyActive(parent::retrieveByToken($identifier, $token));
    }

    private function onlyActive(?Authenticatable $user): ?Authenticatable
    {
        if ($user instanceof User && $user->isDeactivated()) {
            return null;
        }

        return $user;
    }
}
