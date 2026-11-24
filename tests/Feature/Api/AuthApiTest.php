<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    public function test_unauthenticated_request_returns_401()
    {
        $response = $this->getJson('/api/v1/auth');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_get_auth_info()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth');

        $response->assertStatus(200);
        $response->assertExactJson([
            'data' => [
                'email' => $user->email,
                'name' => $user->name,
                'company' => $user->company->name,
            ],
        ]);
    }

    /**
     * `company_id` is nullable, and the `whoami` MCP tool answers null for an
     * account with no company. This endpoint must answer the same value.
     */
    public function test_company_is_null_when_the_account_has_no_company()
    {
        $user = User::factory()->create(['company_id' => null]);
        $token = $user->createToken('test', ['api_requests']);

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.company'));
    }

    public function test_token_without_api_requests_ability_is_rejected()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['other_ability']);

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        $response->assertStatus(403);
    }

    /**
     * A read of the company must not repeat a query the request already made.
     *
     * `LogApiRequestMiddleware` reads the company on every logged route. It
     * now hands that row to the request, so `UserResource` reads it
     * from the relation cache. Before that, this endpoint ran the same
     * `select * from companies where id = ?` a second time.
     *
     * The number is the guard. A later change that drops the cached relation
     * raises it, and this test names the queries it counted.
     */
    public function test_reading_the_company_adds_no_second_query()
    {
        [$user, $token] = $this->createApiUser();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth')
            ->assertOk();

        $companyReads = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from "companies"')
                || str_contains($sql, 'from `companies`')
        ));

        $this->assertCount(
            1,
            $companyReads,
            "The request read the companies table more than once:\n".implode("\n", $companyReads)
        );
    }
}
