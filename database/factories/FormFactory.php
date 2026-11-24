<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'formable_id' => Template::factory(),
            'formable_type' => Template::class,
            'title' => $this->faker->sentence(3),
            'available_layers' => json_encode(['layers' => []]),
            // The output type of the form (`image` or `pdf`), not its scope.
            'type' => 'image',
        ];
    }

    public function forTemplate(Template $template)
    {
        return $this->state(function (array $attributes) use ($template) {
            return [
                'formable_id' => $template->id,
                'formable_type' => Template::class,
            ];
        });
    }

    public function forCollection($collection)
    {
        return $this->state(function (array $attributes) use ($collection) {
            return [
                'formable_id' => $collection->id,
                'formable_type' => TemplateCollection::class,
            ];
        });
    }
}
