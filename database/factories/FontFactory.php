<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Font;
use Illuminate\Database\Eloquent\Factories\Factory;

class FontFactory extends Factory
{
    protected $model = Font::class;

    public function definition()
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->words(2, true),
            'family' => 'Custom '.$this->faker->unique()->numberBetween(1, 100000),
        ];
    }
}
