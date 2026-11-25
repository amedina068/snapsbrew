<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Support\InstanceSetup;
use Illuminate\Console\Command;

/**
 * Prints the URL of the web setup page while the instance has no account.
 *
 * The URL holds the setup token. The Docker image prints it to the log of the
 * web container when the container starts. The entrypoint reads the text
 * output, so keep it as one line with the URL.
 */
class SetupUrlCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:setup-url
        {--json : Write one JSON object to stdout}';

    protected $description = 'Print the URL of the setup page that creates the owner account.';

    public function handle(): int
    {
        if (! InstanceSetup::isNeeded()) {
            $this->note('An account already exists. The setup page is closed.');

            return $this->respond(self::EXIT_OK, ['setup_needed' => false, 'url' => null]);
        }

        if (! $this->wantsJson()) {
            $this->line(InstanceSetup::url());
        }

        return $this->respond(self::EXIT_OK, ['setup_needed' => true, 'url' => InstanceSetup::url()]);
    }
}
