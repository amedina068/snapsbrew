<?php

namespace App\Console\Commands;

use App\Actions\Instance\CreateInstanceOwner;
use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Jobs\InstallStarterTemplates;
use App\Models\Company;
use App\Models\User;
use App\Support\Instance\ApiTokens;
use App\Support\Instance\LoginLink;
use App\Support\Instance\McpConnect;
use App\Support\StarterTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Prepares a new self-hosted instance and creates its owner.
 *
 * The command is safe to run again. It creates the Passport keys and the
 * storage link only when they are missing, and it creates no second owner. A
 * second run returns `"status":"exists"` and exits 0.
 *
 * The owner gets a random password that nobody sees. The command returns a
 * one-time `login_url` instead. `--password` still works, but it is deprecated:
 * a password on the command line stays in the shell history.
 *
 * `--prepare` and its text output are part of the Docker entrypoint
 * (`deploy/docker/entrypoint.d/60-snapsbrew-setup.sh`). Keep them stable.
 */
class InstallCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:install
        {--name= : The name of the owner}
        {--email= : The email of the owner}
        {--company= : The company name of the owner}
        {--with-token= : Also create an API token with this name, and return the MCP connect commands}
        {--password= : Deprecated. The password of the owner. Without it, the owner signs in with the login_url}
        {--promote= : Make the existing user with this email the owner, and create no account}
        {--demote= : Remove the owner flag from the user with this email. The last owner stays}
        {--prepare : Create only the Passport keys and the storage link, and create no account}
        {--no-starter-templates : Do not add the starter templates to the owner account}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Prepare this instance and create the owner account.';

    /** @var array<string, string> */
    private array $prepared = [];

    public function handle(CreateInstanceOwner $owners): int
    {
        $this->preparePassportKeys();
        $this->prepareStorageLink();

        if ($this->option('prepare')) {
            return $this->respond(self::EXIT_OK, ['status' => 'prepared', 'prepared' => $this->prepared]);
        }

        if ($email = $this->option('promote')) {
            return $this->promote($email);
        }

        if ($email = $this->option('demote')) {
            return $this->demote($email);
        }

        if ($owner = User::query()->where('is_owner', true)->orderBy('id')->first()) {
            $this->note("An owner already exists ({$owner->email}). No account was created.");

            return $this->finish($owner, 'exists');
        }

        $input = $this->ownerInput();

        if (is_int($input)) {
            return $input;
        }

        try {
            $owner = $owners->create($input);
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->all();

            if (! $this->wantsJson()) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return $this->respond(self::EXIT_BAD_INPUT, ['status' => 'invalid'], array_map(
                fn (string $message) => self::errorItem('invalid_input', $message, 'Correct the option and run the command again.'),
                $messages,
            ));
        }

        $this->note("The owner account {$owner->email} is ready.");

        return $this->finish($owner, 'created', $this->offerStarterTemplates($owner));
    }

    /**
     * @return array{name: string, email: string, password: string, company: string|null}|int
     */
    private function ownerInput(): array|int
    {
        $interactive = $this->input->isInteractive() && ! $this->wantsJson();
        $name = $this->option('name') ?: ($interactive ? text('Owner name', required: true) : null);
        $email = $this->option('email') ?: ($interactive ? text('Owner email', required: true) : null);

        if (! $name || ! $email) {
            return $this->failWith(
                self::EXIT_BAD_INPUT,
                'missing_option',
                'Give --name and --email to create the owner.',
                'Run php artisan snapsbrew:install --name="Your Name" --email=you@example.com --json.',
            );
        }

        if ($this->option('password') !== null) {
            $this->warnUser('--password is deprecated. The password stays in the shell history. Use the login_url instead.');
        }

        return [
            'name' => $name,
            'email' => $email,
            // Nobody sees the random password. The owner signs in with the
            // one-time login URL, and can set a password later.
            'password' => $this->option('password') ?? Str::password(40),
            'company' => $this->option('company'),
        ];
    }

    /**
     * Writes the result of a run that has an owner.
     */
    private function finish(User $owner, string $status, ?string $starterTemplates = null): int
    {
        $data = [
            'status' => $status,
            'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'company' => Company::query()->whereKey($owner->company_id)->value('name')],
            'app_url' => (string) config('app.url'),
            'login_url' => LoginLink::create($owner),
            'login_url_expires_in_minutes' => LoginLink::MINUTES,
            'password_set' => $status === 'created' ? $this->option('password') !== null : null,
            'starter_templates' => $starterTemplates,
            'prepared' => $this->prepared,
        ];

        if (($tokenName = $this->option('with-token')) !== null) {
            if (trim((string) $tokenName) === '') {
                return $this->failWith(self::EXIT_BAD_INPUT, 'invalid_token_name', 'The --with-token option needs a name.', 'Run the command again with --with-token=agent.', $data);
            }

            $token = ApiTokens::create($owner, (string) $tokenName);
            $data['token'] = ApiTokens::describe($token->accessToken) + ['value' => $token->plainTextToken];
            $data['connect'] = McpConnect::summary($token->plainTextToken);
        }

        if (! $this->wantsJson()) {
            $this->line('');
            $this->line('  Sign in one time with this link. It expires in '.LoginLink::MINUTES.' minutes:');
            $this->line("  {$data['login_url']}");

            if (isset($data['token'])) {
                $this->line('');
                $this->line("  API token \"{$data['token']['name']}\": {$data['token']['value']}");
                $this->line('  Keep this token now. The command does not show it again.');
                $this->line('');
                $this->line('  Connect Claude Code:');
                $this->line('  '.$data['connect']['claude']);
            }

            $this->line('');
        }

        return $this->respond(self::EXIT_OK, $data);
    }

    /**
     * The interactive answer defaults to yes, so a run with --no-interaction
     * adds them. In JSON mode, the templates go to the queue, so the command
     * does not wait for six preview renders.
     */
    private function offerStarterTemplates(User $owner): string
    {
        if ($this->option('no-starter-templates')) {
            return 'skipped';
        }

        if ($this->wantsJson()) {
            try {
                InstallStarterTemplates::dispatch($owner);
            } catch (Throwable $exception) {
                report($exception);
                $this->warnUser('The starter templates were not added. Run php artisan snapsbrew:starter-templates later.');

                return 'failed';
            }

            return 'queued';
        }

        $count = count(StarterTemplates::all());

        if (! confirm("Add the {$count} starter templates to the owner account?", default: true)) {
            $this->components->info('No starter templates were added. To add them later, run php artisan snapsbrew:starter-templates.');

            return 'skipped';
        }

        $this->call('snapsbrew:starter-templates', ['--user' => $owner->email]);

        return 'installed';
    }

    private function promote(string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$email}.", 'Run php artisan snapsbrew:user:list to find the email.');
        }

        $changed = ! $user->isOwner();
        $user->forceFill(['is_owner' => true])->save();
        $this->note("{$user->email} is now the owner of this instance.");

        return $this->respond(self::EXIT_OK, ['status' => $changed ? 'promoted' : 'unchanged', 'user' => ['id' => $user->id, 'email' => $user->email]]);
    }

    /**
     * An instance with no owner has nobody who can open /admin or Horizon, so
     * the last owner keeps the flag.
     */
    private function demote(string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$email}.", 'Run php artisan snapsbrew:user:list to find the email.');
        }

        if (! $user->isOwner()) {
            return $this->failWith(self::EXIT_CONFLICT, 'not_an_owner', "{$user->email} is not an owner.", 'Give the email of an owner.');
        }

        if (User::query()->where('is_owner', true)->count() === 1) {
            return $this->failWith(self::EXIT_CONFLICT, 'last_owner', "{$user->email} is the last owner. Promote another user first.", 'Run php artisan snapsbrew:install --promote=<email> for another user first.');
        }

        $user->forceFill(['is_owner' => false])->save();
        $this->note("{$user->email} is no longer an owner.");

        return $this->respond(self::EXIT_OK, ['status' => 'demoted', 'user' => ['id' => $user->id, 'email' => $user->email]]);
    }

    /**
     * The OAuth flow of the MCP server signs its tokens with these keys. A
     * deploy can also give the keys as PASSPORT_PRIVATE_KEY and
     * PASSPORT_PUBLIC_KEY, and then no file is needed.
     */
    private function preparePassportKeys(): void
    {
        if (config('passport.private_key')) {
            $this->prepared['passport_keys'] = 'environment';

            return;
        }

        if (file_exists(Passport::keyPath('oauth-private.key'))) {
            $this->prepared['passport_keys'] = 'exists';

            return;
        }

        $this->wantsJson() ? $this->callSilently('passport:keys') : $this->call('passport:keys');
        $this->prepared['passport_keys'] = 'created';
    }

    private function prepareStorageLink(): void
    {
        if (file_exists(public_path('storage'))) {
            $this->prepared['storage_link'] = 'exists';

            return;
        }

        $this->wantsJson() ? $this->callSilently('storage:link') : $this->call('storage:link');
        $this->prepared['storage_link'] = 'created';
    }

    private function warnUser(string $message): void
    {
        $this->wantsJson() ? $this->stderr()->writeln("WARN: {$message}") : $this->components->warn($message);
    }
}
