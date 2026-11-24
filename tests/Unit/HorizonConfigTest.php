<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The operator sets the worker count with HORIZON_MAX_PROCESSES, not with an
 * edit of config/horizon.php. The one supervisor reads every queue, the
 * `renders` queue first.
 */
class HorizonConfigTest extends TestCase
{
    public function test_the_environment_variable_sets_the_process_count_in_every_environment(): void
    {
        $config = $this->horizonConfigWith('6');

        $this->assertSame(6, $config['environments']['production']['supervisor-1']['maxProcesses']);
        $this->assertSame(6, $config['environments']['local']['supervisor-1']['maxProcesses']);
    }

    public function test_without_the_variable_the_old_counts_stay(): void
    {
        $config = $this->horizonConfigWith(null);

        $this->assertSame(10, $config['environments']['production']['supervisor-1']['maxProcesses']);
        $this->assertSame(3, $config['environments']['local']['supervisor-1']['maxProcesses']);
    }

    private function horizonConfigWith(?string $value): array
    {
        $saved = getenv('HORIZON_MAX_PROCESSES');

        $value === null ? putenv('HORIZON_MAX_PROCESSES') : putenv("HORIZON_MAX_PROCESSES={$value}");
        unset($_ENV['HORIZON_MAX_PROCESSES'], $_SERVER['HORIZON_MAX_PROCESSES']);

        try {
            return require config_path('horizon.php');
        } finally {
            $saved === false ? putenv('HORIZON_MAX_PROCESSES') : putenv("HORIZON_MAX_PROCESSES={$saved}");
        }
    }
}
