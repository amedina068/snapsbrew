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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A spreadsheet cell becomes a snap variant. The cells must pass the same
 * variant rules as the REST API and the MCP tools before a snap is queued.
 * A file can also hold only a limited number of rows.
 */
class SnapImportRowValidationTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function template(): Template
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => 'Hello']]],
                ['key' => 'photo', 'type' => 'image', 'properties' => [['name' => 'backgroundImage', 'value' => '']]],
                ['key' => 'panel', 'type' => 'empty', 'properties' => [['name' => 'backgroundColor', 'value' => '#ffffff']]],
            ]),
        ]);
    }

    private function csv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rows_');
        $this->files[] = $path;
        file_put_contents($path, $content);

        return new UploadedFile($path, 'rows.csv', null, null, true);
    }

    private function xlsx(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rows_');
        $this->files[] = $path;
        $this->files[] = $path.'.xlsx';
        (new Xlsx($spreadsheet))->save($path.'.xlsx');
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path.'.xlsx', 'rows.xlsx', null, null, true);
    }

    private function upload(Template $template, UploadedFile $file)
    {
        return $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Row checks',
            'file' => $file,
        ], self::JSON);
    }

    public function test_an_image_cell_with_a_private_address_is_refused()
    {
        $template = $this->template();

        $response = $this->upload($template, $this->csv(
            "headline,photo,panel\n"
            ."First,http://93.184.216.34/a.png,#000000\n"
            ."Second,http://127.0.0.1:6379/a.png,#000000\n"
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('Row 3, column photo', $response->json('errors.file.0'));
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_an_image_cell_with_a_file_url_is_refused()
    {
        $template = $this->template();

        $response = $this->upload($template, $this->csv(
            "headline,photo,panel\nFirst,file:///etc/hostname,#000000\n"
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('Row 2, column photo', $response->json('errors.file.0'));
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_a_color_cell_with_a_css_url_is_refused()
    {
        $template = $this->template();

        $response = $this->upload($template, $this->csv(
            "headline,photo,panel\nFirst,,url(http://10.0.0.1/x.png)\n"
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('Row 2, column panel', $response->json('errors.file.0'));
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_the_row_number_counts_blank_rows_in_the_file()
    {
        $template = $this->template();

        $response = $this->upload($template, $this->csv(
            "headline,photo,panel\nFirst,,#000000\n,,\nThird,,not-a-color!\n"
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString('Row 4, column panel', $response->json('errors.file.0'));
    }

    public function test_valid_cells_still_import_with_the_same_identifiers()
    {
        $template = $this->template();

        $this->upload($template, $this->csv(
            "headline,photo,panel\n"
            ."First,http://93.184.216.34/a.png,#000000\n"
            .",,\n"
            ."Second,http://93.184.216.34/a.png,\"rgb(1, 2, 3)\"\n"
        ))->assertStatus(201);

        $identifiers = Snap::query()->orderBy('id')->pluck('identifier')->all();
        $this->assertCount(2, $identifiers);
        $this->assertStringEndsWith('-1', $identifiers[0]);
        $this->assertStringEndsWith('-2', $identifiers[1]);
    }

    public function test_a_csv_with_more_rows_than_the_limit_is_refused()
    {
        config(['snapsbrew.import_max_rows' => 3]);
        $template = $this->template();

        $response = $this->upload($template, $this->csv(
            "headline,photo,panel\n".str_repeat("Row,,#000000\n", 4)
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('at most 3 rows', $response->json('errors.file.0'));
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_a_csv_at_the_limit_imports()
    {
        config(['snapsbrew.import_max_rows' => 3]);
        $template = $this->template();

        $this->upload($template, $this->csv(
            "headline,photo,panel\n".str_repeat("Row,,#000000\n", 3)
        ))->assertStatus(201);

        $this->assertSame(3, Snap::query()->count());
    }

    public function test_an_xlsx_with_more_rows_than_the_limit_is_refused()
    {
        config(['snapsbrew.import_max_rows' => 3]);
        $template = $this->template();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['headline', 'photo', 'panel'],
            ['Row', '', '#000000'],
            ['Row', '', '#000000'],
            ['Row', '', '#000000'],
            ['Row', '', '#000000'],
        ]);

        $response = $this->upload($template, $this->xlsx($spreadsheet));

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('at most 3 rows', $response->json('errors.file.0'));
    }

    public function test_an_xlsx_with_a_large_second_sheet_is_refused()
    {
        config(['snapsbrew.import_max_rows' => 3]);
        $template = $this->template();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['headline', 'photo', 'panel'], ['Row', '', '#000000']]);
        $second = $spreadsheet->createSheet();
        for ($row = 1; $row <= 10; $row++) {
            $second->setCellValue("A{$row}", 'x');
        }

        $this->upload($template, $this->xlsx($spreadsheet))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }
}
