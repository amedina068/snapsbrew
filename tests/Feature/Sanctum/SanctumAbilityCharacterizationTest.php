<?php

namespace Tests\Feature\Sanctum;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins API-access semantics across the Sanctum 2 -> 3 jump. The critical
 * property is FAIL-CLOSED: a token lacking the api_requests ability must get an
 * exact 403, never pass through or 500. Also guards that an existing token
 * (expires_at = null, as the T-016 migration leaves every row) still authes.
 */
class SanctumAbilityCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private function apiUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user;
    }

    public function test_signed_url_ability_token_is_rejected_on_api_route_with_exact_403()
    {
        $user = $this->apiUser();
        $token = $user->createToken('signed_url_key', ['signed_url']);

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        $response->assertStatus(403);
    }

    public function test_api_requests_token_authenticates_with_200()
    {
        $user = $this->apiUser();
        $token = $user->createToken('api_key', ['api_requests']);

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        // Default-created tokens have no expiry; this is the regression guard
        // that the expires_at column added in T-016 does not expire live keys.
        $this->assertNull($token->accessToken->fresh()->expires_at ?? null);
        $response->assertStatus(200);
    }

    public function test_request_without_token_is_unauthenticated_401()
    {
        $response = $this->getJson('/api/v1/auth');

        $response->assertStatus(401);
    }
}
