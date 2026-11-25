<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\LoadsHorizon;
use Tests\TestCase;

class OwnerTest extends TestCase
{
    use LoadsHorizon, RefreshDatabase;

    public function test_only_the_owner_of_the_instance_can_open_horizon()
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $staff = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($staff)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($customer)->allows('viewHorizon'));
        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    public function test_the_owner_flag_is_separate_from_the_admin_role()
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isAdmin());
        $this->assertTrue($owner->actsAsCustomer());
        $this->assertFalse(User::factory()->admin()->create()->isOwner());
    }
}
