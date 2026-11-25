<?php

namespace App\Console\Commands;

use App\Actions\Fortify\ResetUserPassword;
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
    protected $signature = 'snapsbrew:user:password
        {email : The email of the user}
        {--password= : The new password. The command does not prompt}
        {--generate : Make a random password and show it one time}
        {--reset-two-factor : Turn off two-factor authentication for a user who lost the device}';

    protected $description = 'Set a new password for a user, without email.';

    public function handle(ResetUserPassword $resetter): int
    {
        $user = User::query()->where('email', strtolower(trim($this->argument('email'))))->first();

        if (! $user) {
            $this->components->error("No user has the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($this->option('password') !== null && $this->option('generate')) {
            $this->components->error('Use --password or --generate, not the two together.');

            return self::FAILURE;
        }

        $password = $this->newPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        try {
            $resetter->reset($user, ['password' => $password]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
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

        $this->components->info("The password of {$user->email} changed.");

        if ($this->option('generate')) {
            $this->line("  New password: {$password}");
            $this->components->warn('Keep this password now. The command does not show it again.');
        }

        $this->components->bulletList(array_filter([
            'Rotated the remember token. All "Remember me" logins ended.',
            $this->endWebSessions($user),
            $this->option('reset-two-factor')
                ? 'Two-factor authentication is off. The user can turn it on again in the account settings.'
                : null,
        ]));

        return self::SUCCESS;
    }

    /**
     * Returns null when no password is available. The error is already on the screen.
     */
    private function newPassword(): ?string
    {
        if ($this->option('password') !== null) {
            return $this->option('password');
        }

        if ($this->option('generate')) {
            // No symbols, so that the password is easy to copy from a terminal.
            return Str::password(32, symbols: false);
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Give --password or --generate when the command cannot prompt.');

            return null;
        }

        $password = password('New password', required: true);

        if ($password !== password('Type the new password again', required: true)) {
            $this->components->error('The two passwords are not the same.');

            return null;
        }

        return $password;
    }

    /**
     * The database driver stores the user id of each session, so the command
     * removes those rows. Other drivers cannot find the sessions of one user.
     * For them, the AuthenticateSession middleware ends each session at its
     * next request, because the password hash changed.
     */
    private function endWebSessions(User $user): string
    {
        $driver = config('session.driver');

        if ($driver !== 'database') {
            return "The \"{$driver}\" session driver cannot find the sessions of one user. "
                .'Each open web session ends at its next request, because the password changed.';
        }

        $ended = DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $user->id)
            ->delete();

        return "Ended {$ended} web sessions of this user.";
    }
}
