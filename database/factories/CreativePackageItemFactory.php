<?php

namespace Database\Factories;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreativePackageItemFactory extends Factory
{
    protected $model = CreativePackageItem::class;

    public function definition(): array
    {
        return [
            'creative_package_id' => CreativePackage::factory(),
            'company_id' => fn (array $attributes) => CreativePackage::findOrFail($attributes['creative_package_id'])->company_id,
            'position' => 0,
            'client_creative_id' => $this->faker->unique()->regexify('[A-Za-z][A-Za-z0-9_-]{15}'),
            'label' => $this->faker->sentence(3),
            'alt_text' => $this->faker->sentence(),
            'metadata' => [],
            'source_template_version_id' => function (array $attributes) {
                $package = CreativePackage::findOrFail($attributes['creative_package_id']);
                $template = Template::factory()->for($package->company)->create();

                return TemplateVersion::factory()->for($template)->create()->id;
            },
            'source_template_uuid' => fn (array $attributes) => TemplateVersion::findOrFail($attributes['source_template_version_id'])->template->uuid,
            'source_template_version' => fn (array $attributes) => TemplateVersion::findOrFail($attributes['source_template_version_id'])->version_number,
            'source_editor_document_version' => fn (array $attributes) => TemplateVersion::findOrFail($attributes['source_template_version_id'])->editor_schema_version,
            'requested_format' => 'png',
            'status' => CreativePackageItem::STATUS_PENDING,
            'attempts' => 0,
            'retryable' => true,
            'lease_generation' => 0,
        ];
    }

    public function rendering(): static
    {
        return $this->state(fn () => [
            'status' => CreativePackageItem::STATUS_RENDERING, 'attempts' => 1,
            'lease_token' => hash('sha256', $this->faker->uuid()), 'lease_generation' => 1,
            'lease_expires_at' => now()->addMinutes(5), 'started_at' => now(),
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => CreativePackageItem::STATUS_READY, 'retryable' => false,
            'output_filename' => 'creative.png', 'output_mime_type' => 'image/png', 'output_bytes' => 1, 'output_width' => 1080, 'output_height' => 1080,
            'output_sha256' => str_repeat('a', 64), 'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CreativePackageItem::STATUS_FAILED, 'retryable' => false,
            'failure_code' => 'creative_package_render_failed', 'completed_at' => now(),
        ]);
    }
}
