<?php

namespace Tests\Unit\Policies;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The three gates of `TemplatePolicy`, read at the policy and not over a route.
 *
 * `view` is tested here because one of its clauses has no reachable route.
 * `TemplateSeeder` writes a preset catalog with a null `user_id` and a null
 * `company_id`, and the null-owner clause keeps those rows visible. Every
 * caller of the gate scopes its query by `company_id` first, so a company
 * member reads 404 before the gate runs. The clause is therefore provable at
 * the policy only.
 *
 * The three route surfaces that call `view` are covered by
 * `TemplateDocumentApiTest` and `McpToolsTest`.
 */
class TemplatePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seeded preset catalog stays visible.
     *
     * `TemplateFactory::public()` builds the same row the seeder writes: no
     * `user_id` and no `company_id`.
     */
    public function test_a_catalog_template_with_no_owner_is_visible_to_any_customer(): void
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $catalog = Template::factory()->public()->create();

        $this->assertTrue(Gate::forUser($user)->allows('view', $catalog));
    }

    /**
     * `view` names the same people as `update` and `delete`.
     *
     * A write is a compare-and-swap and it needs the `revision` that only the
     * read returns, so the two gates must agree.
     */
    public function test_view_names_the_same_people_as_update_and_delete(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['company_id' => $company->id]);
        $peer = User::factory()->create(['company_id' => $company->id]);
        $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $template = Template::factory()->create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
        ]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($peer)->allows($ability, $template), "peer {$ability}");
            $this->assertFalse(Gate::forUser($stranger)->allows($ability, $template), "stranger {$ability}");
        }
    }

    /**
     * An account that cannot act as a customer reads nothing.
     *
     * `actsAsCustomer()` passes the customer role and the admin role, so a
     * third role is the only caller that reaches the denial branch.
     */
    public function test_a_company_member_that_cannot_act_as_a_customer_cannot_view(): void
    {
        $company = Company::factory()->create();
        $blocked = User::factory()->create(['company_id' => $company->id, 'role' => 'viewer']);
        $template = Template::factory()->create(['company_id' => $company->id]);
        $catalog = Template::factory()->public()->create();

        $this->assertFalse(Gate::forUser($blocked)->allows('view', $template));
        $this->assertFalse(Gate::forUser($blocked)->allows('view', $catalog));
    }

    /**
     * Two accounts with no company are not one company.
     *
     * The three gates compared `$user->company_id == $template->company_id`
     * with a loose operator, and PHP reads two nulls as a match. A user with
     * no company therefore reached every template with no company, including
     * the private template of another user with no company. No signup path
     * writes a null company today, and a gate that rests on that fact is a
     * gate that one migration opens.
     *
     * The template here carries an owner, so the null-owner catalog clause
     * cannot answer for it.
     */
    public function test_a_user_with_no_company_reaches_no_private_template_with_no_company(): void
    {
        $stranger = User::factory()->create(['company_id' => null]);
        $owner = User::factory()->create(['company_id' => null]);
        $private = Template::factory()->create(['user_id' => $owner->id, 'company_id' => null]);

        $this->assertFalse(Gate::forUser($stranger)->allows('view', $private));
        $this->assertFalse(Gate::forUser($stranger)->allows('update', $private));
        $this->assertFalse(Gate::forUser($stranger)->allows('delete', $private));
    }

    /**
     * A company identifier that arrives as a string still matches.
     *
     * A route or a form hands the attribute over as text. The comparison casts
     * both sides, so `3` and `"3"` name one company.
     */
    public function test_a_company_identifier_matches_across_a_string_and_an_integer(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => User::factory()->create(['company_id' => $company->id])->id,
            'company_id' => $company->id,
        ]);

        $user->company_id = (string) $company->id;

        $this->assertTrue(Gate::forUser($user)->allows('view', $template));
    }
}
