<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class TemplatePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->actsAsCustomer();
    }

    /**
     * Determine whether the user can view the model.
     *
     * The rule is the company, the same rule `update` and `delete` read.
     *
     * One editor document carries a read gate and a write gate. The write is a
     * compare-and-swap, and it needs the `revision` that only the read returns.
     * If the two gates name different people, a company peer can write the
     * document and cannot get the value that makes the write safe. This gate
     * read the individual account while the write gate read the company, so
     * that peer wrote blind.
     *
     * The null-owner clause stays. It covers the seeded preset catalog, which
     * carries no `user_id` and no `company_id`. No caller of this gate reaches
     * such a row today. Every caller scopes its query by `company_id` first,
     * and a null `company_id` never matches a company.
     *
     * @return Response|bool
     */
    public function view(User $user, Template $template)
    {
        return $user->actsAsCustomer()
            && (self::sameCompany($user, $template) || ! $template->user_id);
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return $user->actsAsCustomer();
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, Template $template)
    {
        // Templates are company-shared: anyone acting as a customer in the
        // owning company may edit. Cross-company access is blocked upstream —
        // controllers scope lookups by company_id (404 before this runs).
        return $user->actsAsCustomer() && self::sameCompany($user, $template);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, Template $template)
    {
        // Same company-shared rule as update, for consistency.
        return $user->actsAsCustomer() && self::sameCompany($user, $template);
    }

    /**
     * Report whether the user and the template belong to one real company.
     *
     * The comparison is strict and it rejects a null on either side. A loose
     * `==` reads two nulls as a match, so a user with no company reached every
     * template with no company, including the private template of another
     * user with no company. No signup path writes a null company today, and a
     * gate that depends on that fact is a gate that one migration can open.
     *
     * The column is an integer and the attribute can arrive as a string from a
     * route or a form, so the cast keeps `3` and `"3"` the same company.
     */
    private static function sameCompany(User $user, Template $template): bool
    {
        if ($user->company_id === null || $template->company_id === null) {
            return false;
        }

        return (int) $user->company_id === (int) $template->company_id;
    }
}
