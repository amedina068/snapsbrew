<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserListCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_every_user()
    {
        $owner = User::factory()->create([
            'name' => 'Olive Owner',
            'email' => 'owner@example.com',
            'is_owner' => true,
            'company_id' => Company::factory()->create(['name' => 'Olive Studio']),
        ]);
        $member = User::factory()->create([
            'name' => 'Max Member',
            'email' => 'member@example.com',
            'company_id' => Company::factory()->create(['name' => 'Max Studio']),
        ]);

        $this->artisan('snapsbrew:user:list')
            ->expectsTable(
                ['ID', 'Name', 'Email', 'Company', 'Owner', 'Created'],
                [
                    [$owner->id, 'Olive Owner', 'owner@example.com', 'Olive Studio', 'yes', $owner->created_at->toDateString()],
                    [$member->id, 'Max Member', 'member@example.com', 'Max Studio', 'no', $member->created_at->toDateString()],
                ],
            )
            ->assertSuccessful();
    }

    public function test_it_filters_by_name_or_email()
    {
        User::factory()->create(['name' => 'Olive Owner', 'email' => 'owner@example.com']);
        User::factory()->create(['name' => 'Max Member', 'email' => 'max@other.test']);

        $this->artisan('snapsbrew:user:list', ['--search' => 'olive'])
            ->expectsOutputToContain('owner@example.com')
            ->doesntExpectOutputToContain('max@other.test')
            ->assertSuccessful();

        $this->artisan('snapsbrew:user:list', ['--search' => 'other.test'])
            ->expectsOutputToContain('max@other.test')
            ->doesntExpectOutputToContain('owner@example.com')
            ->assertSuccessful();
    }

    public function test_it_says_when_no_user_matches()
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('snapsbrew:user:list', ['--search' => 'nobody'])
            ->expectsOutputToContain('No user matches.')
            ->assertSuccessful();
    }
}
