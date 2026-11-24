<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateCollectionFactory extends Factory
{
    protected $model = TemplateCollection::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'name' => $this->faker->words(3, true),
        ];
    }
}
