<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\TemplateVersioning;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * This deliberately does not use RefreshDatabase: a second MySQL connection
 * must see committed fixtures before the parent begins its lock transaction.
 * CI's SQLite suite skips it; execute it in the MySQL integration lane after
 * `migrate:fresh`.
 */
class MySqlTemplateVersionLockTest extends TestCase
{
    public function test_competing_publish_waits_for_the_template_row_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This lock-contended integration test requires MySQL.');
        }

        Bus::fake();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
        $barrier = tempnam(sys_get_temp_dir(), 'template-version-lock-');
        @unlink($barrier);
        $process = null;
        $transactionCommitted = false;
        $childReaped = false;

        try {
            DB::beginTransaction();
            Template::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            $process = $this->startCompetingPublish($template->id, $barrier);

            $this->waitForFile("{$barrier}.ready");

            // Without lockForUpdate in publish(), this child can finish while
            // the parent holds the template row. With it, it must wait.
            usleep(300_000);
            $this->assertFileDoesNotExist("{$barrier}.done");

            DB::commit();
            $transactionCommitted = true;
            $exitCode = $this->waitForProcess($process);
            $childReaped = true;

            $this->assertSame(0, $exitCode, file_exists("{$barrier}.failed")
                ? file_get_contents("{$barrier}.failed")
                : 'The competing publish failed.');

            app(TemplateVersioning::class)->publish($template->fresh());

            $this->assertSame([1, 2], TemplateVersion::where('template_id', $template->id)
                ->orderBy('version_number')
                ->pluck('version_number')
                ->all());
        } finally {
            if (! $transactionCommitted && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (is_resource($process) && ! $childReaped) {
                $this->waitForProcess($process);
            }

            TemplateVersion::where('template_id', $template->id)->get()
                ->each(function (TemplateVersion $version): void {
                    $version->clearMediaCollection('preview');
                    $version->delete();
                });
            $template->delete();
            $user->delete();
            $company->delete();
            Auth::logout();

            foreach (['', '.ready', '.done', '.failed'] as $suffix) {
                @unlink("{$barrier}{$suffix}");
            }
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 2;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path);
    }

    /** @return resource */
    private function startCompetingPublish(int $templateId, string $barrier)
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['queue.default' => 'sync']);
app()->bind(App\Contracts\SnapRenderer::class, Tests\Support\FakeRenderer::class);

try {
    file_put_contents($argv[3].'.ready', '1');
    app(App\Services\TemplateVersioning::class)->publish(
        App\Models\Template::query()->findOrFail((int) $argv[2])
    );
    file_put_contents($argv[3].'.done', '1');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($argv[3].'.failed', $exception->getMessage());
    exit(1);
}
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, base_path(), (string) $templateId, $barrier],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            $this->fail('Could not start a competing MySQL process.');
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return $process;
    }

    /** @param resource $process */
    private function waitForProcess($process): int
    {
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                proc_close($process);

                return $exitCode;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        $this->fail('The competing publish did not complete within five seconds.');
    }
}
