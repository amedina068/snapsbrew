<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class SnapFactory extends Factory
{
    protected $model = Snap::class;

    public function definition()
    {
        return [
            'template_id' => Template::factory(),
            'company_id' => Company::factory(),
            'status' => Snap::STATUS_PENDING,
            'type' => Snap::TYPE_IMAGE,
            'is_async' => false,
            'metadata' => [],
        ];
    }

    /** A snap rendered from an inline design: no template, a template-less source version. */
    public function inline(): static
    {
        return $this->state(fn () => [
            'template_id' => null,
            'source_template_version_id' => TemplateVersion::factory()->inline(),
        ]);
    }

    public function completed()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Snap::STATUS_COMPLETED,
                'completed_at' => now(),
            ];
        });
    }

    public function failed()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Snap::STATUS_FAILED,
                'last_error' => 'Rendering failed',
            ];
        });
    }

    public function processing()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Snap::STATUS_PROCESSING,
            ];
        });
    }

    public function canceled()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Snap::STATUS_CANCELED,
            ];
        });
    }

    public function async(?string $webhookUrl = null)
    {
        return $this->state(function (array $attributes) use ($webhookUrl) {
            return [
                'is_async' => true,
                'webhook_url' => $webhookUrl ?? $this->faker->url(),
            ];
        });
    }

    public function pdf()
    {
        return $this->state(function (array $attributes) {
            return [
                'type' => Snap::TYPE_PDF,
            ];
        });
    }
}
