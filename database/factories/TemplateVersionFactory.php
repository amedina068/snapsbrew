<?php

namespace Database\Factories;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateVersionFactory extends Factory
{
    protected $model = TemplateVersion::class;

    public function definition(): array
    {
        return [
            'template_id' => Template::factory(),
            'version_number' => 1,
            'layers' => json_encode([]),
            'groups' => json_encode([]),
            'width' => 1080,
            'height' => 1080,
            'published' => false,
            'created_by' => User::factory(),
        ];
    }

    /** A render source with no parent template: an inline design. */
    public function inline(): static
    {
        return $this->state(fn () => ['template_id' => null]);
    }
}
