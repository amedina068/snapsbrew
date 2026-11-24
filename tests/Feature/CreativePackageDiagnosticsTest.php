<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Http\Middleware\EnsureMcpAccess;
use App\Jobs\ProcessCreativePackageItem;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CreativePackageAttemptFailed;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageItemExecutor;
use App\Services\CreativePackageItemFence;
use App\Services\CreativePackageItemLease;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageDiagnostic;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\FakeRenderer;
use Tests\Support\InteractsWithMcp;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * A failed attempt persists an allowlisted diagnostic and logs allowlisted
 * fields. The raw exception message never reaches a row, a log line or a
 * tool response.
 */
class CreativePackageDiagnosticsTest extends TestCase
{
    use BuildsCreativePackages;
    use InteractsWithMcp;
    use RefreshDatabase;
    use WithPassportKeys;

    /** Every fragment a hostile renderer message can carry. */
    public const HOSTILE = [
        'https://storage.example.test/bucket/file.png?token=fake-signature-0123456789',
        '/var/www/snapsbrew/storage/app/secret.key',
        'Bearer eyJHOSTILEJWT.payload.signature',
        '3f2b9d1e-7c4a-4b8e-9d2f-1a2b3c4d5e6f',
        'Caption: Launch day is here',
    ];

    /** The source files that write a log line on the package path. */
    private const LOG_SOURCES = [
        'app/Services/CreativePackage*.php', 'app/Jobs/ProcessCreativePackageItem.php',
        'app/Mcp/Tools/*CreativePackage*.php', 'app/Console/Commands/*CreativePackage*.php',
        'app/Support/CreativePackage*.php', 'app/Models/CreativePackage*.php', 'app/Http/Controllers/CreativePackageMediaController.php',
    ];

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatch(CreativePackage $package): void {}
        });
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
        $this->logs = new TestHandler;
        Log::getLogger()->pushHandler($this->logs);
    }

    public function test_a_hostile_renderer_message_reaches_no_row_no_log_line_and_no_tool_response(): void
    {
        $actor = $this->member();
        $item = $this->item($actor);
        $message = 'Chrome failed: '.implode(' ', self::HOSTILE);
        $this->throwFromRenderer($message);

        $this->runItem($item);

        $row = (array) DB::table('creative_package_items')->where('id', $item->id)->first();
        $this->assertSame(['failed', 'creative_package_render_failed', 'The renderer did not produce an image.'], [$row['status'], $row['failure_code'], $row['failure_details']]);
        $this->assertSame([
            'diagnostic_code' => 'creative_package_render_failed', 'diagnostic_exception_class' => \RuntimeException::class,
            'diagnostic_correlation_id' => $row['diagnostic_correlation_id'], 'diagnostic_message_fingerprint' => hash_hmac('sha256', $message, config('app.key')),
            'diagnostic_expires_at' => $this->now()->addDays(30)->toDateTimeString(),
        ], $item->fresh()->diagnostics());
        $this->assertTrue(Str::isUuid($row['diagnostic_correlation_id']));
        $this->assertSame(64, strlen($row['diagnostic_message_fingerprint']));
        $this->assertNoHostileFragment($row, 'the item row');
        $snaps = DB::table('snaps')->where('company_id', $item->company_id)->get(['status', 'last_error']);
        $this->assertSame(['failed'], $snaps->pluck('status')->all());
        $this->assertNoHostileFragment($snaps->pluck('last_error')->all(), 'the orphan snap row');

        $records = $this->logs->getRecords();
        // The claim line is written before any attempt, so it is the one line with no correlation id.
        $attempt = array_filter($records, fn ($record) => $record['message'] !== 'creative_package.claimed');
        $this->assertNotEmpty($attempt);
        foreach ($records as $record) {
            $this->assertNoHostileFragment([$record['message'], $record['context']], "log line {$record['message']}");
            $this->assertSame([], array_diff(array_keys($record['context']), CreativePackageContract::LOG_FIELDS), "log line {$record['message']}");
        }
        foreach ($attempt as $record) {
            $this->assertSame($row['diagnostic_correlation_id'], $record['context']['correlation_id'], "log line {$record['message']}");
        }

        $token = $actor->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $response = $this->callMcpTool($token, 'get-creative-package', ['package_id' => $item->package->uuid]);
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('creative_package_render_failed', $text);
        $this->assertNoHostileFragment($text, 'the tool response');
        $this->assertStringNotContainsString($row['diagnostic_correlation_id'], $text);
        $this->assertStringNotContainsString($row['diagnostic_message_fingerprint'], $text);
    }

    public function test_a_finished_attempt_logs_one_completed_line_with_the_allowlisted_fields_only(): void
    {
        $item = $this->item($this->member());
        $this->throwFromRenderer('boom');

        $this->runItem($item);

        $completed = $this->logs->getRecords();
        $completed = array_values(array_filter($completed, fn (LogRecord $record) => $record['message'] === 'creative_package_item.completed'));
        $this->assertCount(1, $completed);
        $fresh = $item->fresh();
        $this->assertSame(['duration_ms', 'status', 'attempts', 'correlation_id', 'item_id', 'package_id', 'company_id', 'error_code'], array_keys($completed[0]['context']));
        $this->assertSame(['failed', 1, $fresh->diagnostic_correlation_id, $item->id, $item->creative_package_id, $item->company_id, 'creative_package_render_failed'], array_values(array_diff_key($completed[0]['context'], ['duration_ms' => 1])));
        $this->assertIsInt($completed[0]['context']['duration_ms']);
    }

    public function test_the_fingerprint_is_stable_for_one_message_and_differs_for_another(): void
    {
        $actor = $this->member();
        [$first, $second] = $this->items($actor, 2, 'same');
        $third = $this->item($actor, 'other');
        $this->throwFromRenderer('one message');
        $this->runItem($first);
        $this->runItem($second);
        $this->throwFromRenderer('another message');
        $this->runItem($third);

        $prints = array_map(fn (CreativePackageItem $item) => $item->fresh()->diagnostic_message_fingerprint, [$first, $second, $third]);

        $this->assertSame($prints[0], $prints[1]);
        $this->assertSame(hash_hmac('sha256', 'one message', config('app.key')), $prints[0]);
        $this->assertNotSame(hash('sha256', 'one message'), $prints[0]);
        $this->assertNotSame($prints[0], $prints[2]);
        $this->assertNotSame($first->fresh()->diagnostic_correlation_id, $second->fresh()->diagnostic_correlation_id);
    }

    /** An anonymous class name embeds the file that declared it. Only the named parent and the marker persist. */
    public function test_an_anonymous_exception_class_persists_without_its_file_path(): void
    {
        $item = $this->item($this->member());
        $exception = new class('boom') extends \RuntimeException {};
        $this->app->instance(SnapRenderer::class, $this->renderer(fn () => throw $exception));

        $this->runItem($item);

        $this->assertStringContainsString(__FILE__, $exception::class);
        $this->assertSame('RuntimeException@anonymous', $item->fresh()->diagnostic_exception_class);
    }

    public function test_a_class_name_longer_than_the_column_is_cut_at_the_column_width(): void
    {
        $namespace = str_repeat('LongNamespace\\', 30).'Tail';
        eval("namespace {$namespace}; class LongException extends \\RuntimeException {}");
        $class = "{$namespace}\\LongException";
        $this->assertGreaterThan(CreativePackageContract::DIAGNOSTIC_CLASS_BYTES, strlen($class));
        $item = $this->item($this->member());
        $this->app->instance(SnapRenderer::class, $this->renderer(fn () => throw new $class('boom')));

        $this->runItem($item);

        $this->assertSame(substr($class, 0, CreativePackageContract::DIAGNOSTIC_CLASS_BYTES), $item->fresh()->diagnostic_exception_class);
    }

    /** A cut at a byte offset can split a multibyte character. The stored name must stay valid UTF-8. */
    public function test_a_multibyte_class_name_is_cut_on_a_character_boundary(): void
    {
        // Nine bytes per segment, so a cut at byte 255 ends on the first byte of a two-byte letter. The precondition proves it.
        $namespace = str_repeat('ddÑdúd\\', 30);
        eval("namespace {$namespace}Tail; class WideException extends \\RuntimeException {}");
        $class = "{$namespace}Tail\\WideException";
        $this->assertGreaterThan(CreativePackageContract::DIAGNOSTIC_CLASS_BYTES, strlen($class));
        $this->assertFalse(mb_check_encoding(substr($class, 0, CreativePackageContract::DIAGNOSTIC_CLASS_BYTES), 'UTF-8'));

        $stored = CreativePackageDiagnostic::className(new $class('boom'));

        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertNotFalse(json_encode($stored));
        $this->assertLessThanOrEqual(CreativePackageContract::DIAGNOSTIC_CLASS_BYTES, strlen($stored));
        $this->assertStringStartsWith($stored, $class);
    }

    public function test_a_failure_without_an_exception_still_carries_the_code_the_correlation_id_and_the_expiry(): void
    {
        $actor = $this->member();
        $item = $this->item($actor);
        User::query()->whereKey($actor->id)->update(['role' => 'viewer']);

        $this->runItem($item);

        $diagnostics = $item->fresh()->diagnostics();
        $this->assertSame(['creative_package_entitlement_required', null, null, $this->now()->addDays(30)->toDateTimeString()], [$diagnostics['diagnostic_code'], $diagnostics['diagnostic_exception_class'], $diagnostics['diagnostic_message_fingerprint'], $diagnostics['diagnostic_expires_at']]);
        $this->assertTrue(Str::isUuid($diagnostics['diagnostic_correlation_id']));
    }

    public function test_a_retry_that_succeeds_clears_the_failure_and_diagnostic_columns_of_the_first_attempt(): void
    {
        $actor = $this->member();
        $item = $this->item($actor);
        User::query()->whereKey($actor->id)->update(['role' => 'viewer']);
        $this->runItem($item);
        $this->assertSame(['failed', 'creative_package_entitlement_required'], [$item->fresh()->status, $item->fresh()->failure_code]);
        $this->assertTrue(Str::isUuid($item->fresh()->diagnostic_correlation_id));
        User::query()->whereKey($actor->id)->update(['role' => User::ROLE_CUSTOMER]);
        $this->assertTrue(app(CreativePackageItemFence::class)->reopen($item->fresh()));

        $this->runItem($item);

        $fresh = $item->fresh();
        $this->assertSame('ready', $fresh->status);
        $this->assertSame([null, null], [$fresh->failure_code, $fresh->failure_details]);
        $this->assertSame([null, null, null, null, null], array_values($fresh->diagnostics()));
        $completed = array_values(array_filter($this->logs->getRecords(), fn (LogRecord $record) => $record['message'] === 'creative_package_item.completed'));
        $this->assertSame(['failed', 'ready'], array_column(array_column($completed, 'context'), 'status'));
        $this->assertSame(['creative_package_entitlement_required', null], array_column(array_column($completed, 'context'), 'error_code'));
    }

    public function test_a_ready_item_holds_no_diagnostic(): void
    {
        $item = $this->item($this->member());

        $this->runItem($item);

        $this->assertSame('ready', $item->fresh()->status);
        $this->assertSame([null, null, null, null, null], array_values($item->fresh()->diagnostics()));
    }

    /**
     * The worker reports the exception a job throws, and `failed_jobs` keeps it
     * after the last try. The job rethrows a fixed sentence, so neither holds
     * the message. The framework still retries the sanitized exception.
     */
    public function test_an_exception_that_escapes_the_worker_reaches_no_log_line_and_no_failed_job_row(): void
    {
        config(['queue.default' => 'database']);
        $item = $this->item($this->member());
        $executor = new class extends CreativePackageItemExecutor
        {
            public int $calls = 0;

            public function __construct() {}

            public function execute(CreativePackageItem $item, CreativePackageItemLease $lease, ?string $correlationId = null): void
            {
                $this->calls++;
                throw new \RuntimeException('Chrome failed: '.implode(' ', CreativePackageDiagnosticsTest::HOSTILE));
            }
        };
        $this->app->instance(CreativePackageItemExecutor::class, $executor);
        ProcessCreativePackageItem::dispatch($item->id);

        $this->workQueue();
        $this->assertSame([1, 1, 0], [$executor->calls, (int) DB::table('jobs')->value('attempts'), DB::table('failed_jobs')->count()]);
        CarbonImmutable::setTestNow($this->now()->addMinutes(1));
        $this->workQueue();
        CarbonImmutable::setTestNow($this->now()->addMinutes(2));
        $this->workQueue();

        $this->assertSame([3, 0, 1], [$executor->calls, DB::table('jobs')->count(), DB::table('failed_jobs')->count()]);
        $failed = DB::table('failed_jobs')->value('exception');
        $this->assertStringContainsString(CreativePackageAttemptFailed::class, $failed);
        $this->assertNoHostileFragment($failed, 'the failed_jobs row');
        $this->assertSame('failed', $item->fresh()->status);
        $records = $this->logs->getRecords();
        $this->assertNotEmpty($records);
        $this->assertSame(3, count(array_filter($records, fn (LogRecord $record) => $record['message'] === 'creative_package_item.attempt_failed')));
        foreach ($records as $record) {
            $this->assertNoHostileFragment([$record['message'], $record['context']], "log line {$record['message']}");
            $this->assertSame([], array_diff(array_keys($record['context']), CreativePackageContract::LOG_FIELDS), "log line {$record['message']}");
        }
    }

    /** The serializer never reads these columns. `$hidden` guards every other array path of the model. */
    public function test_the_model_array_hides_every_diagnostic_column(): void
    {
        $item = $this->item($this->member());
        $this->throwFromRenderer('boom');
        $this->runItem($item);

        $keys = array_keys($item->fresh()->toArray());

        $this->assertSame([], array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'diagnostic_'))));
        $this->assertContains('failure_code', $keys);
    }

    public function test_the_executor_mints_a_correlation_id_when_the_caller_passes_none(): void
    {
        $item = $this->item($this->member());
        $this->throwFromRenderer('boom');
        $lease = app(CreativePackageItemFence::class)->claim($item);

        app(CreativePackageItemExecutor::class)->execute($item->fresh(), $lease);

        $this->assertTrue(Str::isUuid($item->fresh()->diagnostic_correlation_id));
    }

    /**
     * Every log call on the package path is read from the source. A context key
     * is a quoted word before `=>`, so a key outside the allowlist fails here
     * whatever its value. A call the pattern cannot read fails the count.
     */
    public function test_every_log_call_on_the_package_path_carries_allowlisted_keys_only(): void
    {
        $calls = 0;
        $readable = 0;
        foreach (self::LOG_SOURCES as $pattern) {
            foreach (glob(base_path($pattern)) as $file) {
                $source = file_get_contents($file);
                $calls += preg_match_all('/Log::(?:debug|info|notice|warning|error|critical|alert|emergency)\(/', $source);
                $readable += preg_match_all('/Log::(?:debug|info|notice|warning|error|critical|alert|emergency)\(\s*\'[a-z_.]+\',\s*\[(.*?)\]\s*\);/s', $source, $matches);
                foreach ($matches[1] as $literal) {
                    // The token before every `=>`, whatever its quote style. Only a single-quoted snake-case key is a readable field.
                    preg_match_all('/([^\s,\[]+)\s*=>/', $literal, $keys);
                    $this->assertNotEmpty($keys[1], basename($file));
                    $this->assertCount(substr_count($literal, '=>'), $keys[1], basename($file));
                    foreach ($keys[1] as $token) {
                        $this->assertMatchesRegularExpression('/^\'[a-z]+(_[a-z]+)*\'$/', $token, basename($file));
                        $this->assertContains(trim($token, "'"), CreativePackageContract::LOG_FIELDS, basename($file));
                    }
                }
                $this->assertStringNotContainsString('logger(', $source, basename($file));
                $this->assertStringNotContainsString('Log::channel(', $source, basename($file));
            }

        }
        $this->assertSame($calls, $readable, 'A log call is written in a shape this test cannot read.');
        $this->assertGreaterThanOrEqual(5, $calls);
    }

    /** @param string|array<mixed> $haystack an array is encoded with its slashes unescaped, so a URL or a path can match */
    private function assertNoHostileFragment(string|array $haystack, string $where): void
    {
        $haystack = is_array($haystack) ? json_encode($haystack, JSON_UNESCAPED_SLASHES) : $haystack;
        foreach (self::HOSTILE as $fragment) {
            $this->assertStringNotContainsString($fragment, $haystack, "{$where} leaks `{$fragment}`.");
        }
    }

    private function item(User $actor, string $key = 'key'): CreativePackageItem
    {
        return $this->items($actor, 1, $key)[0];
    }

    /** @return array<int, CreativePackageItem> */
    private function items(User $actor, int $count, string $key): array
    {
        $template = Template::factory()->for($actor->company)->for($actor)->create();
        $sources = [];
        for ($version = 1; $version <= $count; $version++) {
            $sources[] = TemplateVersion::factory()->for($template)->create(['version_number' => $version, 'created_by' => $actor->id, 'published' => true, 'width' => 20, 'height' => 20]);
        }

        return $this->claim($actor, $this->request($key, $sources))->package->items()->orderBy('position')->get()->all();
    }

    private function workQueue(): void
    {
        // The worker runs inside the PHPUnit process, which sits near the
        // default 128 MB limit late in a full run. Without --memory, the
        // worker stops after one job and the later items stay pending.
        $this->artisan('queue:work', ['--queue' => Queues::IMPORTS, '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 512]);
    }

    private function throwFromRenderer(string $message): void
    {
        $this->app->instance(SnapRenderer::class, $this->renderer(fn () => throw new \RuntimeException($message)));
    }

    private function renderer(\Closure $beforeRender): FakeRenderer
    {
        return new class($beforeRender) extends FakeRenderer
        {
            public function __construct(private \Closure $beforeRender) {}

            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                ($this->beforeRender)();

                return parent::screenshotBase64($html, $width, $height, $format, $transparent);
            }
        };
    }
}
