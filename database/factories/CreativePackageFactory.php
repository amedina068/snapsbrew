<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CreativePackage;
use App\Support\CreativePackageContract;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreativePackageFactory extends Factory
{
    protected $model = CreativePackage::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'operation_namespace' => 'creative_package_export_v1',
            'idempotency_key' => $this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'request_fingerprint' => hash('sha256', $this->faker->uuid()),
            'status' => CreativePackage::STATUS_BUILDING,
            'total_items' => 0,
            'pending_items_count' => 0,
            'rendering_items_count' => 0,
            'ready_items_count' => 0,
            'failed_items_count' => 0,
            'manifest_version' => CreativePackageContract::VERSION,
            'lease_expires_at' => now()->addSeconds(CreativePackageContract::LEASE_SECONDS),
            'started_at' => now(),
            'replay_expires_at' => now()->addDays(CreativePackageContract::REPLAY_DAYS),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CreativePackage::STATUS_FAILED,
            'failure_code' => 'creative_package_failed',
            'completed_at' => now(),
        ]);
    }
}
