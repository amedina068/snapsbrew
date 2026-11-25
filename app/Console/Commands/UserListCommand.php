<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

class UserListCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'snapsbrew:user:list
        {--search= : Show only the users whose name or email contains this text}
        {--json : Write one JSON object to stdout}';

    protected $description = 'List the users of this instance.';

    public function handle(): int
    {
        $search = $this->option('search');

        $users = User::query()
            ->with('company')
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('id')
            ->get();

        if ($this->wantsJson()) {
            return $this->respond(self::EXIT_OK, [
                'users' => $users->map(fn (User $user) => self::describe($user))->values()->all(),
            ]);
        }

        if ($users->isEmpty()) {
            $this->components->info('No user matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Company', 'Owner', 'Created'],
            $users->map(fn (User $user) => [
                $user->id,
                $user->name,
                $user->email,
                $user->company?->name,
                $user->isOwner() ? 'yes' : 'no',
                $user->created_at?->toDateString(),
            ]),
        );

        return self::SUCCESS;
    }

    /**
     * The user fields that the CLI and the instance-admin MCP server show.
     *
     * @return array<string, mixed>
     */
    public static function describe(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company' => ($company = $user->company) instanceof Company
                ? ['id' => $company->id, 'name' => $company->name]
                : null,
            'role' => $user->role,
            'is_owner' => $user->isOwner(),
            'deactivated' => $user->isDeactivated(),
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
