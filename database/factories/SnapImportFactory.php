<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SnapImportFactory extends Factory
{
    protected $model = SnapImport::class;

    public function definition()
    {
        return [
            'template_id' => Template::factory(),
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'status' => SnapImport::STATUS_PENDING,
            'total_rows' => $this->faker->numberBetween(5, 100),
            'processed_rows' => 0,
            'original_filename' => $this->faker->word().'.csv',
            'title' => $this->faker->sentence(3),
        ];
    }

    public function processing()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => SnapImport::STATUS_PROCESSING,
            ];
        });
    }

    public function completed()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => SnapImport::STATUS_COMPLETED,
                'completed_at' => now(),
                'processed_rows' => $attributes['total_rows'] ?? 10,
            ];
        });
    }

    public function failed()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => SnapImport::STATUS_FAILED,
                'error_message' => 'Import processing failed',
            ];
        });
    }

    public function canceled()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => SnapImport::STATUS_CANCELED,
                'canceled_at' => now(),
            ];
        });
    }
}
