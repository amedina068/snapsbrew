<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\Support\FakeRenderer;
use Tests\Support\SelectivelyFailingRenderer;
use Tests\TestCase;

/**
 * Runs the real Bus::batch with a real worker. No Bus::fake() in this file.
 *
 * Every other import test fakes the bus. A faked bus replaces the batch
 * repository and skips PendingBatch::ensureJobIsBatchable(), so a faked suite
 * proves the terminal callbacks and never proves that the batch dispatches.
 * The import returned 500 on every upload while that suite stayed green.
 *
 * The queue is `database` here, not the `sync` driver the rest of the suite
 * uses. Batch::add() runs a sync job at push time and wraps the whole push loop
 * in one try/catch, so the first failed item aborts the loop and the items after
 * it are never pushed. That is an artifact of the sync driver, not the behavior
 * of a worker. Only a real queue plus `queue:work` shows what production does.
 */
class SnapImportRealBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See the class docblock. The sync driver cannot express a batch where
        // one item fails and the rest still run.
        config(['queue.default' => 'database']);

        Mail::fake();
        Http::fake();
        Storage::fake('local');
    }

    /**
     * Run every queued job to completion, the way a worker does.
     *
     * `--tries=1` makes one throw terminal, so `ProcessSnap::failed()` runs on
     * the first failure instead of after a retry cycle.
     */
    private function work(): void
    {
        // The worker runs inside the PHPUnit process, which already sits near
        // the default 128 MB limit late in a full run. Without this, the
        // worker stops after one job and the later rows stay pending.
        $this->artisan('queue:work', [
            '--queue' => Queues::IMPORTS,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--memory' => 512,
        ]);
    }

    private function actingTeam(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user];
    }

    private function templateWithHeadline(int $companyId, int $userId): Template
    {
        return Template::factory()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'layers' => json_encode([[
                'key' => 'headline',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'X']],
            ]]),
        ]);
    }

    /** @param array<int, string> $headlines */
    private function upload(Template $template, array $headlines, string $title = 'Batch')
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'headline');

        foreach ($headlines as $index => $headline) {
            $sheet->setCellValue('A'.($index + 2), $headline);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $this->post("/templates/{$template->uuid}/imports", [
            'title' => $title,
            'file' => new UploadedFile($path, 'import.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_batch_tables_exist_in_the_migration_chain()
    {
        $this->assertTrue(
            Schema::hasTable('job_batches'),
            'Bus::batch() writes to job_batches. Without the table every import fails.'
        );
    }

    public function test_an_import_dispatches_a_real_batch()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, ['Row A', 'Row B'])->assertStatus(201);

        $this->work();

        $import = SnapImport::first();

        $this->assertNotNull($import->batch_id, 'The import recorded no batch id.');
        $this->assertSame(2, $import->snaps()->count());
    }

    public function test_a_failed_item_does_not_stop_the_items_after_it()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, ['Row A', SelectivelyFailingRenderer::MARKER, 'Row C'])
            ->assertStatus(201);

        $this->work();

        $import = SnapImport::first();
        $statuses = $import->snaps()->orderBy('id')->pluck('status', 'identifier')->all();

        $this->assertSame(
            [Snap::STATUS_COMPLETED, Snap::STATUS_FAILED, Snap::STATUS_COMPLETED],
            array_values($statuses),
            'Row 3 must render. A failure on row 2 must not cancel the rest of the batch.'
        );
    }

    /**
     * A failed item must not mark the batch record cancelled.
     *
     * `Batch::recordFailedJob()` calls `$this->cancel()` at the first failure
     * unless the batch allows failures. `SnapImportCancelController` calls the
     * same `cancel()` when a person stops an import, so without
     * `allowFailures()` the two are indistinguishable at the Bus level.
     * `ProcessSnap` reads the cache flag today and not `batch()->cancelled()`,
     * which is why no item is lost right now. The standard Laravel guard is
     * `if ($this->batch()?->cancelled()) return;`, and adding it to a batch that
     * cancels itself on the first failure would drop every item after that one.
     */
    public function test_a_failed_item_does_not_cancel_the_batch_record()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, ['Row A', SelectivelyFailingRenderer::MARKER, 'Row C'])
            ->assertStatus(201);

        $this->work();

        $batch = Bus::findBatch(SnapImport::first()->batch_id);

        $this->assertNotNull($batch);
        $this->assertFalse(
            $batch->cancelled(),
            'Only a person cancels a batch. A failed item must not.'
        );
    }

    public function test_an_import_with_one_failed_item_is_completed_not_failed()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, ['Row A', SelectivelyFailingRenderer::MARKER, 'Row C'])
            ->assertStatus(201);

        $this->work();

        $import = SnapImport::first()->fresh();

        $this->assertSame(SnapImport::STATUS_COMPLETED, $import->status);
    }

    public function test_an_import_where_every_item_failed_is_marked_failed()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, [
            SelectivelyFailingRenderer::MARKER.' one',
            SelectivelyFailingRenderer::MARKER.' two',
        ])->assertStatus(201);

        $this->work();

        $import = SnapImport::first()->fresh();

        $this->assertSame(SnapImport::STATUS_FAILED, $import->status);
    }

    public function test_the_terminal_item_counts_add_up_to_the_total()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, ['Row A', SelectivelyFailingRenderer::MARKER, 'Row C'])
            ->assertStatus(201);

        $this->work();

        $snapshot = SnapImport::first()->fresh()->progressSnapshot();

        $this->assertContains($snapshot['status'], [
            SnapImport::STATUS_COMPLETED,
            SnapImport::STATUS_FAILED,
            SnapImport::STATUS_CANCELED,
        ]);

        $this->assertSame(
            $snapshot['total_rows'],
            $snapshot['completed'] + $snapshot['failed'] + $snapshot['canceled'],
            'At a terminal status every item must hold a terminal state.'
        );
        $this->assertSame(0, $snapshot['pending']);
    }

    public function test_only_the_items_that_rendered_are_recorded()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, ['Row A', SelectivelyFailingRenderer::MARKER, 'Row C'])
            ->assertStatus(201);

        $this->work();

        $import = SnapImport::first();

        $this->assertSame(
            2,
            (int) ApiLog::query()
                ->where('url', 'snap-import:'.$import->uuid)
                ->sum('renders'),
            'Two items rendered, so the batch records two renders.'
        );
    }

    public function test_the_first_failure_message_is_kept_on_the_import()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $this->upload($template, [
            SelectivelyFailingRenderer::MARKER.' one',
            SelectivelyFailingRenderer::MARKER.' two',
        ])->assertStatus(201);

        $this->work();

        $this->assertSame(
            'render exploded on purpose',
            SnapImport::first()->fresh()->error_message
        );
    }

    /**
     * B-002. A throw part way through the write must leave no rows behind.
     *
     * The rows were created one at a time with no transaction, so an exception
     * at row k committed rows 1 to k-1. Those rows sat at `pending` with no
     * batch to render them and no job to finish them, for ever.
     *
     * The throw is injected through the model event on Snap, which fires inside
     * the loop, at the same place a real constraint violation fires.
     */
    public function test_a_throw_part_way_through_the_write_leaves_no_rows_behind()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $created = 0;

        Snap::creating(function () use (&$created) {
            if (++$created === 3) {
                throw new RuntimeException('write exploded on row 3');
            }
        });

        try {
            $this->upload($template, ['Row A', 'Row B', 'Row C', 'Row D']);
        } catch (RuntimeException) {
            // The controller does not catch this. The assertion below is the point.
        }

        $this->assertSame(0, Snap::count(), 'Rows 1 and 2 must roll back with row 3.');
        $this->assertSame(0, SnapImport::count(), 'The import row must roll back too.');
    }

    /**
     * Risk check: the rows must be committed before the first job is dispatched.
     *
     * `ProcessSnap::handle()` starts with `$this->snap->fresh(...)` and returns
     * early when that read finds nothing. A job dispatched from inside an open
     * transaction can reach a worker before the commit is visible, and that item
     * disappears with no error.
     */
    public function test_every_row_is_readable_once_the_batch_is_dispatched()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        // RefreshDatabase holds one transaction around the whole test, so the
        // baseline is 1, not 0. What matters is that the write opens none of its
        // own and leaves none open.
        $baseline = DB::transactionLevel();

        $this->upload($template, ['Row A', 'Row B', 'Row C'])->assertStatus(201);

        // The response returns after dispatch. Read the rows on a fresh query:
        // if any insert were still open, this count would be short.
        $this->assertSame(3, Snap::query()->whereNotNull('snap_import_id')->count());
        $this->assertSame($baseline, DB::transactionLevel(), 'The write left a transaction open.');

        $this->work();

        $this->assertSame(3, Snap::where('status', Snap::STATUS_COMPLETED)->count());
    }

    /**
     * Pins the webhook behavior the web surface promises its users.
     *
     * `resources/views/templates/snap-imports/create.blade.php:36-40` states that
     * an import posts one `snap.completed` event per item plus one terminal
     * event. The controller sets `webhook_url` on the import row and on every
     * snap row to produce that. The batch API that follows this change must
     * define its own contract instead of copying this one.
     */
    public function test_the_web_import_posts_one_event_per_item_plus_one_terminal_event()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithHeadline($company->id, $user->id);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'headline');
        $sheet->setCellValue('A2', 'Row A');
        $sheet->setCellValue('A3', 'Row B');
        $path = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Hooked',
            'webhook_url' => 'https://example.com/hook',
            'file' => new UploadedFile($path, 'import.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->work();

        Http::assertSent(fn ($request) => $request['event'] === 'snap-import.completed');
        $this->assertSame(
            2,
            Http::recorded(fn ($request) => $request['event'] === 'snap.completed')->count(),
            'Two items rendered, so the caller receives two per-item events.'
        );
    }
}
