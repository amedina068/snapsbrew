<?php

namespace App\Services;

use App\Events\FirstSnapCreated;
use App\Models\Snap;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records the first time a company completes a self-made snap that counts in
 * the usage log.
 *
 * Hot path is a column-only SELECT on the companies PK — once the
 * milestone is set, every subsequent snap completion costs one cheap
 * indexed read and exits. Imports and onboarding showcases never count.
 */
class FirstSnapMilestone
{
    public static function recordIfFirst(Snap $snap): void
    {
        if (! self::isEligible($snap)) {
            return;
        }

        if (self::alreadyRecorded($snap->company_id)) {
            return;
        }

        if (! self::markAtomically($snap->company_id)) {
            return; // Lost the race with a concurrent job — that's fine.
        }

        $user = User::find($snap->user_id);

        if ($user) {
            event(new FirstSnapCreated($user, $snap));
        }
    }

    private static function isEligible(Snap $snap): bool
    {
        if ($snap->snap_import_id) {
            return false;
        }

        return data_get($snap->metadata, 'onboarding') !== 'showcase';
    }

    /**
     * Column-only read on indexed PK — sub-millisecond and lock-free,
     * which is what every snap-after-the-first should cost.
     */
    private static function alreadyRecorded(?int $companyId): bool
    {
        if (! $companyId) {
            return true;
        }

        return DB::table('companies')
            ->where('id', $companyId)
            ->value('first_snap_at') !== null;
    }

    /**
     * `UPDATE ... WHERE first_snap_at IS NULL` so two concurrent jobs can
     * race here without double-firing the event. The loser sees zero
     * affected rows and bails.
     */
    private static function markAtomically(int $companyId): bool
    {
        return DB::table('companies')
            ->where('id', $companyId)
            ->whereNull('first_snap_at')
            ->update(['first_snap_at' => now()]) === 1;
    }
}
