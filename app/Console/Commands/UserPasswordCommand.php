<?php

namespace App\Console\Commands;

use App\Actions\Fortify\ResetUserPassword;
use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;

/**
 * Sets a new password for a user without email, for an instance with no SMTP.
 *
 * The password goes through the same rules and the same action as the
 * "forgot password" form. The command then ends the other logins of the user.
 */
class UserPasswordCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:user:password
        {email : The email of the user}
        {--password= : The new password. The command does not prompt}
        {--generate : Make a random password and show it one time}
        {--reset-two-factor : Turn off two-factor authentication for a user who lost the device}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Set a new password for a user, without email.';

    public function handle(ResetUserPassword $resetter): int
    {
        $user = User::query()->where('email', strtolower(trim($this->argument('email'))))->first();

        if (! $user) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'user_not_found', "No user has the email {$this->argument('email')}.", 'Run php artisan snapsbrew:user:list to find the email.');
        }

        if ($this->option('password') !== null && $this->option('generate')) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'conflicting_options', 'Use --password or --generate, not the two together.', 'Run the command again with one of the two options.');
        }

        $password = $this->newPassword();

        if (is_int($password)) {
            return $password;
        }

        try {
            $resetter->reset($user, ['password' => $password]);
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->all();

            if (! $this->wantsJson()) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return $this->respond(self::EXIT_BAD_INPUT, [], array_map(
                fn (string $message) => self::errorItem('invalid_password', $message, 'Choose a password that meets the rule, or use --generate.'),
                $messages,
            ));
        }

        $user->forceFill(['remember_token' => Str::random(60)]);

        if ($this->option('reset-two-factor')) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
        }

        $user->save();

        [$endedSessions, $sessionsMessage] = $this->endWebSessions($user);

        if ($this->wantsJson()) {
            return $this->respond(self::EXIT_OK, [
                'user' => ['id' => $user->id, 'email' => $user->email],
                'password_changed' => true,
                'generated_password' => $this->option('generate') ? $password : null,
                'remember_token_rotated' => true,
                'web_sessions_ended' => $endedSessions,
                'two_factor_reset' => (bool) $this->option('reset-two-factor'),
            ]);
        }

        $this->components->info("The password of {$user->email} changed.");

        if ($this->option('generate')) {
            $this->line("  New password: {$password}");
            $this->components->warn('Keep this password now. The command does not show it again.');
        }

        $this->components->bulletList(array_filter([
            'Rotated the remember token. All "Remember me" logins ended.',
            $sessionsMessage,
            $this->option('reset-two-factor')
                ? 'Two-factor authentication is off. The user can turn it on again in the account settings.'
                : null,
        ]));

        return self::SUCCESS;
    }

    /**
     * Returns the exit code when no password is available. The error is
     * already on the screen.
     */
    private function newPassword(): string|int
    {
        if ($this->option('password') !== null) {
            return $this->option('password');
        }

        if ($this->option('generate')) {
            // No symbols, so that the password is easy to copy from a terminal.
            return Str::password(32, symbols: false);
        }

        if (! $this->input->isInteractive() || $this->wantsJson()) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'missing_option', 'Give --password or --generate when the command cannot prompt.', 'Run the command again with --generate.');
        }

        $password = password('New password', required: true);

        if ($password !== password('Type the new password again', required: true)) {
            return $this->failWith(self::EXIT_BAD_INPUT, 'password_mismatch', 'The two passwords are not the same.', 'Run the command again and type the same password two times.');
        }

        return $password;
    }

    /**
     * The database driver stores the user id of each session, so the command
     * removes those rows. Other drivers cannot find the sessions of one user.
     * For them, the AuthenticateSession middleware ends each session at its
     * next request, because the password hash changed.
     */
    /**
     * @return array{0: int|null, 1: string}
     */
    private function endWebSessions(User $user): array
    {
        $driver = config('session.driver');

        if ($driver !== 'database') {
            return [null, "The \"{$driver}\" session driver cannot find the sessions of one user. "
                .'Each open web session ends at its next request, because the password changed.'];
        }

        $ended = DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $user->id)
            ->delete();

        return [$ended, "Ended {$ended} web sessions of this user."];
    }
}
