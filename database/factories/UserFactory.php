<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
            'role' => User::ROLE_CUSTOMER,
            'company_id' => Company::factory(),
            // Factory users are "onboarded" by default — mirrors the migration
            // backfill of pre-existing users. Use needsOnboarding() to test the
            // first-run wizard flow.
            'onboarding_completed_at' => now(),
        ];
    }

    public function needsOnboarding()
    {
        return $this->state(fn (array $attributes) => [
            'onboarding_completed_at' => null,
        ]);
    }

    /** Without a state, the first user of a company is its owner and later users are members. */
    public function companyOwner()
    {
        return $this->state(fn (array $attributes) => [
            'company_role' => User::COMPANY_ROLE_OWNER,
        ]);
    }

    public function companyMember()
    {
        return $this->state(fn (array $attributes) => [
            'company_role' => User::COMPANY_ROLE_MEMBER,
        ]);
    }

    public function unverified()
    {
        return $this->state(function (array $attributes) {
            return [
                'email_verified_at' => null,
            ];
        });
    }

    public function admin()
    {
        return $this->state(function (array $attributes) {
            return [
                'role' => User::ROLE_ADMIN,
            ];
        });
    }
}
