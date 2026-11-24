<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CreativePackageAttemptFailed;
use App\Services\CreativePackageDispatcher;
use App\Services\CreativePackageItemExecutor;
use App\Services\CreativePackageItemFence;
use App\Services\IdempotencyClaimer;
use App\Services\SnapService;
use App\Services\SnapSourceVersioner;
use App\Services\SourceVersionUnavailableException;
use App\Services\UsageRecorder;
use App\Support\CreativePackageContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * One worker takes one fenced item from its claim to a ready or failed
 * state. The fake renderer answers with a 20x20 PNG, so a 20x20 source is the
 * happy path and a 1080x1080 source is a media mismatch.
 */
class CreativePackageItemExecutorTest extends TestCase
{
    use BuildsCreativePackages;
    use RefreshDatabase;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        // The claim dispatches after commit. A no-op dispatcher keeps the sync queue free for the inline render.
        $this->app->instance(CreativePackageDispatcher::class, new class extends CreativePackageDispatcher
        {
            public function dispatch(CreativePackage $package): void {}
        });
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
        $this->recordQueries();
    }

    public function test_the_worker_renders_verifies_records_usage_and_marks_the_item_ready(): void
    {
        $item = $this->item();
        TemplateVersion::factory()->for($item->sourceVersion->template)->create(['version_number' => 2, 'created_by' => $item->package->created_by, 'published' => true, 'width' => 20, 'height' => 20]);

        $this->runItem($item);

        $fresh = $item->fresh();
        $this->assertSame($fresh->source_template_version_id, $fresh->snap->source_template_version_id);
        $media = $fresh->snap->getFirstMedia('image');
        $bytes = Storage::disk('public')->get($media->getPathRelativeToRoot());
        $this->assertSame([CreativePackageItem::STATUS_READY, false, $fresh->snap_id, $media->id, $media->file_name, 'image/png', strlen($bytes), hash('sha256', $bytes)], [$fresh->status, $fresh->retryable, $fresh->snap->id, $fresh->media_id, $fresh->output_filename, $fresh->output_mime_type, $fresh->output_bytes, $fresh->output_sha256]);
        $this->assertSame([20, 20], [$fresh->output_width, $fresh->output_height]);
        $this->assertSame([$this->now()->timestamp, Snap::STATUS_COMPLETED, false], [$fresh->completed_at->timestamp, $fresh->snap->status, $fresh->snap->is_async]);
        $this->assertUsageRecordedOnce($fresh);
        $this->assertPackage($fresh->package, CreativePackage::STATUS_READY, [0, 0, 1, 0]);
    }

    public function test_the_generic_async_recorder_never_records_a_package_snap(): void
    {
        $item = $this->item();
        $this->runItem($item);
        $snap = $item->fresh()->snap;
        $snap->forceFill(['is_async' => true])->save();

        $this->assertNull(UsageRecorder::recordCompletedAsyncSnap($snap->fresh()));

        $this->assertSame(1, ApiLog::count());
    }

    public function test_a_media_mismatch_fails_permanently(): void
    {
        $item = $this->item(width: 1080);

        $this->runItem($item);

        $this->assertFailed($item, 'creative_package_output_invalid', retryable: false);
        $this->assertNotNull($item->fresh()->snap_id);
        $this->assertPackage($item->package, CreativePackage::STATUS_FAILED, [0, 0, 0, 1]);
    }

    public function test_a_renderer_exception_fails_permanently(): void
    {
        $item = $this->item();
        $this->app->instance(SnapRenderer::class, $this->throwingRenderer());

        $this->runItem($item);

        $this->assertFailed($item, 'creative_package_render_failed', retryable: false);
        $this->assertSame('The renderer did not produce an image.', $item->fresh()->failure_details);
        $this->assertPackage($item->package, CreativePackage::STATUS_FAILED, [0, 0, 0, 1]);
    }

    public function test_a_creator_who_lost_entitlement_records_a_retryable_failure_without_a_render(): void
    {
        $item = $this->item();
        User::query()->whereKey($item->package->created_by)->update(['role' => 'viewer']);

        $this->runItem($item);

        $this->assertFailed($item, 'creative_package_entitlement_required', retryable: true);
        $this->assertSame([[], null], [$this->renderer->screenshotCalls, $item->fresh()->snap_id]);
    }

    public function test_a_worker_whose_lease_moved_cannot_record_usage_or_write_a_status(): void
    {
        $item = $this->item();
        $this->renderer = $this->rendererThatRuns(function () use ($item): void {
            CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
            $this->assertNotNull(app(CreativePackageItemFence::class)->claim($item));
        });

        $this->runItem($item);

        $fresh = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_RENDERING, 2, null, null, null, 0], [$fresh->status, $fresh->lease_generation, $fresh->snap_id, $fresh->failure_code, $fresh->api_log_id, ApiLog::count()]);
        $this->assertPackage($item->package, CreativePackage::STATUS_BUILDING, [1, 0, 0, 0]);
    }

    public function test_a_lease_moved_between_claim_and_execute_never_renders(): void
    {
        $item = $this->item();
        $fence = app(CreativePackageItemFence::class);
        $first = $fence->claim($item);
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));
        $this->assertNotNull($fence->claim($item));

        app(CreativePackageItemExecutor::class)->execute($item->fresh(), $first);

        $fresh = $item->fresh();
        $this->assertSame([[], 0, CreativePackageItem::STATUS_RENDERING, 2, null, 0], [$this->renderer->screenshotCalls, Snap::count(), $fresh->status, $fresh->attempts, $fresh->api_log_id, ApiLog::count()]);
    }

    public function test_a_preflight_refusal_fails_permanently_without_claiming_the_renderer_ran(): void
    {
        $item = $this->item();
        $this->partialMock(SnapService::class, fn ($mock) => $mock->shouldReceive('createFromVersion')->once()->andThrow(new SourceVersionUnavailableException('version gone')));

        $this->runItem($item);

        $this->assertFailed($item, 'creative_package_render_failed', retryable: false);
        $this->assertSame(['The source could not be prepared for rendering.', 0, SourceVersionUnavailableException::class], [$item->fresh()->failure_details, Snap::count(), $item->fresh()->diagnostic_exception_class]);
    }

    public function test_the_worker_renews_the_lease_after_the_render(): void
    {
        $item = $this->item();
        $this->renderer = $this->rendererThatRuns(fn () => CarbonImmutable::setTestNow($this->now()->addSeconds(100)));

        $this->runItem($item);

        $renewed = $this->now()->addSeconds(100);
        $fresh = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_READY, $renewed->addSeconds(CreativePackageContract::LEASE_SECONDS)->timestamp], [$fresh->status, $fresh->lease_expires_at->timestamp]);
    }

    public function test_a_replay_after_a_crash_reuses_the_rendered_snap_and_records_usage_once(): void
    {
        $item = $this->item();
        $this->runItem($item);
        $ready = $item->fresh();
        $sha256 = $ready->output_sha256;
        $ready->forceFill(['status' => CreativePackageItem::STATUS_RENDERING, 'retryable' => true, 'media_id' => null, 'output_sha256' => null, 'completed_at' => null, 'lease_expires_at' => $this->now()->subSecond(), 'api_log_id' => null])->save();
        ApiLog::query()->delete();

        $this->runItem($item);

        $fresh = $item->fresh();
        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertSame([CreativePackageItem::STATUS_READY, $ready->snap_id, $sha256, 2], [$fresh->status, $fresh->snap_id, $fresh->output_sha256, $fresh->attempts]);
        $this->assertUsageRecordedOnce($fresh);
    }

    /**
     * The worker persists snap_id as soon as the render returns. A crash on
     * the first media read after that write leaves a row that names its snap,
     * so the retry reuses the render and records usage one time.
     */
    public function test_a_crash_right_after_the_render_leaves_the_snap_id_behind_and_the_replay_reuses_the_snap(): void
    {
        $item = $this->item();
        $crash = true;
        $this->app->instance(SnapService::class, new class(app(IdempotencyClaimer::class), app(SnapSourceVersioner::class), $crash) extends SnapService
        {
            public function __construct(IdempotencyClaimer $claimer, SnapSourceVersioner $sourceVersioner, private bool &$crash)
            {
                parent::__construct($claimer, $sourceVersioner);
            }

            public function createFromVersion(Template $template, TemplateVersion $source, ?array $variants, ?string $type, bool $async, ?string $webhookUrl, int $userId, int $companyId, ?string $format = null, bool $transparent = false, bool $keepErrorMessage = true): Snap
            {
                $snap = parent::createFromVersion($template, $source, $variants, $type, $async, $webhookUrl, $userId, $companyId, $format, $transparent);
                Media::retrieved(function (): void {
                    if ($this->crash) {
                        throw new \RuntimeException('worker lost after the render');
                    }
                });

                return $snap;
            }
        });

        try {
            $this->runItem($item);
            $this->fail('The first run did not crash.');
        } catch (CreativePackageAttemptFailed $exception) {
            $this->assertStringNotContainsString('worker lost after the render', $exception->getMessage());
        }

        $crashed = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_RENDERING, Snap::query()->sole()->id, null, null, null, null, 0], [$crashed->status, $crashed->snap_id, $crashed->media_id, $crashed->output_sha256, $crashed->output_width, $crashed->output_height, ApiLog::count()]);
        $this->assertLessThan($this->now()->timestamp, $crashed->lease_expires_at->timestamp);

        $crash = false;
        $this->runItem($item);

        $fresh = $item->fresh();
        $this->assertCount(1, $this->renderer->screenshotCalls);
        $this->assertSame([CreativePackageItem::STATUS_READY, $crashed->snap_id, 20, 20, 2], [$fresh->status, $fresh->snap_id, $fresh->output_width, $fresh->output_height, $fresh->attempts]);
        $this->assertUsageRecordedOnce($fresh);
    }

    public function test_a_replay_after_usage_was_recorded_marks_the_item_ready_without_a_render_or_a_second_usage_row(): void
    {
        $item = $this->item();
        $this->runItem($item);
        $item->fresh()->forceFill(['status' => CreativePackageItem::STATUS_RENDERING, 'retryable' => true, 'completed_at' => null, 'lease_expires_at' => $this->now()->subSecond()])->save();
        $this->renderer->screenshotCalls = [];

        $this->runItem($item);

        $fresh = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_READY, [], 2], [$fresh->status, $this->renderer->screenshotCalls, $fresh->attempts]);
        $this->assertUsageRecordedOnce($fresh);
    }

    public function test_a_second_worker_cannot_move_a_ready_item(): void
    {
        $item = $this->item();
        $this->runItem($item);
        CarbonImmutable::setTestNow($this->now()->addSeconds(CreativePackageContract::LEASE_SECONDS + 1));

        $this->assertNull(app(CreativePackageItemFence::class)->claim($item));
        $this->runItem($item);

        $this->assertSame([CreativePackageItem::STATUS_READY, 1, 1], [$item->fresh()->status, $item->fresh()->attempts, ApiLog::count()]);
    }

    private function item(int $width = 20): CreativePackageItem
    {
        $actor = $this->member();
        $template = Template::factory()->for($actor->company)->for($actor)->create();
        $source = TemplateVersion::factory()->for($template)->create(['version_number' => 1, 'created_by' => $actor->id, 'published' => true, 'width' => $width, 'height' => 20]);

        return $this->claim($actor, $this->request('key', [$source]))->package->items()->sole();
    }

    private function rendererThatRuns(\Closure $beforeRender): FakeRenderer
    {
        $renderer = new class($beforeRender) extends FakeRenderer
        {
            public function __construct(private \Closure $beforeRender) {}

            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                ($this->beforeRender)();

                return parent::screenshotBase64($html, $width, $height, $format, $transparent);
            }
        };
        $this->app->instance(SnapRenderer::class, $renderer);

        return $renderer;
    }

    private function throwingRenderer(): SnapRenderer
    {
        return $this->rendererThatRuns(fn () => throw new \RuntimeException('chrome secret path /tmp/x'));
    }

    private function assertFailed(CreativePackageItem $item, string $code, bool $retryable): void
    {
        $fresh = $item->fresh();
        $this->assertSame([CreativePackageItem::STATUS_FAILED, $retryable, $code, null, 0], [$fresh->status, $fresh->retryable, $fresh->failure_code, $fresh->api_log_id, ApiLog::count()]);
    }

    /** The item points at the one usage row, and that row counts one render. */
    private function assertUsageRecordedOnce(CreativePackageItem $item): void
    {
        $apiLog = ApiLog::query()->sole();
        $this->assertSame([$apiLog->id, 1], [$item->api_log_id, (int) $apiLog->renders]);
    }

    /** @param array{int, int, int, int} $counts pending, rendering, ready, failed */
    private function assertPackage(CreativePackage $package, string $status, array $counts): void
    {
        $fresh = $package->fresh();
        $this->assertSame([$status, $counts, $status !== CreativePackage::STATUS_BUILDING], [$fresh->status, [$fresh->pending_items_count, $fresh->rendering_items_count, $fresh->ready_items_count, $fresh->failed_items_count], $fresh->completed_at !== null]);
        $this->assertSame($fresh->total_items, array_sum($counts));
    }
}
