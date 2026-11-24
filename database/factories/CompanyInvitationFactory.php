<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CompanyInvitationFactory extends Factory
{
    protected $model = CompanyInvitation::class;

    public function definition()
    {
        return [
            'company_id' => Company::factory(),
            'invited_by' => User::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired()
    {
        return $this->state(function (array $attributes) {
            return [
                'expires_at' => now()->subDay(),
            ];
        });
    }

    public function accepted()
    {
        return $this->state(function (array $attributes) {
            return [
                'accepted_at' => now(),
                'accepted_by' => User::factory(),
            ];
        });
    }

    public function noExpiration()
    {
        return $this->state(function (array $attributes) {
            return [
                'expires_at' => null,
            ];
        });
    }
}
