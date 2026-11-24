<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Screenshot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_belongs_to_company()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->assertInstanceOf(Company::class, $user->company);
        $this->assertEquals($company->id, $user->company->id);
    }

    public function test_user_has_many_templates()
    {
        $user = User::factory()->create();
        Template::factory()->count(3)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertCount(3, $user->templates);
    }

    public function test_user_has_many_screenshots()
    {
        $user = User::factory()->create();
        Screenshot::factory()->count(2)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertCount(2, $user->screenshots);
    }

    public function test_is_admin()
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($customer->isAdmin());
    }

    public function test_is_customer()
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $admin = User::factory()->admin()->create();

        $this->assertTrue($customer->isCustomer());
        $this->assertFalse($admin->isCustomer());
    }

    public function test_get_account_data()
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $data = $user->getAccountData();

        $this->assertEquals('John Doe', $data['name']);
        $this->assertEquals('john@example.com', $data['email']);
    }

    public function test_get_newsletter_data()
    {
        $user = User::factory()->create();

        $data = $user->getNewsletterData();

        $this->assertArrayHasKey('newsletter', $data);
    }

    public function test_admin_scope()
    {
        User::factory()->admin()->create();
        User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $admins = User::admin()->get();

        $this->assertCount(1, $admins);
        $this->assertEquals(User::ROLE_ADMIN, $admins->first()->role);
    }

    public function test_factory_states()
    {
        $user = User::factory()->unverified()->create();
        $this->assertNull($user->email_verified_at);

        $admin = User::factory()->admin()->create();
        $this->assertEquals(User::ROLE_ADMIN, $admin->role);
    }
}
