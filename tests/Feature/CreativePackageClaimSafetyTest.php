<?php

namespace Tests\Feature;

use App\Models\CreativePackageItem;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CreativePackageClaimResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BuildsCreativePackages;
use Tests\TestCase;

/**
 * Proves the claim writes all or nothing, dispatches only after its own commit
 * and resolves a lost unique-key race without a retry loop.
 */
class CreativePackageClaimSafetyTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    private ?CreativePackageClaimResult $winner = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordDispatches();
    }

    public function test_a_duplicate_key_loser_reloads_the_winner_as_a_replay(): void
    {
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 1)]);
        $this->winnerClaimsDuringTheReplayLookup($actor, $request);

        $result = $this->claim($actor, $request);

        $this->assertSame([false, true], [$this->winner->replayed, $result->replayed]);
        $this->assertSame($this->winner->package->id, $result->package->id);
        $this->assertClaimed(1, 1, 1);
        $this->assertSame($this->winner->package->id, $this->dispatches[0]['package_id']);
    }

    /**
     * The simulated winner shares the loser's transaction, so the rollback removes it too.
     * The "winner survives, loser writes nothing" half is asserted by
     * CreativePackageClaimTest::test_a_changed_payload_on_the_same_key_is_a_conflict.
     * Do not strengthen the assertClaimed(0, 0, 0) below.
     */
    public function test_a_duplicate_key_loser_with_another_payload_is_a_conflict(): void
    {
        $actor = $this->member();
        $sources = [$this->source($actor, 1), $this->source($actor, 2)];
        $this->winnerClaimsDuringTheReplayLookup($actor, $this->request('key-1', array_reverse($sources)));

        $exception = $this->rejected($actor, $this->request('key-1', $sources));

        $this->assertSame('creative_package_idempotency_conflict', $exception->codeName);
        $this->assertSame(['idempotency_key' => 'key-1', 'package_id' => $this->winner->package->uuid], $exception->details);
        // The simulated winner shares the loser's transaction, so the rollback removes it too.
        $this->assertClaimed(0, 0, 0);
    }

    public function test_an_item_insert_failure_rolls_back_the_package_and_dispatches_nothing(): void
    {
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 1), $this->source($actor, 2)]);
        CreativePackageItem::creating(fn (CreativePackageItem $item) => $item->position === 1 ? throw new RuntimeException('forced item insert failure') : null);

        $this->expectExceptionMessage('forced item insert failure');
        try {
            $this->claim($actor, $request);
        } finally {
            $this->assertClaimed(0, 0, 0);
        }
    }

    public function test_an_outer_rollback_removes_the_rows_and_the_after_commit_dispatch(): void
    {
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 1)]);

        try {
            DB::transaction(function () use ($actor, $request): void {
                $this->claim($actor, $request);
                $this->assertClaimed(1, 1, 0);
                throw new RuntimeException('outer rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertClaimed(0, 0, 0);

        $later = $this->claim($actor, $this->request('key-2', [$this->source($actor, 2)]));

        // The discarded callback never fires later. Only the new claim dispatches.
        $this->assertSame([$later->package->id], array_column($this->dispatches, 'package_id'));
    }

    public function test_an_invalid_source_is_refused_before_the_company_lock_and_writes_nothing(): void
    {
        $actor = $this->member();
        $unpublished = $this->source($actor, 1);
        TemplateVersion::query()->whereKey($unpublished->id)->update(['published' => false]);
        $request = $this->request('key-1', [$unpublished]);
        $queries = $this->recordQueries();

        $exception = $this->rejected($actor, $request);

        $this->assertSame(['creative_package_source_invalid', ['issues' => [['item_index' => 0, 'client_creative_id' => 'creative-0', 'code' => 'creative_package_source_invalid', 'issues' => ['source_unpublished']]]]], [$exception->codeName, $exception->details]);
        $this->assertClaimed(0, 0, 0);
        $this->assertSame([], array_values(array_filter($queries->all(), fn (string $sql) => str_contains($sql, 'from companies'))));
    }

    public function test_source_preflight_runs_before_the_company_lock(): void
    {
        $actor = $this->member();
        $request = $this->request('key-1', [$this->source($actor, 1)]);
        $queries = $this->recordQueries();

        $this->claim($actor, $request);

        $lock = $queries->search(fn (string $sql) => str_contains($sql, 'from companies'));
        $preflight = $queries->search(fn (string $sql) => str_contains($sql, 'from templates'));
        $versions = $queries->search(fn (string $sql) => str_contains($sql, 'from template_versions'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($preflight);
        $this->assertNotFalse($versions);
        $this->assertTrue($preflight < $versions && $versions < $lock, "Preflight at {$preflight}/{$versions}, lock at {$lock}");
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringEndsWith('for update', $queries[$lock]);
        }
    }

    public function test_the_same_key_in_another_company_is_a_new_package(): void
    {
        $first = $this->member();
        $second = $this->member();

        $one = $this->claim($first, $this->request('shared-key', [$this->source($first, 1)]));
        $two = $this->claim($second, $this->request('shared-key', [$this->source($second, 1)]));

        $this->assertSame([false, false], [$one->replayed, $two->replayed]);
        $this->assertNotSame($one->package->id, $two->package->id);
        $this->assertSame([$first->company_id, $second->company_id], [$one->package->company_id, $two->package->company_id]);
        $this->assertClaimed(2, 2, 2);
    }

    /**
     * Simulates a lost race on the unique claim key. The winner's claim runs from
     * inside the loser's replay lookup, so the winner's row exists before the
     * loser's insert and after the loser's lookup. The hook runs one time.
     */
    private function winnerClaimsDuringTheReplayLookup(User $actor, array $request): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $event) use ($actor, $request, &$armed): void {
            // Matches the WHERE built in CreativePackageClaim::existing(). Update this string if that query changes.
            if ($armed && str_starts_with($event->sql, 'select') && str_contains($event->sql, 'idempotency_key')) {
                $armed = false;
                $this->winner = $this->claim($actor, $request);
            }
        });
    }

    /** @return Collection<int, string> */
    private function recordQueries(): Collection
    {
        $queries = collect();
        DB::listen(fn (QueryExecuted $event) => $queries->push(strtolower(str_replace(['`', '"'], '', $event->sql))));

        return $queries;
    }
}
