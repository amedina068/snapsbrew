<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Support\Instance\InstanceDoctor;
use Illuminate\Console\Command;

/**
 * Runs the health checks of `InstanceDoctor`. It exits 1 when a check fails.
 * A warning does not change the exit code. `--fix` runs only the safe fixes:
 * the storage link, the Passport keys and the font cache folder.
 */
class DoctorCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:doctor
        {--fix : Run the safe fixes (storage link, Passport keys, font cache folder)}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Check this instance and show how to correct each problem.';

    public function handle(InstanceDoctor $doctor): int
    {
        $checks = $doctor->run((bool) $this->option('fix'));
        $summary = InstanceDoctor::summary($checks);
        $failed = array_values(array_filter($checks, fn (array $check) => $check['status'] === InstanceDoctor::FAIL));

        if (! $this->wantsJson()) {
            foreach ($checks as $check) {
                $label = match ($check['status']) {
                    InstanceDoctor::OK => '<fg=green>OK</>',
                    InstanceDoctor::WARN => '<fg=yellow>WARN</>',
                    default => '<fg=red>FAIL</>',
                };
                $this->components->twoColumnDetail($check['id'].'  <fg=gray>'.$check['message'].'</>', $label);

                if ($check['fix'] !== '') {
                    $this->line('    <fg=gray>Fix: '.$check['fix'].'</>');
                }
            }

            $this->newLine();
            $this->line(sprintf('  %d ok, %d warn, %d fail.', $summary['ok'], $summary['warn'], $summary['fail']));
        }

        return $this->respond(
            $failed === [] ? self::EXIT_OK : self::EXIT_FAILED,
            ['checks' => $checks, 'summary' => $summary],
            array_map(fn (array $check) => self::errorItem($check['id'], $check['message'], $check['fix']), $failed),
        );
    }
}
