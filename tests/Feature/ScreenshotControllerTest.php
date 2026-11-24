<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenshotControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_guests_cannot_access_screenshots()
    {
        $response = $this->get('/screenshots');

        $response->assertRedirect('/login');
    }

    public function test_index_shows_screenshots()
    {
        $user = $this->createAuthenticatedUser();

        Screenshot::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'url' => 'https://example.com',
        ]);

        $response = $this->get('/screenshots');

        $response->assertStatus(200);
    }

    public function test_create_shows_form()
    {
        $user = $this->createAuthenticatedUser();

        $response = $this->get('/screenshots/new');

        $response->assertStatus(200);
    }
}
