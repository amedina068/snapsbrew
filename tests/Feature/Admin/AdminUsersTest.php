<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'is_owner' => true,
            'name' => 'Olive Owner',
            'email' => 'owner@example.com',
        ]);
    }

    public function test_the_list_shows_every_user_of_the_instance_with_company_and_status()
    {
        $owner = $this->owner();
        $acme = Company::factory()->create(['name' => 'Acme Studio']);
        User::factory()->create(['name' => 'Ada Active', 'email' => 'ada@example.com', 'company_id' => $acme->id]);
        User::factory()->create(['name' => 'Dan Gone', 'email' => 'dan@example.com', 'deactivated_at' => now()]);

        $response = $this->actingAs($owner)->get('/admin/users');

        $response->assertOk();
        $response->assertSee('Ada Active');
        $response->assertSee('ada@example.com');
        $response->assertSee('Acme Studio');
        $response->assertSee('Dan Gone');
        $response->assertSee('Deactivated');
        $response->assertSee('Owner');
    }

    public function test_the_list_is_paginated()
    {
        $owner = $this->owner();
        User::factory()->count(30)->create();
        $last = User::factory()->create(['name' => 'Zed Last-Created']);

        // The newest users come first.
        $this->actingAs($owner)->get('/admin/users')
            ->assertOk()
            ->assertSee('Zed Last-Created')
            ->assertSee('page=2', false);

        $this->actingAs($owner)->get('/admin/users?page=2')
            ->assertOk()
            ->assertDontSee('Zed Last-Created')
            ->assertSee('Olive Owner');

        $this->assertNotNull($last);
    }

    public function test_the_search_matches_the_name_or_the_email()
    {
        $owner = $this->owner();
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        User::factory()->create(['name' => 'Alan Turing', 'email' => 'enigma@example.org']);

        $this->actingAs($owner)->get('/admin/users?search=hopper')
            ->assertOk()
            ->assertSee('Grace Hopper')
            ->assertDontSee('Alan Turing');

        $this->actingAs($owner)->get('/admin/users?search=enigma@')
            ->assertOk()
            ->assertSee('Alan Turing')
            ->assertDontSee('Grace Hopper');

        $this->actingAs($owner)->get('/admin/users?search=nobody-matches')
            ->assertOk()
            ->assertDontSee('Grace Hopper')
            ->assertDontSee('Alan Turing')
            ->assertSee('No user matches');
    }

    public function test_the_owner_deactivates_a_user()
    {
        $owner = $this->owner();
        $user = User::factory()->create();

        $this->actingAs($owner)
            ->from('/admin/users')
            ->post("/admin/users/{$user->id}/deactivation")
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        $this->assertNotNull($user->fresh()->deactivated_at);
        $this->assertTrue($user->fresh()->isDeactivated());
    }

    public function test_the_owner_reactivates_a_user()
    {
        $owner = $this->owner();
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->actingAs($owner)
            ->from('/admin/users')
            ->delete("/admin/users/{$user->id}/deactivation")
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        $this->assertNull($user->fresh()->deactivated_at);
    }

    public function test_deactivation_keeps_the_data_of_the_user()
    {
        $owner = $this->owner();
        $user = User::factory()->create();
        $user->createToken('cli', ['api_requests']);

        $this->actingAs($owner)->post("/admin/users/{$user->id}/deactivation");

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_the_owner_cannot_deactivate_an_owner()
    {
        $owner = $this->owner();
        $otherOwner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)->post("/admin/users/{$otherOwner->id}/deactivation")->assertForbidden();
        $this->actingAs($owner)->post("/admin/users/{$owner->id}/deactivation")->assertForbidden();

        $this->assertNull($otherOwner->fresh()->deactivated_at);
        $this->assertNull($owner->fresh()->deactivated_at);
    }

    public function test_the_list_offers_the_right_action_for_each_status()
    {
        $owner = $this->owner();
        $active = User::factory()->create();
        $inactive = User::factory()->create(['deactivated_at' => now()]);

        $response = $this->actingAs($owner)->get('/admin/users');

        $response->assertSee('action="'.url("/admin/users/{$active->id}/deactivation").'"', false);
        $response->assertSee('action="'.url("/admin/users/{$inactive->id}/deactivation").'"', false);
        // The owner row has no form at all.
        $response->assertDontSee('action="'.url("/admin/users/{$owner->id}/deactivation").'"', false);
        // Each form asks for a confirmation before it sends.
        $response->assertSee('onsubmit="return confirm(', false);
        $response->assertSee('Reactivate');
        $response->assertSee('Deactivate');
    }
}
