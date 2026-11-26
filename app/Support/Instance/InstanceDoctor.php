<?php

namespace App\Support\Instance;

use App\Contracts\SnapRenderer;
use App\Models\User;
use App\Support\CompanyOwners;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * The health checks of `snapsbrew:doctor` and of the `run-doctor` tool.
 *
 * Each check returns `{id, status, message, fix}`. The status is `ok`, `warn`
 * or `fail`. A `fail` means the instance cannot do its main job. A `warn`
 * means that a part works badly or that a background process is missing.
 *
 * `--fix` runs only the fixes in `SAFE_FIXES`. Each one creates a file or a
 * folder that is missing, and none of them changes data. The doctor never runs
 * migrations and never changes `.env`.
 */
class InstanceDoctor
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** The checks that `--fix` can repair. */
    public const SAFE_FIXES = ['storage_link', 'passport_keys', 'fonts_cache'];

    /** The message and the fix name at most this many companies. */
    private const COMPANIES_SHOWN = 10;

    private const TEST_RENDER_HTML = '<!DOCTYPE html><html><body style="margin:0;background:#ff6a00"></body></html>';

    private bool $rendererReachable = false;

    public function __construct(private InstanceStatus $status) {}

    /**
     * @return list<array{id: string, status: string, message: string, fix: string, fixed?: bool}>
     */
    public function run(bool $fix = false): array
    {
        $checks = [];

        foreach ($this->checks() as $id => $check) {
            $result = $check();

            if ($fix && $result['status'] !== self::OK && in_array($id, self::SAFE_FIXES, true)) {
                $this->repair($id);
                $result = $check() + ['fixed' => false];
                $result['fixed'] = $result['status'] === self::OK;
            }

            $checks[] = $result;
        }

        return $checks;
    }

    /**
     * @param  list<array{status: string}>  $checks
     * @return array{ok: int, warn: int, fail: int}
     */
    public static function summary(array $checks): array
    {
        $summary = [self::OK => 0, self::WARN => 0, self::FAIL => 0];

        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return $summary;
    }

    /**
     * The order matters: the test render reads the result of the renderer check.
     *
     * @return array<string, Closure(): array{id: string, status: string, message: string, fix: string}>
     */
    private function checks(): array
    {
        return [
            'app_key' => fn () => $this->appKey(),
            'database' => fn () => $this->database(),
            'migrations' => fn () => $this->migrations(),
            'storage_writable' => fn () => $this->storageWritable(),
            'storage_link' => fn () => $this->storageLink(),
            'passport_keys' => fn () => $this->passportKeys(),
            'fonts_cache' => fn () => $this->fontsCache(),
            'renderer' => fn () => $this->renderer(),
            'test_render' => fn () => $this->testRender(),
            'queue_heartbeat' => fn () => $this->queueHeartbeat(),
            'scheduler_heartbeat' => fn () => $this->schedulerHeartbeat(),
            'cache_store' => fn () => $this->cacheStore(),
            'mail' => fn () => $this->mail(),
            'owner' => fn () => $this->owner(),
            'company_owners' => fn () => $this->companyOwners(),
        ];
    }

    private function appKey(): array
    {
        return config('app.key')
            ? $this->ok('app_key', 'APP_KEY is set.')
            : $this->result('app_key', self::FAIL, 'APP_KEY is empty. Sessions, signed URLs and the setup token do not work.', 'Run php artisan key:generate, or set APP_KEY in the environment.');
    }

    private function database(): array
    {
        $database = $this->status->database();

        return $database['connected']
            ? $this->ok('database', "The {$database['driver']} database answers.")
            : $this->result('database', self::FAIL, "The {$database['driver']} database does not answer: {$database['error']}", 'Make sure that the DB_* variables are correct and that the database server runs.');
    }

    private function migrations(): array
    {
        $pending = $this->status->pendingMigrations();

        return match (true) {
            $pending === null => $this->result('migrations', self::FAIL, 'The migration state cannot be read.', 'Correct the database check first.'),
            $pending === 0 => $this->ok('migrations', 'All migrations ran.'),
            default => $this->result('migrations', self::WARN, "{$pending} migrations did not run.", 'Run php artisan migrate --force. The doctor does not run migrations.'),
        };
    }

    private function storageWritable(): array
    {
        $paths = [storage_path('app'), storage_path('framework/cache'), storage_path('framework/views'), storage_path('logs')];
        $blocked = array_values(array_filter($paths, fn (string $path) => ! is_dir($path) || ! is_writable($path)));

        return $blocked === []
            ? $this->ok('storage_writable', 'The storage folders are writable.')
            : $this->result('storage_writable', self::FAIL, 'These folders are missing or not writable: '.implode(', ', $blocked).'.', 'Give the web server user write access to the storage folder.');
    }

    private function storageLink(): array
    {
        return file_exists(public_path('storage'))
            ? $this->ok('storage_link', 'public/storage links to the storage folder.')
            : $this->result('storage_link', self::WARN, 'public/storage does not exist. Uploaded images and fonts do not load.', 'Run php artisan storage:link, or run php artisan snapsbrew:doctor --fix.');
    }

    private function passportKeys(): array
    {
        if (config('passport.private_key') || file_exists(Passport::keyPath('oauth-private.key'))) {
            return $this->ok('passport_keys', 'The OAuth signing keys exist.');
        }

        return $this->result('passport_keys', self::FAIL, 'The OAuth signing keys do not exist. The MCP server answers 500 instead of 401.', 'Run php artisan passport:keys, or run php artisan snapsbrew:doctor --fix.');
    }

    private function fontsCache(): array
    {
        if (config('services.render.driver') === 'browserless') {
            return $this->ok('fonts_cache', 'Browserless keeps its own font cache.');
        }

        $path = self::fontsCachePath();

        if ($path === null) {
            return $this->result('fonts_cache', self::WARN, 'HOME is not set, so Chromium has no font cache folder. Each render loads the fonts again.', 'Set HOME or XDG_CACHE_HOME for the PHP process.');
        }

        if (is_dir($path) && is_writable($path)) {
            return $this->ok('fonts_cache', "The font cache folder {$path} is writable.");
        }

        return $this->result('fonts_cache', self::WARN, "The font cache folder {$path} is missing or not writable. Each render loads the fonts again.", 'Run php artisan snapsbrew:doctor --fix, or create the folder with write access.');
    }

    private function renderer(): array
    {
        $this->rendererReachable = false;
        $driver = (string) config('services.render.driver');

        if ($driver === 'browserless') {
            $url = rtrim((string) config('services.browserless.url'), '/');

            if ($url === '') {
                return $this->result('renderer', self::FAIL, 'The render driver is browserless, but BROWSERLESS_URL is empty.', 'Set BROWSERLESS_URL, or set RENDER_DRIVER=browsershot.');
            }

            return $this->probe($url.'/json/version', 'Browserless', ['token' => (string) config('services.browserless.token')]);
        }

        if ($instance = rtrim((string) config('services.browsershot.instance'), '/')) {
            return $this->probe($instance.'/json/version', 'The Chromium at BROWSERSHOT_INSTANCE');
        }

        $node = (string) config('services.browsershot.node_path');

        if (! is_executable($node)) {
            return $this->result('renderer', self::FAIL, "Node does not exist at {$node}. Browsershot cannot start Chromium.", 'Set BROWSERSHOT_NODE_PATH, or set BROWSERSHOT_INSTANCE to a running Chromium.');
        }

        $this->rendererReachable = true;

        return $this->ok('renderer', "Browsershot starts Chromium with the node at {$node}.");
    }

    /**
     * @param  array<string, string>  $query
     */
    private function probe(string $url, string $label, array $query = []): array
    {
        $query = array_filter($query);

        try {
            $response = Http::timeout(3)->get($url, $query);
        } catch (Throwable $exception) {
            return $this->result('renderer', self::FAIL, "{$label} at ".$this->redactUrl($url).' does not answer.', 'Start the renderer, or correct its URL.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->result('renderer', self::FAIL, "{$label} refused the token (HTTP {$response->status()}).", 'Make sure that BROWSERLESS_TOKEN is the token of the renderer.');
        }

        $this->rendererReachable = true;

        return $this->ok('renderer', "{$label} answers (HTTP {$response->status()}).");
    }

    private function testRender(): array
    {
        if (! $this->rendererReachable) {
            return $this->result('test_render', self::FAIL, 'The test render did not run, because the renderer does not answer.', 'Correct the renderer check first.');
        }

        $started = microtime(true);

        try {
            $png = base64_decode(app(SnapRenderer::class)->screenshotBase64(self::TEST_RENDER_HTML, 16, 16, 'png'), true);
        } catch (Throwable $exception) {
            return $this->result('test_render', self::FAIL, 'The test render failed: '.class_basename($exception).'.', 'Read storage/logs for the render error. Then run php artisan snapsbrew:doctor again.');
        }

        $milliseconds = (int) round((microtime(true) - $started) * 1000);

        if (! is_string($png) || ! str_starts_with($png, "\x89PNG")) {
            return $this->result('test_render', self::FAIL, 'The test render returned data that is not a PNG image.', 'Read storage/logs for the render error.');
        }

        return $this->ok('test_render', "A 16x16 test render took {$milliseconds} ms.") + ['duration_ms' => $milliseconds];
    }

    private function queueHeartbeat(): array
    {
        if (config('queue.default') === 'sync') {
            return $this->ok('queue_heartbeat', 'The queue is sync. Each job runs inside its request, so no worker is necessary.');
        }

        $beat = Heartbeats::last(Heartbeats::QUEUE);

        if (Heartbeats::isFresh($beat)) {
            return $this->ok('queue_heartbeat', 'A queue worker ran a job at '.$beat->toIso8601String().'.');
        }

        return $this->result('queue_heartbeat', self::WARN, $beat
            ? 'The last queue heartbeat is from '.$beat->toIso8601String().'. No worker took a job since then.'
            : 'No queue worker ran the heartbeat job yet. Async renders and imports wait in the queue.',
            'Start a worker: php artisan queue:work (or Horizon). The scheduler must also run, because it sends the heartbeat job.');
    }

    private function schedulerHeartbeat(): array
    {
        $beat = Heartbeats::last(Heartbeats::SCHEDULER);

        if (Heartbeats::isFresh($beat)) {
            return $this->ok('scheduler_heartbeat', 'The scheduler ran at '.$beat->toIso8601String().'.');
        }

        return $this->result('scheduler_heartbeat', self::WARN, $beat
            ? 'The scheduler last ran at '.$beat->toIso8601String().'.'
            : 'The scheduler did not run yet. The prune and reconcile jobs do not run.',
            'Run php artisan schedule:work, or add a cron entry for php artisan schedule:run each minute.');
    }

    private function cacheStore(): array
    {
        return config('cache.default') === 'array'
            ? $this->result('cache_store', self::WARN, 'The cache store is array. Locks, rate limits and login links do not last between requests.', 'Set CACHE_DRIVER to file, database or redis.')
            : $this->ok('cache_store', 'The cache store is '.config('cache.default').'.');
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? $this->result('mail', self::WARN, "The mailer is {$mailer}. Invitations and password reset emails do not leave this server.", 'Set MAIL_MAILER and the SMTP variables. Then run php artisan snapsbrew:doctor again.')
            : $this->ok('mail', "The mailer is {$mailer}.");
    }

    private function owner(): array
    {
        if (! $this->status->database()['connected']) {
            return $this->result('owner', self::FAIL, 'The owner cannot be read, because the database does not answer.', 'Correct the database check first.');
        }

        return User::query()->where('is_owner', true)->exists()
            ? $this->ok('owner', 'An owner account exists.')
            : $this->result('owner', self::WARN, 'This instance has no owner yet.', 'Run php artisan snapsbrew:install --name="..." --email="..." --json.');
    }

    /**
     * A company with active users and no active owner works, but nobody in it
     * can change the roles or manage the members. The `companies` list has
     * each company and the active user who joined first.
     */
    private function companyOwners(): array
    {
        if (! $this->status->database()['connected']) {
            return $this->result('company_owners', self::FAIL, 'The company owners cannot be read, because the database does not answer.', 'Correct the database check first.');
        }

        try {
            $companies = CompanyOwners::companiesWithoutActiveOwner();
        } catch (Throwable) {
            return $this->result('company_owners', self::WARN, 'The company owners cannot be read. The users table has no company roles yet.', 'Run php artisan migrate --force. Then run php artisan snapsbrew:doctor again.');
        }

        if ($companies === []) {
            return $this->ok('company_owners', 'Each company with an active user has an active owner.');
        }

        $shown = array_slice($companies, 0, self::COMPANIES_SHOWN);
        $more = count($companies) - count($shown);
        $names = implode(', ', array_map(fn (array $company) => "{$company['name']} (id {$company['id']})", $shown));
        $commands = implode(' ', array_map(fn (array $company) => "Run php artisan snapsbrew:user:role {$company['suggested_owner']} owner for {$company['name']}.", $shown));
        $count = count($companies) === 1 ? '1 company has' : count($companies).' companies have';

        return $this->result(
            'company_owners',
            self::WARN,
            "{$count} no active owner: {$names}".($more > 0 ? " and {$more} more" : '').'. Nobody in these companies can change the roles or manage the members.',
            'Make an active user the owner of each company. '.$commands.($more > 0 ? ' The companies list has the other companies.' : ''),
        ) + ['companies' => $companies];
    }

    private function repair(string $id): void
    {
        try {
            match ($id) {
                'storage_link' => self::callQuietly('storage:link'),
                'passport_keys' => self::callQuietly('passport:keys'),
                'fonts_cache' => ($path = self::fontsCachePath()) && ! is_dir($path) && @mkdir($path, 0755, true),
            };
        } catch (Throwable) {
            // The check runs again after the repair and reports the result.
        }
    }

    /**
     * Runs an Artisan command of a repair with no output.
     *
     * `Artisan::call()` replaces the output that `Artisan::output()` returns.
     * A caller that runs `snapsbrew:doctor --fix --json` in the same process
     * then reads the line of the repair command instead of the JSON object.
     * This method runs the command directly, so the JSON object stays the
     * only output.
     */
    private static function callQuietly(string $command): int
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return Artisan::all()[$command]->run($input, new NullOutput);
    }

    /**
     * The fontconfig cache folder that Chromium writes. It is null when the
     * process has no HOME and no XDG_CACHE_HOME.
     */
    public static function fontsCachePath(): ?string
    {
        // Process variables of Chromium, not app settings, so .env.example
        // does not list them.
        [$xdg, $home] = array_map(fn (string $name) => getenv($name) ?: null, ['XDG_CACHE_HOME', 'HOME']);
        $base = $xdg ?: ($home ? rtrim($home, '/').'/.cache' : null);

        return $base ? rtrim($base, '/').'/fontconfig' : null;
    }

    private function redactUrl(string $url): string
    {
        return (string) preg_replace('#//[^/@]+@#', '//[redacted]@', $url);
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function ok(string $id, string $message): array
    {
        return $this->result($id, self::OK, $message, '');
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private function result(string $id, string $status, string $message, string $fix): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message, 'fix' => $fix];
    }
}
