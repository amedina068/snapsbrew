<?php

namespace Tests\Feature;

use App\Mail\SnapImportFinishedMail;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the Bus::batch closures SnapBatchService registers for an import
 * started through the web upload.
 *
 * The closures are one-liners and the finalizer is unit-tested directly. These
 * tests confirm that the controller captures the import and calls the right
 * finalizer method for each callback.
 *
 * Laravel runs `then()` only when zero jobs failed, so it cannot describe a
 * batch that ran to the end with some items failed. No `then()` callback is
 * registered for this reason. `catch()` records the first failure message and
 * nothing else, because it fires while the rest of the batch is still running.
 * `finally()` runs one time after every item reached a terminal state and
 * decides the status there.
 *
 * `tests/Feature/SnapImportRealBatchTest` covers the same ground with a real
 * worker and no Bus::fake(). Keep both: this file proves the wiring, that file
 * proves the behavior.
 */
class SnapImportBatchFailureTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeam(int $credits = 100): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user];
    }

    private function templateWithLayers(int $companyId, int $userId): Template
    {
        return Template::factory()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'layers' => json_encode([
                [
                    'key' => 'headline',
                    'type' => 'text',
                    'properties' => [['name' => 'textContent', 'value' => 'X']],
                ],
            ]),
        ]);
    }

    private function makeXlsx(array $headerRow, array $dataRows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headerRow as $column => $value) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + 1).'1', $value);
        }

        foreach ($dataRows as $rowIndex => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + 1).($rowIndex + 2), $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    /**
     * After Bus::fake(), invoke the captured PendingBatch callbacks directly
     * through the public *Callbacks() accessors on PendingBatch.
     */
    private function invokeBatchCallbacks(string $kind, ?\Throwable $exception = null): void
    {
        Bus::assertBatched(function ($batch) use ($kind, $exception) {
            $callbacks = match ($kind) {
                'then' => $batch->thenCallbacks(),
                'catch' => $batch->catchCallbacks(),
                'finally' => $batch->finallyCallbacks(),
            };

            $fakeBatch = \Mockery::mock(Batch::class);

            foreach ($callbacks as $callback) {
                if ($kind === 'catch') {
                    $callback($fakeBatch, $exception);
                } else {
                    $callback($fakeBatch);
                }
            }

            return true;
        });
    }

    public function test_batch_finally_marks_the_import_completed_when_an_item_rendered()
    {
        Bus::fake();
        Mail::fake();
        Http::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Completes',
            'webhook_url' => 'https://example.com/hook',
            'file' => $this->makeXlsx(['headline'], [['Row A']]),
        ])->assertStatus(201);

        $import = SnapImport::first();
        $this->assertSame(SnapImport::STATUS_PROCESSING, $import->status);

        // Bus::fake() runs no job, so the item state is set here. `finalize()`
        // reads the item counts to decide the batch status.
        $import->snaps()->update(['status' => Snap::STATUS_COMPLETED]);

        $this->invokeBatchCallbacks('finally');

        $fresh = $import->fresh();
        $this->assertSame(SnapImport::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);

        Mail::assertQueued(SnapImportFinishedMail::class);
        Http::assertSent(fn ($req) => $req['event'] === 'snap-import.completed'
            && $req->url() === 'https://example.com/hook'
        );
    }

    public function test_batch_finally_marks_the_import_completed_when_only_some_items_rendered()
    {
        Bus::fake();
        Mail::fake();
        Http::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Partly completes',
            'file' => $this->makeXlsx(['headline'], [['Row A'], ['Row B']]),
        ])->assertStatus(201);

        $import = SnapImport::first();
        $snaps = $import->snaps()->orderBy('id')->get();

        $snaps[0]->update(['status' => Snap::STATUS_COMPLETED]);
        $snaps[1]->update(['status' => Snap::STATUS_FAILED]);

        $this->invokeBatchCallbacks('finally');

        $this->assertSame(
            SnapImport::STATUS_COMPLETED,
            $import->fresh()->status,
            'One rendered item makes the batch completed. The caller reads the failed count.'
        );
    }

    public function test_batch_catch_records_the_message_and_leaves_the_status_alone()
    {
        Bus::fake();
        Mail::fake();
        Http::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Fails',
            'webhook_url' => 'https://example.com/hook',
            'file' => $this->makeXlsx(['headline'], [['Row A'], ['Row B']]),
        ])->assertStatus(201);

        $import = SnapImport::first();

        $this->invokeBatchCallbacks('catch', new RuntimeException('disk full'));

        $fresh = $import->fresh();
        $this->assertSame('disk full', $fresh->error_message);
        $this->assertSame(
            SnapImport::STATUS_PROCESSING,
            $fresh->status,
            'The batch is still running here. Only `finally` decides the status.'
        );

        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_batch_finally_marks_the_import_failed_when_no_item_rendered()
    {
        Bus::fake();
        Mail::fake();
        Http::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Fails',
            'webhook_url' => 'https://example.com/hook',
            'file' => $this->makeXlsx(['headline'], [['Row A']]),
        ])->assertStatus(201);

        $import = SnapImport::first();

        $this->invokeBatchCallbacks('catch', new RuntimeException('disk full'));
        $import->snaps()->update(['status' => Snap::STATUS_FAILED]);

        $this->invokeBatchCallbacks('finally');

        $fresh = $import->fresh();
        $this->assertSame(SnapImport::STATUS_FAILED, $fresh->status);
        $this->assertSame(
            'disk full',
            $fresh->error_message,
            'The message `catch` stored must survive the terminal write.'
        );

        Mail::assertQueued(SnapImportFinishedMail::class);
        Http::assertSent(fn ($req) => $req['event'] === 'snap-import.failed');
    }

    public function test_batch_finally_clears_the_cancellation_cache_flag()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Clears flag',
            'file' => $this->makeXlsx(['headline'], [['Row A']]),
        ])->assertStatus(201);

        $import = SnapImport::first();

        Cache::put(
            SnapImport::cancellationCacheKey($import->id),
            now(),
            60
        );

        $this->invokeBatchCallbacks('finally');

        $this->assertFalse(Cache::has(
            SnapImport::cancellationCacheKey($import->id)
        ));
    }
}
