<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Jobs\RecordQueueHeartbeat;
use App\Models\User;
use App\Support\Instance\Heartbeats;
use App\Support\Instance\InstanceDoctor;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakeRenderer;
use Tests\Support\RunsJsonCommands;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

class StatusAndDoctorCommandTest extends TestCase
{
    use RefreshDatabase, RunsJsonCommands, WithPassportKeys;

    private const STATUS_KEYS = ['version', 'app_url', 'environment', 'database', 'queue', 'scheduler', 'storage_link', 'mail_driver', 'cache_driver', 'renderer', 'mcp', 'owner_exists'];

    private const CHECK_IDS = ['app_key', 'database', 'migrations', 'storage_writable', 'storage_link', 'passport_keys', 'fonts_cache', 'renderer', 'test_render', 'queue_heartbeat', 'scheduler_heartbeat', 'cache_store', 'mail', 'owner', 'company_owners'];

    public function test_status_returns_every_field_of_the_contract()
    {
        config(['queue.default' => 'database', 'snapsbrew.version' => '0.1.0']);

        [$exit, $json] = $this->runJson('snapsbrew:status');

        $this->assertSame(0, $exit);
        $data = $json['data'];
        $this->assertSame(self::STATUS_KEYS, array_keys($data));
        $this->assertSame('0.1.0', $data['version']);
        $this->assertSame(['driver' => DB::connection()->getDriverName(), 'connected' => true, 'pending_migrations' => 0, 'error' => null], $data['database']);
        $this->assertSame(['connection' => 'database', 'heartbeat_at' => null, 'healthy' => false], $data['queue']);
        $this->assertSame(['last_run_at' => null, 'healthy' => false], $data['scheduler']);
        $this->assertSame(['enabled' => true, 'url' => url('/mcp'), 'admin_http_enabled' => false], $data['mcp']);
        $this->assertFalse($data['owner_exists']);
    }

    public function test_status_reads_fresh_and_stale_heartbeats()
    {
        config(['queue.default' => 'database']);
        User::factory()->create(['is_owner' => true]);
        Heartbeats::beat(Heartbeats::SCHEDULER);
        (new RecordQueueHeartbeat)->handle();

        [, $json] = $this->runJson('snapsbrew:status');
        $this->assertTrue($json['data']['queue']['healthy']);
        $this->assertTrue($json['data']['scheduler']['healthy']);
        $this->assertTrue($json['data']['owner_exists']);

        $this->travel(Heartbeats::STALE_AFTER_SECONDS + 1)->seconds();

        [, $json] = $this->runJson('snapsbrew:status');
        $this->assertFalse($json['data']['queue']['healthy']);
        $this->assertFalse($json['data']['scheduler']['healthy']);
        $this->assertNotNull($json['data']['queue']['heartbeat_at']);
    }

    public function test_status_exits_one_with_db_unreachable_when_the_database_does_not_answer()
    {
        config(['database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']]);
        $previous = config('database.default');
        config(['database.default' => 'broken']);

        try {
            [$exit, $json] = $this->runJson('snapsbrew:status');
        } finally {
            config(['database.default' => $previous]);
        }

