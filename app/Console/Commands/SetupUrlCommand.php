<?php

namespace App\Console\Commands;

use App\Support\InstanceSetup;
use Illuminate\Console\Command;

/**
 * Prints the URL of the web setup page while the instance has no account.
 *
 * The URL holds the setup token. The Docker image prints it to the log of the
 * web container when the container starts.
 */
class SetupUrlCommand extends Command
{
    protected $signature = 'snapsbrew:setup-url';

    protected $description = 'Print the URL of the setup page that creates the owner account.';

    public function handle(): int
    {
        if (! InstanceSetup::isNeeded()) {
            $this->components->info('An account already exists. The setup page is closed.');

            return self::SUCCESS;
        }

        $this->line(InstanceSetup::url());

        return self::SUCCESS;
    }
}
