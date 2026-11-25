<?php

namespace App\Policies;

use App\Models\Font;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FontPolicy
{
    /** A font is only ever visible to, or mutable by, its own company. */
    public function view(User $user, Font $font): bool
    {
        return $user->company_id === $font->company_id;
    }

    /**
     * Only an owner of the company can delete a font. Templates that use the
     * font fall back to a default font, so the change affects every member.
     */
    public function delete(User $user, Font $font): Response
    {
        if ($user->company_id !== $font->company_id) {
            return Response::deny();
        }

        return $user->isCompanyOwner() ? Response::allow() : Response::deny(CompanyPolicy::DENIED);
    }
}
