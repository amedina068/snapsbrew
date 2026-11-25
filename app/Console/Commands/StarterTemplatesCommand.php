<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StarterTemplateInstaller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Adds the starter templates to the company of one user.
 *
 * Without --user, the command uses the owner of the instance. The command is
 * safe to run again: it skips each starter template that the company has.
 */
class StarterTemplatesCommand extends Command
{
    protected $signature = 'snapsbrew:starter-templates
        {--user= : The email of the user. The default is the owner of the instance.}';

    protected $description = 'Add the starter templates to the company of a user.';

    public function handle(StarterTemplateInstaller $installer): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }

        try {
            $result = $installer->install($user);
        } catch (InvalidArgumentException|AuthorizationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['created'] as $template) {
            $this->components->twoColumnDetail($template->title, '<fg=green>ADDED</>');
        }

        foreach ($result['skipped'] as $title) {
            $this->components->twoColumnDetail($title, '<fg=yellow>ALREADY THERE</>');
        }

        foreach ($result['preview_failures'] as $title) {
            $this->components->warn("The preview image of \"{$title}\" did not render. The template works, but the template list shows no preview image for it. Read the log for the render error.");
        }

        $this->components->twoColumnDetail('User', $user->email);
        $this->components->info(sprintf(
            'Added %d starter templates. Skipped %d.',
            count($result['created']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($email = $this->option('user')) {
            $user = User::query()->where('email', strtolower(trim($email)))->first();

            if (! $user) {
                $this->components->error("No user has the email {$email}.");
            }

            return $user;
        }

        $owner = User::query()->where('is_owner', true)->orderBy('id')->first();

        if (! $owner) {
            $this->components->error('This instance has no owner. Run snapsbrew:install first, or give a user with --user=<email>.');
        }

        return $owner;
    }
}
