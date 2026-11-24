<?php

namespace App\Policies;

use App\Models\Font;
use App\Models\User;

class FontPolicy
{
    /** A font is only ever visible to, or mutable by, its own company. */
    public function view(User $user, Font $font): bool
    {
        return $user->company_id === $font->company_id;
    }

    public function delete(User $user, Font $font): bool
    {
        return $user->company_id === $font->company_id;
    }
}
