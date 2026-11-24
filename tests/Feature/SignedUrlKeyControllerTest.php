<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedUrlKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_store_creates_signed_url_key()
    {
        $user = $this->authenticatedUser();

        $response = $this->post('/signed-url-keys');

        $response->assertRedirect();

        $this->assertDatabaseHas('api_keys', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'type' => ApiKey::TYPE_SIGNED_URL,
        ]);
    }

    public function test_destroy_revokes_key()
    {
        $user = $this->authenticatedUser();

        $apiKey = ApiKey::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->delete("/signed-url-keys/{$apiKey->id}");

        $response->assertRedirect();
        $this->assertNotNull($apiKey->fresh()->invalidated_at);
    }

    public function test_guests_cannot_create_keys()
    {
        $this->post('/signed-url-keys')->assertRedirect('/login');
    }

    public function test_cannot_destroy_other_company_key()
    {
        $this->authenticatedUser();

        $other = User::factory()->create();
        $apiKey = ApiKey::factory()->create([
            'user_id' => $other->id,
            'company_id' => $other->company_id,
        ]);

        $this->delete("/signed-url-keys/{$apiKey->id}")->assertNotFound();

        $this->assertNull($apiKey->fresh()->invalidated_at);
    }

    /**
     * The developers page lists only the keys of the signed-in user, so a
     * company peer must not revoke a key that the page never shows them.
     */
    public function test_cannot_destroy_the_key_of_a_company_peer()
    {
        $user = $this->authenticatedUser();
        $peer = User::factory()->create(['company_id' => $user->company_id]);
        $token = $peer->createToken('signed_url', ['signed_url']);
        $apiKey = ApiKey::factory()->create([
            'user_id' => $peer->id,
            'company_id' => $peer->company_id,
            'token_id' => $token->accessToken->id,
        ]);

        $this->delete("/signed-url-keys/{$apiKey->id}")->assertNotFound();

        $this->assertNull($apiKey->fresh()->invalidated_at);
        $this->assertNotNull($token->accessToken->fresh());
    }
}
