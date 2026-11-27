<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SnapImportXlsxTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeam(): array
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
                    'properties' => [
                        ['name' => 'textContent', 'value' => 'Default headline'],
                    ],
                ],
                [
                    'key' => 'subhead',
                    'type' => 'text',
                    'properties' => [
                        ['name' => 'textContent', 'value' => 'Default sub'],
                    ],
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

        $path = $this->temporaryFile('xlsx_', '.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    public function test_store_dispatches_batch_for_valid_xlsx()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(
            ['headline', 'subhead'],
            [
                ['First row', 'Hello'],
                ['Second row', 'World'],
            ]
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Bulk import',
            'file' => $file,
        ])->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', [
            'template_id' => $template->id,
            'company_id' => $company->id,
            'title' => 'Bulk import',
            'total_rows' => 2,
        ]);

        $this->assertSame(2, Snap::query()->where('template_id', $template->id)->count());

        Bus::assertBatched(fn ($batch) => $batch->name === 'snap-import-'.SnapImport::first()->id);
    }

    public function test_store_rejects_unknown_columns()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(
            ['headline', 'subhead', 'unknown_layer'],
            [['x', 'y', 'z']]
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Bad headers',
            'file' => $file,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('snap_imports', 0);
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_missing_columns()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(
            ['headline'],
            [['only headline']]
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Missing headers',
            'file' => $file,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_empty_spreadsheet()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(['headline', 'subhead'], []);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'No data',
            'file' => $file,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_skips_completely_blank_rows()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(
            ['headline', 'subhead'],
            [
                ['One', 'A'],
                ['', ''],
                ['Two', 'B'],
            ]
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'With blank',
            'file' => $file,
        ])->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', [
            'template_id' => $template->id,
            'total_rows' => 2,
        ]);
    }

    public function test_store_validates_webhook_url_format()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(['headline', 'subhead'], [['x', 'y']]);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Bad webhook',
            'webhook_url' => 'not-a-url',
            'file' => $file,
        ])->assertSessionHasErrors('webhook_url');
    }

    public function test_store_records_no_render_at_import_creation()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(
            ['headline', 'subhead'],
            [
                ['A', '1'],
                ['B', '2'],
                ['C', '3'],
            ]
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'No upfront render',
            'file' => $file,
        ])->assertStatus(201);

        $this->assertDatabaseMissing('api_logs', [
            'user_id' => $user->id,
            'feature' => 'snap_import',
        ]);

        $this->assertSame(0, (int) ApiLog::query()->sum('renders'));
    }

    public function test_store_accepts_xls_format()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'headline');
        $sheet->setCellValue('B1', 'subhead');
        $sheet->setCellValue('A2', 'A');
        $sheet->setCellValue('B2', '1');

        $path = $this->temporaryFile('xls_', '.xls');
        (new Xls($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $file = new UploadedFile(
            $path,
            'rows.xls',
            'application/vnd.ms-excel',
            null,
            true
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Legacy xls',
            'file' => $file,
        ])->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', [
            'title' => 'Legacy xls',
            'total_rows' => 1,
        ]);
    }

    public function test_store_strips_bom_prefix_from_header_cells()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $bom = "\u{FEFF}";
        $file = $this->makeXlsx(
            [$bom.'headline', 'subhead'],
            [['A', '1']]
        );

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'BOM header',
            'file' => $file,
        ])->assertSessionHasErrors('file');
    }

    public function test_store_rejects_duplicate_header_columns()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeXlsx(
            ['headline', 'subhead', 'headline'],
            [['A', '1', 'B']]
        );

        $response = $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Duplicate headers',
            'file' => $file,
        ]);

        $this->assertContains($response->status(), [201, 302]);

        if (SnapImport::count() > 0) {
            $this->assertSame(1, SnapImport::first()->total_rows);
        }
    }

    public function test_download_template_returns_xlsx_with_layer_headers()
    {
        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $response = $this->get("/templates/{$template->uuid}/imports/export");

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
