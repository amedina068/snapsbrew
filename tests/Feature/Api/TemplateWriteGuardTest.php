<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every template write route carries a gate, and no other test reads one.
 *
 * The tenant boundary is enforced two times on a route that reads a template.
 * The company-scoped fetch answers 404, and `TemplatePolicy` answers 403. A
 * caller from another company reads the 404 first, so a test that sends a
 * stranger never reaches the gate. An audit deleted the `authorize` line from
 * three write routes and the suite stayed green for that reason.
 *
 * Every `TemplatePolicy` method also requires `actsAsCustomer()`. A caller
 * inside the owning company that fails that check is the only caller the gate
 * stops on its own, so every test here sends that caller.
 */
class TemplateWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A template and a token for a caller the gate must refuse.
     *
     * The caller belongs to the company that owns the template, so the fetch
     * finds the row and the gate is the only thing left to answer.
     *
     * @return array{0: Template, 1: string}
     */
    private function templateAndBlockedCaller(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
        ]);

        return [$template, $this->blockedToken($company)];
    }

    /**
     * A token for a company member that fails `actsAsCustomer()`.
     *
     * `actsAsCustomer()` passes the customer role and the admin role, so a
     * third role is the only one that reaches the denial branch. No factory
     * state builds such a user. `McpToolErrorTest::makeUserWithNoContentRole()`
     * writes the role inline for the same reason.
     */
    private function blockedToken(Company $company): string
    {
        $blocked = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'viewer',
        ]);

        return $blocked->createToken('test', ['api_requests'])->plainTextToken;
    }

    /**
     * `POST /v1/templates` has a different shape. It reads no template, so the
     * company-scoped fetch that hides the other gates is not there, and it
     * names the `create` ability. The role check is the whole gate, and no
     * other test sends a caller that fails it.
     */
    public function test_template_create_refuses_a_caller_that_cannot_act_as_a_customer(): void
    {
        $token = $this->blockedToken(Company::factory()->create());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/templates', [
                'title' => 'Built by a caller the gate must refuse',
                'width' => 100,
                'height' => 100,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('templates', ['title' => 'Built by a caller the gate must refuse']);
    }

    public function test_publish_refuses_a_company_member_that_cannot_act_as_a_customer(): void
    {
        [$template, $token] = $this->templateAndBlockedCaller();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/templates/{$template->uuid}/publish")
            ->assertForbidden();

        $this->assertDatabaseMissing('template_versions', ['template_id' => $template->id]);
    }

    public function test_template_update_refuses_a_company_member_that_cannot_act_as_a_customer(): void
    {
        [$template, $token] = $this->templateAndBlockedCaller();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", [
                'title' => 'Renamed by a caller the gate must refuse',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'title' => $template->title]);
    }

    /**
     * `DELETE /v1/templates/{templateId}` names the `delete` ability, and
     * `TemplatePolicy::delete` carries the same two conditions as `update`. The
     * route was not in the audit, and its gate is shadowed the same way.
     */
    public function test_template_delete_refuses_a_company_member_that_cannot_act_as_a_customer(): void
    {
        [$template, $token] = $this->templateAndBlockedCaller();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$template->id}")
            ->assertForbidden();

        // The model has no soft deletes, so the row itself is the proof.
        $this->assertDatabaseHas('templates', ['id' => $template->id]);
    }

    public function test_document_replace_refuses_a_company_member_that_cannot_act_as_a_customer(): void
    {
        [$template, $token] = $this->templateAndBlockedCaller();

        // The request body is valid. Validation runs before the gate, so an
        // invalid body answers 422 and proves nothing about the gate.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'document' => ['version' => 2, 'layers' => []],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => null]);
    }

    public function test_document_apply_refuses_a_company_member_that_cannot_act_as_a_customer(): void
    {
        [$template, $token] = $this->templateAndBlockedCaller();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'operations' => [
                    ['op' => 'set_layer_property', 'layer_id' => 'a', 'property' => 'opacity', 'value' => 1],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => null]);
    }

    /**
     * Pin the throttle group of the four editor-document routes.
     *
     * The public specification says 30 calls a minute for one token and 180 a
     * minute for one company. Those numbers come from the `editor-document`
     * limiter in `RouteServiceProvider`. A route that leaves the group keeps
     * the documented numbers on the page and loses them on the wire, and no
     * other test reads the membership.
     */
    public function test_every_editor_document_route_carries_the_editor_document_throttle(): void
    {
        $expected = [
            ['GET', 'api/v1/templates/{templateUuid}/document'],
            ['GET', 'api/v1/templates/{templateUuid}/document/preflight'],
            ['PUT', 'api/v1/templates/{templateUuid}/document'],
            ['PATCH', 'api/v1/templates/{templateUuid}/document'],
        ];

        foreach ($expected as [$method, $uri]) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

            $this->assertNotNull($route, "No route answers {$method} {$uri}.");
            $this->assertContains(
                'throttle:editor-document',
                $route->gatherMiddleware(),
                "{$method} {$uri} left the editor-document throttle group."
            );
        }
    }
}
