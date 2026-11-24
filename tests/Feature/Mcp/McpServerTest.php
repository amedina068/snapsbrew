<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_endpoint_requires_authentication()
    {
        // The /mcp route is registered and guarded by auth:sanctum — an
        // unauthenticated call is rejected.
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }

    public function test_mcp_endpoint_rejects_token_without_api_requests_ability()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['other_ability'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]);

        $response->assertStatus(403);
    }
}
