<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition()
    {
        $size = $this->faker->randomElement(Template::DEFAULT_SIZES);

        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'title' => $this->faker->sentence(3),
            'width' => $size['width'],
            'height' => $size['height'],
            'size' => $size['value'],
            'layers' => json_encode([]),
        ];
    }

    public function withLayers(array $layers)
    {
        return $this->state(function (array $attributes) use ($layers) {
            return [
                'layers' => json_encode($layers),
            ];
        });
    }

    public function public()
    {
        return $this->state(function (array $attributes) {
            return [
                'user_id' => null,
                'company_id' => null,
            ];
        });
    }

    public function instagram()
    {
        return $this->state(function (array $attributes) {
            return [
                'width' => 1080,
                'height' => 1080,
                'size' => 'instagram_square_post',
            ];
        });
    }

    public function custom(int $width, int $height)
    {
        return $this->state(function (array $attributes) use ($width, $height) {
            return [
                'width' => $width,
                'height' => $height,
                'size' => 'custom',
            ];
        });
    }
}
