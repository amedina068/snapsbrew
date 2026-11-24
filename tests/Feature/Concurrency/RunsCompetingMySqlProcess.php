<?php

namespace Tests\Feature\Concurrency;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Snap;
use Illuminate\Support\Facades\DB;
use Tests\Support\RequiresMysql;

/**
 * Two real MySQL connections for one race. The parent test holds one
 * connection. A child PHP process, started with proc_open, holds the second
 * one and runs one PHP body against the same schema. Files next to the
 * barrier path carry the signals: `.ready` when the child starts its body,
 * `.done` with the JSON result, `.failed` with the JSON exception.
 *
 * A test that uses this trait cannot use RefreshDatabase. The child must see
 * committed fixtures, and a wrapping transaction hides them. Every test
 * deletes its own company in a finally block. The suite skips outside MySQL,
 * and it fails instead when REQUIRE_MYSQL_LEDGER_GATE is set.
 */
trait RunsCompetingMySqlProcess
{
    use RequiresMysql;

    private string $barrier = '';

    /** @var resource|null */
    private $process = null;

    /** Skip or fail outside MySQL (RequiresMysql), then open this race's barrier path. */
    private function requireMysqlBarrier(): void
    {
        $this->requireMysql();
        $this->barrier = tempnam(sys_get_temp_dir(), 'creative-package-race-');
        @unlink($this->barrier);
    }

    /**
     * Start the child. The body runs inside a closure that receives `$args`,
     * `$now` (a CarbonImmutable) and `$wait` (a closure that blocks on one
     * barrier suffix and throws after ten seconds), and it returns the JSON result.
     *
     * @param  array<string, mixed>  $args
     */
    private function startCompetingProcess(string $body, array $args): void
    {
        $script = <<<'PHP_SCRIPT'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['queue.default' => 'sync']);
Illuminate\Support\Facades\Queue::fake();
$barrier = $argv[2];
$args = json_decode($argv[3], true);
$now = Carbon\CarbonImmutable::parse($args['now']);
$wait = function (string $suffix) use ($barrier): void {
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier.$suffix)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException("The child waited ten seconds for the barrier {$suffix} and gave up.");
        }
        usleep(10_000);
    }
};

try {
    file_put_contents($barrier.'.ready', '1');
    $result = (function () use ($args, $now, $wait, $barrier) {
        BODY_PLACEHOLDER
    })();
    file_put_contents($barrier.'.done', json_encode($result));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($barrier.'.failed', json_encode([
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'mysql_error' => $exception instanceof Illuminate\Database\QueryException ? ($exception->errorInfo[1] ?? null) : null,
        'code_name' => $exception instanceof App\Services\CreativePackageClaimException ? $exception->codeName : null,
    ]));
    exit(1);
}
PHP_SCRIPT;
        $script = str_replace('BODY_PLACEHOLDER', $body, $script);

        $process = proc_open(
            [PHP_BINARY, '-r', $script, base_path(), $this->barrier, json_encode($args)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            $this->fail('Could not start a competing MySQL process.');
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $this->process = $process;

        $this->waitForFile('.ready');
    }

    /** Touch one barrier file the child waits on. */
    private function signal(string $suffix): void
    {
        file_put_contents($this->barrier.$suffix, '1');
    }

    private function waitForFile(string $suffix, float $seconds = 5): void
    {
        $deadline = microtime(true) + $seconds;
        while (! file_exists($this->barrier.$suffix) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($this->barrier.$suffix);
    }

    /** The child is still inside its body after a grace period. This proves it waits on a lock the parent holds. */
    private function assertChildIsBlocked(): void
    {
        usleep(300_000);
        $this->assertFileDoesNotExist($this->barrier.'.done', 'The child finished while the parent held the lock.');
        $this->assertFileDoesNotExist($this->barrier.'.failed', 'The child failed while the parent held the lock: '.$this->childFailure());
    }

    /**
     * Wait for the child and return its outcome as [exit code, result or failure].
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function finishCompetingProcess(): array
    {
        $exitCode = $this->waitForProcess();
        $this->process = null;
        if (file_exists($this->barrier.'.done')) {
            return [$exitCode, (array) json_decode((string) file_get_contents($this->barrier.'.done'), true)];
        }

        return [$exitCode, (array) json_decode($this->childFailure(), true)];
    }

    /** @return array<string, mixed> */
    private function childSucceeded(): array
    {
        [$exitCode, $outcome] = $this->finishCompetingProcess();
        $this->assertSame(0, $exitCode, 'The competing process failed: '.json_encode($outcome));

        return $outcome;
    }

    /** @return array<string, mixed> */
    private function childFailed(): array
    {
        [$exitCode, $outcome] = $this->finishCompetingProcess();
        $this->assertSame(1, $exitCode, 'The competing process succeeded: '.json_encode($outcome));

        return $outcome;
    }

    private function childFailure(): string
    {
        return file_exists($this->barrier.'.failed') ? (string) file_get_contents($this->barrier.'.failed') : '{}';
    }

    private function waitForProcess(): int
    {
        $deadline = microtime(true) + 15;
        do {
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                proc_close($this->process);

                return $status['exitcode'];
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        proc_terminate($this->process);
        proc_close($this->process);
        $this->fail('The competing process did not complete within fifteen seconds.');
    }

    /** Roll back an open parent transaction, reap the child, delete the fixtures and the barrier files. */
    private function tearDownRace(?Company $company): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if ($company !== null) {
            ApiLog::query()->whereIn('user_id', $company->users()->pluck('id'))->delete();
            Snap::query()->where('company_id', $company->id)->delete();
            $company->delete();
        }
        foreach (['', '.ready', '.done', '.failed', '.gated', '.go'] as $suffix) {
            @unlink($this->barrier.$suffix);
        }
    }
}
