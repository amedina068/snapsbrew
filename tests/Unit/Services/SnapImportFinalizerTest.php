<?php

namespace Tests\Unit\Services;

use App\Mail\SnapImportFinishedMail;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapImportFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SnapImportFinalizerTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(string $status = SnapImport::STATUS_PROCESSING, ?string $webhookUrl = null): SnapImport
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        return SnapImport::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'status' => $status,
            'webhook_url' => $webhookUrl,
        ]);
    }

    public function test_mark_completed_updates_import_and_dispatches_notifications()
    {
        Mail::fake();
        Http::fake();

        $import = $this->makeImport(SnapImport::STATUS_PROCESSING, 'https://example.com/hook');

        SnapImportFinalizer::markCompleted($import);

        $fresh = $import->fresh();
        $this->assertSame(SnapImport::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->error_message);

        Mail::assertQueued(SnapImportFinishedMail::class, function ($mail) use ($fresh) {
            return $mail->import->id === $fresh->id;
        });

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/hook'
            && $request['event'] === 'snap-import.completed'
        );
    }

    public function test_mark_completed_is_noop_when_import_is_already_canceled()
    {
        Mail::fake();
        Http::fake();

        $import = $this->makeImport(SnapImport::STATUS_CANCELED, 'https://example.com/hook');

        SnapImportFinalizer::markCompleted($import);

        $this->assertSame(SnapImport::STATUS_CANCELED, $import->fresh()->status);
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_mark_failed_records_error_and_notifies()
    {
        Mail::fake();
        Http::fake();

        $import = $this->makeImport(SnapImport::STATUS_PROCESSING, 'https://example.com/hook');

        SnapImportFinalizer::markFailed($import, new RuntimeException('boom'));

        $fresh = $import->fresh();
        $this->assertSame(SnapImport::STATUS_FAILED, $fresh->status);
        $this->assertSame('boom', $fresh->error_message);

        Mail::assertQueued(SnapImportFinishedMail::class);
        Http::assertSent(fn ($request) => $request['event'] === 'snap-import.failed');
    }

    public function test_mark_canceled_stamps_canceled_at_and_notifies()
    {
        Mail::fake();
        Http::fake();

        $import = $this->makeImport(SnapImport::STATUS_PROCESSING, 'https://example.com/hook');

        SnapImportFinalizer::markCanceled($import);

        $fresh = $import->fresh();
        $this->assertSame(SnapImport::STATUS_CANCELED, $fresh->status);
        $this->assertNotNull($fresh->canceled_at);

        Http::assertSent(fn ($request) => $request['event'] === 'snap-import.canceled');
    }

    public function test_skips_mail_when_user_is_missing()
    {
        Mail::fake();
        Http::fake();

        $import = $this->makeImport(SnapImport::STATUS_PROCESSING);
        $import->user->forceDelete();

        SnapImportFinalizer::markCompleted($import->fresh());

        Mail::assertNothingQueued();
    }

    public function test_clear_cancellation_flag_forgets_the_cache_key()
    {
        $import = $this->makeImport();

        Cache::put(SnapImport::cancellationCacheKey($import->id), now(), 60);

        SnapImportFinalizer::clearCancellationFlag($import);

        $this->assertFalse(Cache::has(SnapImport::cancellationCacheKey($import->id)));
    }

    public function test_cancel_stops_pending_items_and_leaves_completed_ones()
    {
        Mail::fake();
        Http::fake();

        $import = SnapImport::factory()->create([
            'status' => SnapImport::STATUS_PROCESSING,
            'total_rows' => 3,
            'processed_rows' => 1,
        ]);

        Snap::factory()->create(['snap_import_id' => $import->id, 'status' => Snap::STATUS_COMPLETED]);
        Snap::factory()->create(['snap_import_id' => $import->id, 'status' => Snap::STATUS_PENDING]);
        Snap::factory()->create(['snap_import_id' => $import->id, 'status' => Snap::STATUS_PROCESSING]);

        $this->assertTrue(SnapImportFinalizer::cancel($import));

        $fresh = $import->fresh();
        $this->assertSame(SnapImport::STATUS_CANCELED, $fresh->status);
        $this->assertNotNull($fresh->canceled_at);
        $this->assertSame(1, $fresh->snaps()->where('status', Snap::STATUS_COMPLETED)->count());
        $this->assertSame(2, $fresh->snaps()->where('status', Snap::STATUS_CANCELED)->count());
        $this->assertSame(3, $fresh->processed_rows);
    }

    public function test_cancel_sets_the_flag_that_a_running_worker_reads()
    {
        Mail::fake();
        Http::fake();

        $import = SnapImport::factory()->create(['status' => SnapImport::STATUS_PROCESSING]);

        SnapImportFinalizer::cancel($import);

        $this->assertTrue(
            Cache::has(SnapImport::cancellationCacheKey($import->id)),
            'A worker that starts an item after the cancel reads this flag and stops.'
        );
    }

    public function test_cancel_does_nothing_to_a_batch_that_already_finished()
    {
        Mail::fake();
        Http::fake();

        foreach ([
            SnapImport::STATUS_COMPLETED,
            SnapImport::STATUS_FAILED,
            SnapImport::STATUS_CANCELED,
        ] as $status) {
            $import = SnapImport::factory()->create(['status' => $status]);

            $this->assertFalse(
                SnapImportFinalizer::cancel($import),
                "A batch at {$status} must be left alone."
            );
            $this->assertSame($status, $import->fresh()->status);
        }

        Http::assertNothingSent();
    }
}
