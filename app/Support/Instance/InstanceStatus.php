<?php

namespace App\Support\Instance;

use App\Models\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One snapshot of the state of this instance.
 *
 * `snapsbrew:status --json` prints it, and the `get-instance-status` tool of
 * the instance-admin MCP server returns it. Every value comes from a fast
 * local read. The slow checks (a test render) are in `InstanceDoctor`.
 */
class InstanceStatus
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $database = $this->database();
        $queueBeat = $database['connected'] ? Heartbeats::last(Heartbeats::QUEUE) : null;
        $schedulerBeat = $database['connected'] ? Heartbeats::last(Heartbeats::SCHEDULER) : null;
        $queueConnection = (string) config('queue.default');

        return [
            'version' => (string) config('snapsbrew.version'),
            'app_url' => (string) config('app.url'),
            'environment' => app()->environment(),
            'database' => $database,
            'queue' => [
                'connection' => $queueConnection,
                'heartbeat_at' => $queueBeat?->toIso8601String(),
                // The sync driver runs each job inside the request. No worker
                // exists, so no beat is necessary.
                'healthy' => $queueConnection === 'sync' || Heartbeats::isFresh($queueBeat),
            ],
            'scheduler' => [
                'last_run_at' => $schedulerBeat?->toIso8601String(),
                'healthy' => Heartbeats::isFresh($schedulerBeat),
            ],
            'storage_link' => file_exists(public_path('storage')),
            'mail_driver' => (string) config('mail.default'),
            'cache_driver' => (string) config('cache.default'),
            'renderer' => (string) config('services.render.driver'),
            'mcp' => [
                'enabled' => (bool) config('mcp.enabled'),
                'url' => McpConnect::url(),
                'admin_http_enabled' => (bool) config('mcp.admin_enabled'),
            ],
            'owner_exists' => $database['connected'] && $this->ownerExists(),
        ];
    }

    /**
     * @return array{driver: string, connected: bool, pending_migrations: int|null, error: string|null}
     */
    public function database(): array
    {
        $driver = (string) DB::connection()->getDriverName();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            return ['driver' => $driver, 'connected' => false, 'pending_migrations' => null, 'error' => class_basename($exception).': '.$this->firstLine($exception->getMessage())];
        }

        return ['driver' => $driver, 'connected' => true, 'pending_migrations' => $this->pendingMigrations(), 'error' => null];
    }

    public function pendingMigrations(): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return count($migrator->getMigrationFiles($this->migrationPaths($migrator)));
            }

            $files = $migrator->getMigrationFiles($this->migrationPaths($migrator));
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }

    private function ownerExists(): bool
    {
        try {
            return User::query()->where('is_owner', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function migrationPaths(Migrator $migrator): array
    {
        return [...$migrator->paths(), database_path('migrations')];
    }

    private function firstLine(string $message): string
    {
        return strtok($message, "\n") ?: $message;
    }
}
