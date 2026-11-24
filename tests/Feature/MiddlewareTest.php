<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    // --- No plan gate ---

    public function test_customer_without_a_plan_reaches_snap_validation()
    {
        $company = Company::factory()->create();
        $customer = User::factory()->create([
            'company_id' => $company->id,
            'role' => User::ROLE_CUSTOMER,
        ]);
        $token = $customer->createToken('test', ['api_requests']);

        // No plan gate stands in front of the API. The empty body fails
        // validation, so the request reached the controller.
        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->postJson('/api/v1/snaps', [])
            ->assertStatus(422);
    }

    // --- LogApiRequestMiddleware ---

    public function test_api_requests_are_logged()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        $this->assertDatabaseHas('api_logs', [
            'user_id' => $user->id,
            'method' => 'GET',
        ]);
    }

    public function test_api_log_records_request_url()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        $log = ApiLog::where('user_id', $user->id)->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('/api/v1/auth', $log->url);
        $this->assertNotNull($log->ip_address);
    }

    public function test_api_log_records_access_token_id()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth');

        $log = ApiLog::where('user_id', $user->id)->first();

        $this->assertEquals($token->accessToken->id, $log->personal_access_token_id);
    }
}
