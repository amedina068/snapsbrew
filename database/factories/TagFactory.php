<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition()
    {
        $name = $this->faker->unique()->word();

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
