<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\User;
use App\Support\CreativePackageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

class CreativePackageClaimTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordDispatches();
    }

    public function test_a_member_claims_every_ordered_item_and_dispatch_runs_after_commit(): void
    {
        $actor = $this->member();
        $sources = [$this->source($actor, 2), $this->source($actor, 1)];
        $baseLevel = DB::transactionLevel();

        $result = $this->claim($actor, $this->request('key-1', $sources));

        $this->assertFalse($result->replayed);
        $package = $result->package->fresh();
        $this->assertSame([CreativePackage::STATUS_BUILDING, $actor->company_id, $actor->id, 'creative_package_export_v1', 'key-1', 2, 2, $this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS)->timestamp, $this->now()->addDays(CreativePackageContract::REPLAY_DAYS)->timestamp], [$package->status, $package->company_id, $package->created_by, $package->operation_namespace, $package->idempotency_key, $package->total_items, $package->pending_items_count, $package->lease_expires_at->timestamp, $package->replay_expires_at->timestamp]);
        $items = $package->items;
        $this->assertSame(
            [[0, 'creative-0', $sources[0]->id, $sources[0]->template->uuid, 2, 'pending', null], [1, 'creative-1', $sources[1]->id, $sources[1]->template->uuid, 1, 'pending', null]],
            $items->map(fn (CreativePackageItem $item) => [$item->position, $item->client_creative_id, $item->source_template_version_id, $item->source_template_uuid, $item->source_template_version, $item->status, $item->api_log_id])->all(),
        );
        $this->assertSame(['family-0', 'instagram.feed.organic', 'Launch day', ['campaign' => 'launch'], 'png', $sources[0]->editor_schema_version], [$items[0]->client_family_id, $items[0]->placement_key, $items[0]->caption, $items[0]->metadata, $items[0]->requested_format, $items[0]->source_editor_document_version]);
        $this->assertSame([['package_id' => $package->id, 'transaction_level' => $baseLevel, 'persisted' => true]], $this->dispatches);
    }

    public function test_a_customer_and_an_admin_can_claim(): void
    {
        $customer = $this->memberOf(Company::factory()->create());
        $admin = $this->memberOf(Company::factory()->create(), role: User::ROLE_ADMIN);

        $this->claim($customer, $this->request('customer', [$this->source($customer, 1)]));
        $this->claim($admin, $this->request('admin', [$this->source($admin, 1)]));

        $this->assertSame([2, 2], [CreativePackage::count(), count($this->dispatches)]);
    }

    public function test_a_same_key_replay_returns_the_first_package_and_does_not_dispatch(): void
    {
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 1)]);
        $first = $this->claim($actor, $request)->package;

        $replay = $this->claim($actor, $request);

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->id, $replay->package->id);
        $this->assertSame([1, 1], [CreativePackage::count(), CreativePackageItem::count()]);
        $this->assertCount(1, $this->dispatches);
    }

    public function test_every_claim_logs_one_line_that_says_whether_it_replayed(): void
    {
        Log::spy();
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 1), $this->source($actor, 2)]);

        $package = $this->claim($actor, $request)->package;
        $this->claim($actor, $request);

        $line = ['package_id' => $package->id, 'company_id' => $package->company_id, 'status' => CreativePackage::STATUS_BUILDING, 'items' => 2];
        Log::shouldHaveReceived('info')->with('creative_package.claimed', ['replayed' => false] + $line)->once();
        Log::shouldHaveReceived('info')->with('creative_package.claimed', ['replayed' => true] + $line)->once();
    }

    public function test_a_changed_payload_on_the_same_key_is_a_conflict(): void
    {
        $actor = $this->member();
        $sources = [$this->source($actor, 1), $this->source($actor, 2)];
        $package = $this->claim($actor, $this->request('key-1', $sources))->package;

        $exception = $this->rejected($actor, $this->request('key-1', array_reverse($sources)));

        $this->assertSame('creative_package_idempotency_conflict', $exception->codeName);
        $this->assertSame(['idempotency_key' => 'key-1', 'package_id' => $package->uuid], $exception->details);
        $this->assertClaimed(1, 2, 1);
    }

    public function test_a_new_claim_is_rejected_without_membership_entitlement_or_valid_input(): void
    {
        $noRole = $this->memberOf(Company::factory()->create(), role: 'viewer');
        $moved = $this->member();
        User::query()->whereKey($moved->id)->update(['company_id' => Company::factory()->create()->id]);
        $valid = $this->member();

        $cases = [
            ['creative_package_entitlement_required', false, [], $noRole, $this->request('k', [$this->source($noRole, 1)])],
            ['creative_package_entitlement_required', false, [], $moved, $this->request('k', [$this->source($moved, 1)])],
            ['creative_package_invalid_request', false, ['fields' => ['items.0.alt_text']], $valid, array_replace_recursive($this->request('k', [$this->source($valid, 1)]), ['items' => [['alt_text' => ' ']]])],
            ['creative_package_source_not_found', false, ['item_index' => [0], 'client_creative_id' => ['creative-0']], $valid, array_replace_recursive($this->request('k', [$this->source($valid, 2)]), ['items' => [['template_id' => (string) Str::uuid()]]])],
        ];
        foreach ($cases as [$code, $retryable, $details, $actor, $request]) {
            $exception = $this->rejected($actor, $request);
            $this->assertSame([$code, $retryable, $details], [$exception->codeName, $exception->retryable, $exception->details]);
        }
        $this->assertClaimed(0, 0, 0);
        Queue::assertNothingPushed();
    }

    public function test_the_active_limit_and_the_lease_honor_a_lower_config_and_clamp_a_higher_one(): void
    {
        $actor = $this->member();
        config()->set('creative-packages.limits.active_per_company', 1);
        config()->set('creative-packages.limits.lease_seconds', 60);
        CreativePackage::factory()->create(['company_id' => $actor->company_id, 'lease_expires_at' => $this->now()->subSecond()]);

        $package = $this->claim($actor, $this->request('key-1', [$this->source($actor, 1)]))->package;
        $exception = $this->rejected($actor, $this->request('key-2', [$this->source($actor, 2)]));

        $this->assertSame([true, 'creative_package_rate_limited', true, ['poll_after_seconds' => CreativePackageContract::HEARTBEAT_SECONDS]], [$package->lease_expires_at->equalTo($this->now()->addSeconds(60)), $exception->codeName, $exception->retryable, $exception->details]);
        $this->assertClaimed(2, 1, 1);
        config()->set('creative-packages.limits.active_per_company', 99);
        config()->set('creative-packages.limits.lease_seconds', CreativePackageContract::LEASE_SECONDS + 1);
        CreativePackage::factory()->count(CreativePackageContract::MAX_ACTIVE_PACKAGES - 1)->create(['company_id' => $actor->company_id, 'lease_expires_at' => $this->now()->addMinute()]);

        $this->assertSame('creative_package_rate_limited', $this->rejected($actor, $this->request('key-3', [$this->source($actor, 3)]))->codeName);
        $this->assertClaimed(4, 1, 1);
        CreativePackage::query()->update(['lease_expires_at' => $this->now()->subSecond()]);
        $clamped = $this->claim($actor, $this->request('key-3', [$this->source($actor, 4)]))->package;
        $this->assertTrue($clamped->lease_expires_at->equalTo($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS)));
    }
}
