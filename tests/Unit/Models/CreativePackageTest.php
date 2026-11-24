<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreativePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_an_initialized_tenant_claim(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $package = CreativePackage::factory()->for($company)->create(['created_by' => $user->id]);

        $this->assertSame(CreativePackage::STATUS_BUILDING, $package->status);
        $this->assertSame('creative_package_export_v1', $package->operation_namespace);
        $this->assertSame(1, $package->manifest_version);
        $this->assertSame(0, $package->pending_items_count);
        $this->assertNotNull($package->uuid);
        $this->assertNotNull($package->lease_expires_at);
        $this->assertNotNull($package->started_at);
        $this->assertNotNull($package->replay_expires_at);
        $this->assertTrue($package->lease_expires_at->isFuture());
        $this->assertSame(90.0, now()->startOfDay()->diffInDays($package->replay_expires_at->startOfDay()));
        $this->assertTrue($package->company->is($company));
        $this->assertTrue($package->creator->is($user));
        $this->assertTrue($company->creativePackages->contains($package));
    }

    public function test_package_casts_its_timestamps_and_failure_details(): void
    {
        $package = CreativePackage::factory()->failed()->create([
            'failure_details' => ['item' => 'creative-1'],
        ])->fresh();

        $this->assertSame(['item' => 'creative-1'], $package->failure_details);
        $this->assertInstanceOf(\DateTimeInterface::class, $package->lease_expires_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $package->started_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $package->completed_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $package->replay_expires_at);
        $this->assertContains('idempotency_key', $package->getFillable());
        $this->assertNotContains('uuid', $package->getFillable());
    }

    public function test_claim_key_is_unique_per_company_and_reusable_by_another_company(): void
    {
        $first = CreativePackage::factory()->create([
            'operation_namespace' => 'creative_package_export_v1',
            'idempotency_key' => 'same-key',
        ]);

        CreativePackage::factory()->create([
            'operation_namespace' => $first->operation_namespace,
            'idempotency_key' => $first->idempotency_key,
        ]);

        $this->expectException(QueryException::class);
        CreativePackage::factory()->for($first->company)->create([
            'operation_namespace' => $first->operation_namespace,
            'idempotency_key' => $first->idempotency_key,
        ]);
    }

    /** The signed media route reads by uuid alone, so a uuid is unique across every company, not per company. */
    public function test_public_uuid_is_unique_across_companies(): void
    {
        $first = CreativePackage::factory()->create();
        $second = CreativePackage::factory()->create();

        $this->expectException(QueryException::class);
        $second->forceFill(['uuid' => $first->uuid])->save();
    }

    public function test_schema_has_exact_package_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('creative_packages'))
            ->mapWithKeys(fn (array $index) => [$index['name'] => [
                'columns' => $index['columns'],
                'unique' => $index['unique'],
            ]]);

        $this->assertSame(['company_id', 'uuid'], $indexes['creative_packages_company_uuid_unique']['columns']);
        $this->assertTrue($indexes['creative_packages_company_uuid_unique']['unique']);
        // The signed media route reads by uuid alone, with no tenant in hand.
        $this->assertSame(['uuid'], $indexes['creative_packages_uuid_unique']['columns']);
        $this->assertTrue($indexes['creative_packages_uuid_unique']['unique']);
        $this->assertSame(['company_id', 'id'], $indexes['creative_packages_company_id_unique']['columns']);
        $this->assertTrue($indexes['creative_packages_company_id_unique']['unique']);
        $this->assertSame(
            ['company_id', 'operation_namespace', 'idempotency_key'],
            $indexes['creative_packages_company_claim_unique']['columns']
        );
        $this->assertTrue($indexes['creative_packages_company_claim_unique']['unique']);
        $this->assertSame(
            ['company_id', 'status', 'lease_expires_at'],
            $indexes['creative_packages_active_limit_index']['columns']
        );
        // (company_id, created_at, id) had no reader and was dropped.
        $this->assertArrayNotHasKey('creative_packages_company_lookup_index', $indexes->all());
        $this->assertSame(
            ['replay_expires_at', 'id'],
            $indexes['creative_packages_replay_cleanup_index']['columns']
        );
    }
}
