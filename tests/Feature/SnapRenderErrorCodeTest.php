<?php

namespace Tests\Feature;

use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A renderer exception can name internal hosts, paths and URLs. The customer
 * reads `last_error` through the API, MCP and the import page, so the row holds
 * a fixed code and only the log holds the raw message.
 */
class SnapRenderErrorCodeTest extends TestCase
{
    use RefreshDatabase;

    private const RAW = 'net::ERR_CONNECTION_REFUSED at http://browserless.internal:3000/var/www/storage';

    /**
     * @return array{0: Snap, 1: SnapImport, 2: User, 3: Template}
     */
    private function failedImportSnap(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
        $import = SnapImport::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'status' => SnapImport::STATUS_PROCESSING,
            'total_rows' => 1,
            'processed_rows' => 0,
        ]);
        $snap = Snap::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'snap_import_id' => $import->id,
            'user_id' => $user->id,
            'is_async' => true,
            'status' => Snap::STATUS_PENDING,
        ]);

        (new ProcessSnap($snap))->failed(new \RuntimeException(self::RAW));

        return [$snap->fresh(), $import, $user, $template];
    }

    public function test_a_failed_render_stores_a_fixed_code_and_logs_the_raw_message()
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        [$snap] = $this->failedImportSnap();

        $this->assertSame(Snap::STATUS_FAILED, $snap->status);
        $this->assertSame('render_failed', $snap->last_error);
        $this->assertTrue(
            collect($logged)->contains(fn ($line) => str_contains($line, 'ERR_CONNECTION_REFUSED') && str_contains($line, (string) $snap->uuid)),
            'The log must keep the raw message with the snap uuid.'
        );
    }

    public function test_the_batch_api_shows_the_code_and_not_the_raw_message()
    {
        [$snap, $import, $user] = $this->failedImportSnap();
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/snaps/batches/{$import->uuid}");

        $response->assertOk();
        $this->assertStringNotContainsString('browserless.internal', $response->getContent());
        $this->assertSame('render_failed', collect($response->json('items'))->firstWhere('id', $snap->uuid)['last_error']);
    }

    public function test_the_import_page_shows_the_code_and_not_the_raw_message()
    {
        [, $import, $user, $template] = $this->failedImportSnap();

        $response = $this->actingAs($user)->get("/templates/{$template->uuid}/imports/{$import->uuid}");

        $response->assertOk();
        $response->assertDontSee('browserless.internal');
        $response->assertSee('render_failed');
    }
}
