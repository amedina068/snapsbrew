<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A spreadsheet cell that starts with `=` is a formula in Excel. The import
 * template must write layer text as text, and an import must read such a cell
 * as the text that the customer typed, never as a computed value.
 */
class SnapImportFormulaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function scratchFile(string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), 'formula_');
        $this->files[] = $base;
        $this->files[] = $base.$suffix;

        return $base.$suffix;
    }

    private function template(string $headline = 'Default headline'): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => $headline]]],
            ]),
        ]);

        return [$template, $user];
    }

    private function upload(Template $template, UploadedFile $file)
    {
        return $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Formula import',
            'file' => $file,
        ], ['Accept' => 'application/json']);
    }

    private function importedHeadline(Template $template): string
    {
        $snap = Snap::query()->where('template_id', $template->id)->firstOrFail();

        return collect(json_decode($snap->variants, true))->firstWhere('layer', 'headline')['text'];
    }

    public function test_the_import_template_writes_layer_text_that_starts_with_an_equals_sign_as_text()
    {
        [$template] = $this->template('=HYPERLINK("https://link.invalid","Open")');

        $response = $this->get("/templates/{$template->uuid}/imports/export");
        $response->assertOk();

        $path = $this->scratchFile('.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $cell = $spreadsheet->getActiveSheet()->getCell('A2');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=HYPERLINK("https://link.invalid","Open")', $cell->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_a_csv_cell_that_starts_with_an_equals_sign_stays_text()
    {
        Bus::fake();
        Storage::fake('local');
        [$template] = $this->template();

        $path = $this->scratchFile('.csv');
        file_put_contents($path, "headline\n=1+1\n");

        $this->upload($template, new UploadedFile($path, 'rows.csv', null, null, true))->assertStatus(201);

        $this->assertSame('=1+1', $this->importedHeadline($template));
    }

    public function test_a_malformed_formula_in_a_csv_cell_stays_text()
    {
        Bus::fake();
        Storage::fake('local');
        [$template] = $this->template();

        $path = $this->scratchFile('.csv');
        file_put_contents($path, "headline\n=SUM(\n");

        $this->upload($template, new UploadedFile($path, 'rows.csv', null, null, true))->assertStatus(201);

        $this->assertSame('=SUM(', $this->importedHeadline($template));
    }

    public function test_an_xlsx_formula_cell_imports_as_its_formula_text()
    {
        Bus::fake();
        Storage::fake('local');
        [$template] = $this->template();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'headline');
        $sheet->setCellValue('A2', '=1+1');
        $path = $this->scratchFile('.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->upload($template, new UploadedFile($path, 'rows.xlsx', null, null, true))->assertStatus(201);

        $this->assertSame('=1+1', $this->importedHeadline($template));
    }
}
