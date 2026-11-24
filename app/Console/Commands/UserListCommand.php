<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserListCommand extends Command
{
    protected $signature = 'snapsbrew:user:list
        {--search= : Show only the users whose name or email contains this text}';

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
}
