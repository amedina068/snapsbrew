<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The changes to the company itself. Only an owner of the company can make them.
 *
 * The content of the company (templates, snaps, collections, forms, tags,
 * media) is shared. Every user of the company can change it, and the
 * TemplatePolicy and the controllers govern it.
 */
class CompanyPolicy
{
    public const DENIED = 'Only a company owner can do this.';

    /** Rename the company. */
    public function update(User $user, Company $company): Response
    {
        return self::owner($user, $company);
    }

    /** Create, copy and cancel invitations. */
    public function manageInvitations(User $user, Company $company): Response
    {
        return self::owner($user, $company);
    }

    /** Rotate the webhook signing secret. Receivers reject the next webhook until they get the new secret. */
    public function rotateWebhookSecret(User $user, Company $company): Response
    {
        return self::owner($user, $company);
    }

    /** Change the role of a user in the company, or remove a user from the company. */
    public function manageMembers(User $user, Company $company): Response
    {
        return self::owner($user, $company);
    }

    private static function owner(User $user, Company $company): Response
    {
        return $user->company_id !== null
            && (int) $user->company_id === (int) $company->id
            && $user->isCompanyOwner()
                ? Response::allow()
                : Response::deny(self::DENIED);
    }
}
