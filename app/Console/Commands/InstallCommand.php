<?php

namespace App\Console\Commands;

use App\Actions\Instance\CreateInstanceOwner;
use App\Models\User;
use App\Support\StarterTemplates;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Prepares a new self-hosted instance and creates its owner.
 *
 * The command is safe to run again. It creates the Passport keys and the
 * storage link only when they are missing, and it creates no second owner.
 */
class InstallCommand extends Command
{
    protected $signature = 'snapsbrew:install
        {--name= : The name of the owner}
        {--email= : The email of the owner}
        {--password= : The password of the owner}
        {--company= : The company name of the owner}
        {--promote= : Make the existing user with this email the owner, and create no account}
        {--demote= : Remove the owner flag from the user with this email. The last owner stays}
        {--prepare : Create only the Passport keys and the storage link, and create no account}
        {--no-starter-templates : Do not add the starter templates to the owner account}';

    protected $description = 'Prepare this instance and create the owner account.';

    public function handle(CreateInstanceOwner $owners): int
    {
        $this->preparePassportKeys();
        $this->prepareStorageLink();

        if ($this->option('prepare')) {
            return self::SUCCESS;
        }

        if ($email = $this->option('promote')) {
            return $this->promote($email);
        }

        if ($email = $this->option('demote')) {
            return $this->demote($email);
        }

        if ($owner = User::query()->where('is_owner', true)->first()) {
            $this->components->info("An owner already exists ({$owner->email}). No account was created.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: text('Owner name', required: true);
        $email = $this->option('email') ?: text('Owner email', required: true);
        $password = $this->option('password') ?: password('Owner password', required: true);
        try {
            $owner = $owners->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'company' => $this->option('company'),
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->components->info("The owner account {$owner->email} is ready. Sign in at ".url('/login').'.');

        $this->offerStarterTemplates($owner);

        return self::SUCCESS;
    }

    /**
     * The answer defaults to yes, so a run with --no-interaction adds them.
     */
    private function offerStarterTemplates(User $owner): void
    {
        if ($this->option('no-starter-templates')) {
            return;
        }

        $count = count(StarterTemplates::all());

        if (! confirm("Add the {$count} starter templates to the owner account?", default: true)) {
            $this->components->info('No starter templates were added. To add them later, run php artisan snapsbrew:starter-templates.');

            return;
        }

        $this->call('snapsbrew:starter-templates', ['--user' => $owner->email]);
    }

    private function promote(string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->components->error("No user has the email {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_owner' => true])->save();
        $this->components->info("{$user->email} is now the owner of this instance.");

        return self::SUCCESS;
    }

    /**
     * An instance with no owner has nobody who can open /admin or Horizon, so
     * the last owner keeps the flag.
     */
    private function demote(string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->components->error("No user has the email {$email}.");

            return self::FAILURE;
        }

        if (! $user->isOwner()) {
            $this->components->error("{$user->email} is not an owner.");

            return self::FAILURE;
        }

        if (User::query()->where('is_owner', true)->count() === 1) {
            $this->components->error("{$user->email} is the last owner. Promote another user first.");

            return self::FAILURE;
        }

        $user->forceFill(['is_owner' => false])->save();
        $this->components->info("{$user->email} is no longer an owner.");

        return self::SUCCESS;
    }

    /**
     * The OAuth flow of the MCP server signs its tokens with these keys. A
     * deploy can also give the keys as PASSPORT_PRIVATE_KEY and
     * PASSPORT_PUBLIC_KEY, and then no file is needed.
     */
    private function preparePassportKeys(): void
    {
        if (config('passport.private_key') || file_exists(Passport::keyPath('oauth-private.key'))) {
            return;
        }

        $this->call('passport:keys');
    }

    private function prepareStorageLink(): void
    {
        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }
    }
}
