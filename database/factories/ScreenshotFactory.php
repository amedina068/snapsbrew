<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScreenshotFactory extends Factory
{
    protected $model = Screenshot::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'url' => $this->faker->url(),
            'width' => $this->faker->randomElement([1280, 1920, 375]),
            'height' => $this->faker->randomElement([720, 1080, 812]),
            'full_page' => false,
        ];
    }

    public function fullPage()
    {
        return $this->state(function (array $attributes) {
            return [
                'full_page' => true,
            ];
        });
    }
}