        $this->assertSame(1, $exit);
        $this->assertFalse($json['data']['database']['connected']);
        $this->assertSame('db_unreachable', $json['errors'][0]['code']);
        $this->assertStringNotContainsString('password', strtolower($json['errors'][0]['message']));
    }

    public function test_the_scheduler_sends_both_heartbeats_each_minute()
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event) => $event->description);

        $this->assertSame('* * * * *', $events['snapsbrew:scheduler-heartbeat']->expression);
        $this->assertSame('* * * * *', $events['snapsbrew:queue-heartbeat']->expression);
    }

    public function test_doctor_returns_every_check_and_passes_with_a_working_renderer()
    {
        $renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $renderer);
        config(['services.render.driver' => 'browsershot', 'services.browsershot.instance' => 'http://chromium:9222', 'queue.default' => 'sync']);
        Http::fake(['chromium:9222/*' => Http::response(['Browser' => 'Chrome/140'])]);
        User::factory()->create(['is_owner' => true]);

        [$exit, $json] = $this->runJson('snapsbrew:doctor');

        $checks = collect($json['data']['checks'])->keyBy('id');
        $this->assertSame(self::CHECK_IDS, $checks->keys()->all());
        foreach ($checks as $check) {
            $this->assertSame(['id', 'status', 'message', 'fix'], array_slice(array_keys($check), 0, 4));
            $this->assertContains($check['status'], ['ok', 'warn', 'fail']);
        }
        $this->assertSame('ok', $checks['renderer']['status']);
        $this->assertSame('ok', $checks['test_render']['status']);
        $this->assertCount(1, $renderer->screenshotCalls, 'The test render did not reach the renderer.');
        $this->assertSame(array_sum($json['data']['summary']), count(self::CHECK_IDS));
        $this->assertSame(0, $json['data']['summary']['fail'], json_encode($checks->where('status', 'fail')->values()));
        $this->assertSame(0, $exit);
    }

    public function test_doctor_fails_with_exit_one_when_the_renderer_does_not_answer()
    {
        config(['services.render.driver' => 'browserless', 'services.browserless.url' => 'http://browserless:3000', 'services.browserless.token' => 'secret-token']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        [$exit, $json, $raw] = $this->runJson('snapsbrew:doctor');

        $this->assertSame(1, $exit);
        $codes = array_column($json['errors'], 'code');
        $this->assertContains('renderer', $codes);
        $this->assertContains('test_render', $codes);
        $this->assertStringNotContainsString('secret-token', $raw);
    }

    public function test_doctor_reports_a_failed_test_render()
    {
        config(['services.render.driver' => 'browserless', 'services.browserless.url' => 'http://browserless:3000']);
        Http::fake(['browserless:3000/*' => Http::response(['Browser' => 'Chrome'])]);
        $this->app->instance(SnapRenderer::class, new class extends FakeRenderer
        {
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                throw new RuntimeException('boom');
            }
        });

        [$exit, $json] = $this->runJson('snapsbrew:doctor');

        $this->assertSame(1, $exit);
        $this->assertSame(['test_render'], array_column($json['errors'], 'code'));
    }

    public function test_doctor_fix_creates_the_missing_font_cache_folder_and_reports_it()
    {
        $base = sys_get_temp_dir().'/snapsbrew-doctor-'.uniqid();
        $previous = getenv('XDG_CACHE_HOME');
        putenv("XDG_CACHE_HOME={$base}");
        config(['services.render.driver' => 'browsershot']);

        try {
            [, $json] = $this->runJson('snapsbrew:doctor');
            $this->assertSame('warn', collect($json['data']['checks'])->firstWhere('id', 'fonts_cache')['status']);

            [, $json] = $this->runJson('snapsbrew:doctor', ['--fix' => true]);
            $check = collect($json['data']['checks'])->firstWhere('id', 'fonts_cache');

            $this->assertSame('ok', $check['status']);
            $this->assertTrue($check['fixed']);
            $this->assertDirectoryExists("{$base}/fontconfig");
        } finally {
            putenv($previous === false ? 'XDG_CACHE_HOME' : "XDG_CACHE_HOME={$previous}");
            @rmdir("{$base}/fontconfig");
            @rmdir($base);
        }
    }

    public function test_doctor_fix_json_creates_the_missing_storage_link_and_stdout_stays_one_json_object()
    {
        $link = public_path('storage');
        $target = is_link($link) ? readlink($link) : null;
        $this->assertTrue($target !== null || ! file_exists($link), 'public/storage must be a link or must not exist.');

        if ($target !== null) {
            unlink($link);
        }

        try {
            // runJson() reads Artisan::output() and fails unless it holds one
            // JSON object and nothing else.
            [, $json, $raw] = $this->runJson('snapsbrew:doctor', ['--fix' => true]);
            $check = collect($json['data']['checks'])->firstWhere('id', 'storage_link');

            $this->assertSame('ok', $check['status']);
            $this->assertTrue($check['fixed']);
            $this->assertTrue(is_link($link));
            $this->assertStringNotContainsString('has been connected', $raw);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            if ($target !== null) {
                symlink($target, $link);
            }
        }
    }

    public function test_doctor_never_runs_migrations_or_other_unsafe_fixes()
    {
        $this->assertSame(['storage_link', 'passport_keys', 'fonts_cache'], InstanceDoctor::SAFE_FIXES);
    }
}
