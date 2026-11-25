<?php

namespace App\Support\Instance;

use App\Models\InstanceSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The last time that the queue worker and the scheduler did work.
 *
 * The scheduler writes its own beat each minute and dispatches
 * `RecordQueueHeartbeat`, which a queue worker writes. `snapsbrew:status` and
 * `snapsbrew:doctor` read the two values.
 *
 * The values live in the `instance_settings` table, because the database is
 * the one store that the web, worker and scheduler processes always share. A
 * write goes to the table directly and does not clear the settings cache.
 */
class Heartbeats
{
    public const QUEUE = 'heartbeat:queue';

    public const SCHEDULER = 'heartbeat:scheduler';

    /** A beat older than this many seconds is stale. */
    public const STALE_AFTER_SECONDS = 300;

    public static function beat(string $key): void
    {
        InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => now()->toIso8601String()]);
    }

    public static function last(string $key): ?CarbonImmutable
    {
        try {
            if (! Schema::hasTable('instance_settings')) {
                return null;
            }

            $value = InstanceSetting::query()->whereKey($key)->value('value');
        } catch (Throwable) {
            return null;
        }

        return $value ? CarbonImmutable::parse($value) : null;
    }

    public static function isFresh(?CarbonImmutable $at): bool
    {
        return $at !== null && $at->greaterThan(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }
}
