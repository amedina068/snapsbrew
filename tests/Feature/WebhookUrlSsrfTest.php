<?php

namespace Tests\Feature;

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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `webhook_url` is a caller-supplied address that the server POSTs to, from the
 * render host, in `ProcessSnap::sendWebhook()` and
 * `SnapImportWebhookDispatcher::dispatch()`.
 *
 * Without a host check a caller can aim it at cloud metadata or an internal
 * service. `App\Rules\SafeUrl` already guards the other caller-supplied URL in
 * this product, `image_url`. These tests hold both surfaces to the same rule.
 *
 * Every address below is a literal IP, so `SafeUrl::resolveIps()` returns it
 * without a DNS lookup and the suite makes no network call.
 */
class WebhookUrlSsrfTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<int, string>> */
    public static function blockedUrls(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:6379/'],
            'private class A' => ['http://10.0.0.5/hook'],
            'private class C' => ['http://192.168.1.1/hook'],
            'non-http scheme' => ['file:///etc/passwd'],
        ];
    }

    private function apiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$user, $user->createToken('test', ['api_requests'])->plainTextToken];
    }

    private function webUser(): array
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
                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'textContent', 'value' => 'x']]],
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

    #[DataProvider('blockedUrls')]
    public function test_the_async_snap_api_refuses_an_internal_webhook_url(string $url)
    {
        Bus::fake();
        [$user, $token] = $this->apiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'async' => true,
                'webhook_url' => $url,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_the_async_snap_api_still_accepts_a_public_webhook_url()
    {
        Bus::fake();
        [$user, $token] = $this->apiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', [
                'template_id' => $template->uuid,
                'async' => true,
                'webhook_url' => 'https://example.com/hooks/snap',
            ])
            ->assertStatus(202);

        $this->assertSame(1, Snap::query()->count());
    }

    #[DataProvider('blockedUrls')]
    public function test_the_bulk_import_refuses_an_internal_webhook_url(string $url)
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->webUser();
        $template = $this->templateWithLayers($company->id, $user->id);
        $file = $this->makeXlsx(['headline'], [['One']]);

        $response = $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Internal webhook',
            'webhook_url' => $url,
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('webhook_url');
        $this->assertSame(0, SnapImport::query()->count());
    }

    public function test_the_bulk_import_still_accepts_a_public_webhook_url()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->webUser();
        $template = $this->templateWithLayers($company->id, $user->id);
        $file = $this->makeXlsx(['headline'], [['One']]);

        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'Public webhook',
            'webhook_url' => 'https://example.com/hooks/import',
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame(1, SnapImport::query()->count());
    }

    public function test_the_bulk_import_still_accepts_no_webhook_url()
    {
        Bus::fake();
        Storage::fake('local');

        [$company, $user] = $this->webUser();
        $template = $this->templateWithLayers($company->id, $user->id);
        $file = $this->makeXlsx(['headline'], [['One']]);

        // SafeUrl composes with `nullable`. An absent webhook must still work.
        $this->post("/templates/{$template->uuid}/imports", [
            'title' => 'No webhook',
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame(1, SnapImport::query()->count());
    }
}
