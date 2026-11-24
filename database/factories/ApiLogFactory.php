<?php

namespace Database\Factories;

use App\Models\ApiLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApiLogFactory extends Factory
{
    protected $model = ApiLog::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'url' => $this->faker->url(),
            'ip_address' => $this->faker->ipv4(),
            'method' => $this->faker->randomElement(['GET', 'POST', 'PATCH', 'DELETE']),
            'request' => json_encode(['key' => 'value']),
            'response' => json_encode(['status' => 'ok']),
            'status_code' => 200,
            'renders' => 1,
            'feature' => 'snaps',
            'type' => 'api',
        ];
    }

    public function successful()
    {
        return $this->state(function (array $attributes) {
            return [
                'status_code' => $this->faker->randomElement([200, 201]),
            ];
        });
    }

    public function post()
    {
        return $this->state(function (array $attributes) {
            return [
                'method' => 'POST',
            ];
        });
    }

    public function withRenders(int $renders = 1)
    {
        return $this->state(function (array $attributes) use ($renders) {
            return [
                'renders' => $renders,
                'method' => 'POST',
                'status_code' => 201,
            ];
        });
    }
}
