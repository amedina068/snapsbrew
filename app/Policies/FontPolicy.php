<?php

namespace App\Policies;

use App\Models\Company;
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
     * Each active user of the company, owner or member, can upload a font.
     * Fonts are a shared asset of the company, like templates. A new font
     * changes no template, so a member cannot break the work of another user.
     */
    public function create(User $user, ?Company $company): bool
    {
        return $company !== null
            && $user->company_id !== null
            && (int) $user->company_id === (int) $company->id
            && ! $user->isDeactivated();
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
