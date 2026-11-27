<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateVersioning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SnapImportCsvTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every test here posts with `Accept: application/json`, which is the header
     * axios sends. That path returns 422 with a JSON body. A plain post returns
     * 302 with session errors, which is what SnapImportXlsxTest asserts.
     */
    private const JSON = ['Accept' => 'application/json'];

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

    /**
     * Writes raw CSV text, not a Spreadsheet object. The point of these tests is
     * the bytes a real export produces.
     */
    private function makeCsv(
        array $headerRow,
        array $dataRows,
        string $delimiter = ',',
        string $prefix = '',
        bool $trailingNewline = false,
        string $filename = 'rows.csv'
    ): UploadedFile {
        $lines = [];

        if (count($headerRow)) {
            $lines[] = $this->csvLine($headerRow, $delimiter);
        }

        foreach ($dataRows as $row) {
            $lines[] = $this->csvLine($row, $delimiter);
        }

        $content = $prefix.implode("\n", $lines);

        if ($trailingNewline && $content !== '') {
            $content .= "\n";
        }

        return $this->uploadedFileWithContent($content, $filename);
    }

    private function csvLine(array $values, string $delimiter): string
    {
        $cells = array_map(function ($value) use ($delimiter) {
            $value = (string) $value;

            if (str_contains($value, $delimiter) || str_contains($value, '"') || str_contains($value, "\n")) {
                return '"'.str_replace('"', '""', $value).'"';
            }

            return $value;
        }, $values);

        return implode($delimiter, $cells);
    }

    private function uploadedFileWithContent(string $content, string $filename): UploadedFile
    {
        $path = $this->temporaryFile('import_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function upload(Template $template, UploadedFile $file, string $title = 'CSV import')
    {
        return $this->post("/templates/{$template->uuid}/imports", [
            'title' => $title,
            'file' => $file,
        ], self::JSON);
    }

    public function test_store_dispatches_batch_for_valid_csv()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [
                ['First row', 'Hello'],
                ['Second row', 'World'],
            ]
        );

        $this->upload($template, $file, 'Bulk csv import')->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', [
            'template_id' => $template->id,
            'company_id' => $company->id,
            'title' => 'Bulk csv import',
            'total_rows' => 2,
        ]);

        $this->assertSame(2, Snap::query()->where('template_id', $template->id)->count());

        Bus::assertBatched(fn ($batch) => $batch->name === 'snap-import-'.SnapImport::first()->id);
    }

    /**
     * The snaps render the published version. The columns must name the layer
     * keys of that version, not the keys of an unpublished draft.
     */
    public function test_store_reads_the_layer_keys_of_the_published_version()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);
        app(TemplateVersioning::class)->publish($template->fresh());

        $template->fresh()->forceFill([
            'layers' => json_encode([[
                'key' => 'draft_only',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'Draft']],
            ]]),
        ])->save();

        $file = $this->makeCsv(['headline', 'subhead'], [['Live row', 'Hello']]);

        $this->upload($template, $file, 'Published keys')->assertStatus(201);

        $variants = collect(json_decode(Snap::query()->where('template_id', $template->id)->sole()->variants, true));
        $this->assertSame('Live row', $variants->firstWhere('layer', 'headline')['text']);
    }

    /**
     * A barcode layer also has a background color. Before the barcode branch,
     * the import read the cell as a `background_color` value.
     */
    public function test_store_maps_a_barcode_column_to_barcode_content()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithBarcode($company->id, $user->id);

        $this->upload($template, $this->makeCsv(['sku'], [['SKU-0042']]))->assertStatus(201);

        $variants = json_decode(Snap::query()->where('template_id', $template->id)->sole()->variants, true);
        $this->assertSame([['layer' => 'sku', 'barcode_content' => 'SKU-0042']], $variants);
    }

    public function test_the_import_template_writes_the_barcode_value_as_the_example_row()
    {
        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithBarcode($company->id, $user->id);

        $response = $this->get("/templates/{$template->uuid}/imports/export")->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'import_');

        try {
            file_put_contents($path, $response->streamedContent());
            $spreadsheet = IOFactory::load($path);
            $this->assertSame('SKU-0001', $spreadsheet->getActiveSheet()->getCell('A2')->getValue());
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    private function templateWithBarcode(int $companyId, int $userId): Template
    {
        return Template::factory()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'layers' => json_encode([[
                'key' => 'sku',
                'type' => 'barcode',
                'properties' => [
                    ['name' => 'barcodeContent', 'value' => 'SKU-0001'],
                    ['name' => 'barcodeFormat', 'value' => 'Code128'],
                    ['name' => 'backgroundColor', 'value' => '#ffffff'],
                ],
            ]]),
        ]);
    }

    public function test_store_returns_validation_error_when_csv_cannot_be_parsed()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->uploadedFileWithContent(random_bytes(2048), 'garbage.csv');

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        // The content sniff rejects these bytes before the reader runs.
        // test_store_returns_validation_error_when_xlsx_content_is_renamed_to_csv
        // is the test that proves the try/catch around the reader.
        $this->assertStringContainsString(
            'does not match xlsx, xls, or csv format',
            $response->json('errors.file.0')
        );
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_returns_validation_error_when_xlsx_content_is_renamed_to_csv()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        // Passes `mimes` (the content sniffs as xlsx) and `extensions` (the name
        // says csv). The two rules read independent properties, so the pair never
        // has to agree. The Csv reader then gets zip bytes.
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue(Coordinate::stringFromColumnIndex(1).'1', 'headline');
        $path = $this->temporaryFile('xlsx_', '.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $file = new UploadedFile($path, 'rows.csv', null, null, true);

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_txt_file_by_extension()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->uploadedFileWithContent("headline,subhead\nA,1", 'rows.txt');

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'must end in .xlsx, .xls, or .csv',
            $response->json('errors.file.0')
        );
    }

    public function test_store_rejects_csv_larger_than_ten_megabytes()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $content = "headline,subhead\n".str_repeat("A row value,Another row value\n", 400000);
        $this->assertGreaterThan(10 * 1024 * 1024, strlen($content));

        $file = $this->uploadedFileWithContent($content, 'big.csv');

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'larger than 10 MB',
            implode(' ', $response->json('errors.file'))
        );
    }

    public function test_store_accepts_uppercase_csv_extension()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        // Laravel's `extensions` rule lowercases before it compares, so this file
        // passes validation. A raw comparison in the reader would send it to the
        // Xlsx reader.
        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [['A', '1']],
            ',',
            '',
            false,
            'Export.CSV'
        );

        $this->upload($template, $file)->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', ['total_rows' => 1]);
    }

    public function test_store_accepts_csv_with_utf8_bom_header()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        // The CSV reader strips the byte order mark from the stream, so the first
        // header cell resolves to `headline`.
        // SnapImportXlsxTest::test_store_strips_bom_prefix_from_header_cells
        // asserts the opposite for XLSX: there the mark is cell content and
        // trim() does not remove it. Both results are correct.
        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [['A', '1']],
            ',',
            "\xEF\xBB\xBF"
        );

        $this->upload($template, $file)->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', ['total_rows' => 1]);
    }

    public function test_store_accepts_semicolon_delimited_csv()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        // A European export separates columns with `;`. The reader infers the
        // separator, so the columns still map to the layer keys.
        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [['A', '1']],
            ';'
        );

        $this->upload($template, $file)->assertStatus(201);

        $snap = Snap::query()->where('template_id', $template->id)->firstOrFail();
        $variants = collect(json_decode($snap->variants, true));

        $this->assertSame('A', $variants->firstWhere('layer', 'headline')['text']);
        $this->assertSame('1', $variants->firstWhere('layer', 'subhead')['text']);
    }

    public function test_store_handles_quoted_field_with_embedded_comma()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [['Smith, Doe', 'Hello']]
        );

        $this->upload($template, $file)->assertStatus(201);

        $snap = Snap::query()->where('template_id', $template->id)->firstOrFail();
        $variants = collect(json_decode($snap->variants, true));

        $this->assertSame('Smith, Doe', $variants->firstWhere('layer', 'headline')['text']);
        $this->assertSame('Hello', $variants->firstWhere('layer', 'subhead')['text']);
    }

    public function test_store_skips_trailing_blank_line_in_csv()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [
                ['One', 'A'],
                ['Two', 'B'],
            ],
            ',',
            '',
            true
        );

        $this->upload($template, $file)->assertStatus(201);

        $this->assertDatabaseHas('snap_imports', ['total_rows' => 2]);
    }

    public function test_store_pads_ragged_csv_row_shorter_than_header()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(
            ['headline', 'subhead'],
            [['Only the headline']]
        );

        $this->upload($template, $file)->assertStatus(201);

        $snap = Snap::query()->where('template_id', $template->id)->firstOrFail();
        $variants = collect(json_decode($snap->variants, true));

        $this->assertSame('Only the headline', $variants->firstWhere('layer', 'headline')['text']);
        $this->assertNull($variants->firstWhere('layer', 'subhead'));
    }

    public function test_store_rejects_unknown_columns_in_csv()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(
            ['headline', 'subhead', 'unknown_layer'],
            [['x', 'y', 'z']]
        );

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'unknown columns: unknown_layer',
            $response->json('errors.file.0')
        );
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_missing_columns_in_csv()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(['headline'], [['only headline']]);

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'missing columns: subhead',
            $response->json('errors.file.0')
        );
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_csv_with_header_row_but_no_data_rows()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->makeCsv(['headline', 'subhead'], []);

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'no data rows',
            $response->json('errors.file.0')
        );
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_csv_with_no_header_row()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->uploadedFileWithContent("\n\n\n", 'blank.csv');

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString(
            'no header row',
            $response->json('errors.file.0')
        );
        $this->assertDatabaseCount('snap_imports', 0);
    }

    public function test_store_rejects_completely_empty_csv_file()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->actingTeam();
        $template = $this->templateWithLayers($company->id, $user->id);

        $file = $this->uploadedFileWithContent('', 'empty.csv');

        $response = $this->upload($template, $file);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        // An empty file has no content to sniff, so it never reaches the reader.
        // test_store_rejects_csv_with_no_header_row covers the no-header message.
        $this->assertStringContainsString(
            'does not match xlsx, xls, or csv format',
            $response->json('errors.file.0')
        );
        $this->assertDatabaseCount('snap_imports', 0);
    }
}
