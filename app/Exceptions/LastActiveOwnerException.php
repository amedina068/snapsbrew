<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * A change would leave a company that has active users with no active owner.
 * `CompanyOwners` throws it. Each caller turns it into its own response.
 */
class LastActiveOwnerException extends RuntimeException
{
    public const MESSAGE = 'The company must keep at least one active owner. Make another active user an owner first.';

    public function __construct(public readonly User $user)
    {
        parent::__construct(self::MESSAGE);
    }
}
