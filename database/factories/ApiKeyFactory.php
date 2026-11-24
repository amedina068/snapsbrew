<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition()
    {
        $user = User::factory()->create();

        $token = PersonalAccessToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test-token',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['*'],
        ]);

        return [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'token_id' => $token->id,
            'type' => ApiKey::TYPE_SIGNED_URL,
            'key' => Str::random(40),
        ];
    }

    public function signedUrl()
    {
        return $this->state(function (array $attributes) {
            return [
                'type' => ApiKey::TYPE_SIGNED_URL,
            ];
        });
    }
}
