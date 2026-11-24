<?php

namespace Tests\Unit\Jobs;

use App\Contracts\SnapRenderer;
use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class ProcessSnapTest extends TestCase
{
    use RefreshDatabase;

    private function makeSnapWithImport(string $importStatus = SnapImport::STATUS_PROCESSING): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
        $import = SnapImport::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'status' => $importStatus,
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

        return [$snap, $import];
    }

    public function test_cancels_snap_when_import_already_canceled()
    {
        [$snap, $import] = $this->makeSnapWithImport(SnapImport::STATUS_CANCELED);

        (new ProcessSnap($snap))->handle();

        $this->assertSame(Snap::STATUS_CANCELED, $snap->fresh()->status);
        $this->assertSame(1, $import->fresh()->processed_rows);
    }

    public function test_canceled_snap_does_not_debit_credit()
    {
        [$snap] = $this->makeSnapWithImport(SnapImport::STATUS_CANCELED);

        (new ProcessSnap($snap))->handle();

        $this->assertDatabaseMissing('api_logs', [
            'feature' => 'snap_import',
        ]);
    }

    public function test_failed_method_does_not_debit_credit()
    {
        [$snap] = $this->makeSnapWithImport();

        (new ProcessSnap($snap))->failed(new \RuntimeException('boom'));

        $this->assertSame(Snap::STATUS_FAILED, $snap->fresh()->status);
        $this->assertDatabaseMissing('api_logs', [
            'feature' => 'snap_import',
        ]);
    }

    public function test_cancels_snap_when_cancellation_cache_key_present()
    {
        [$snap, $import] = $this->makeSnapWithImport();

        Cache::put(SnapImport::cancellationCacheKey($import->id), now(), 60);

        (new ProcessSnap($snap))->handle();

        $this->assertSame(Snap::STATUS_CANCELED, $snap->fresh()->status);
    }

    public function test_returns_early_when_snap_template_is_deleted()
    {
        [$snap, $import] = $this->makeSnapWithImport();

        $snap->template->delete();

        // A template delete also deletes its snaps. A job that is already on
        // the queue finds no row and stops without an error.
        (new ProcessSnap($snap))->handle();

        $this->assertNull($snap->fresh());
    }

    public function test_legacy_snap_receives_an_immutable_source_before_rendering()
    {
        [$snap] = $this->makeSnapWithImport();
        $snap->update(['snap_import_id' => null]);
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        (new ProcessSnap($snap))->handle();

        $snap->refresh();
        $this->assertSame(Snap::STATUS_COMPLETED, $snap->status);
        $this->assertNotNull($snap->source_template_version_id);
        $this->assertDatabaseHas('template_versions', [
            'id' => $snap->source_template_version_id,
            'template_id' => $snap->template_id,
        ]);
    }

    public function test_webhook_retries_on_5xx_responses()
    {
        Http::fake([
            'https://example.com/snap-hook' => Http::response(null, 500),
        ]);

        [$snap, $import] = $this->makeSnapWithImport();
        $snap->forceFill([
            'status' => Snap::STATUS_COMPLETED,
            'webhook_url' => 'https://example.com/snap-hook',
        ])->save();

        (new ProcessSnap($snap))->handle();

        Http::assertSentCount(3);
        $this->assertNotNull($snap->fresh()->last_error);
    }

    public function test_webhook_clears_last_error_on_eventual_success()
    {
        $attempt = 0;

        Http::fake(function () use (&$attempt) {
            $attempt++;

            return $attempt < 3
                ? Http::response(null, 500)
                : Http::response(['ok' => true], 200);
        });

        [$snap, $import] = $this->makeSnapWithImport();
        $snap->forceFill([
            'status' => Snap::STATUS_COMPLETED,
            'webhook_url' => 'https://example.com/snap-hook',
            'last_error' => 'previous failure',
        ])->save();

        (new ProcessSnap($snap))->handle();

        $this->assertSame(3, $attempt);
        $this->assertNull($snap->fresh()->last_error);
    }

    /**
     * A public receiver answered with a redirect to an internal address. The
     * client followed it, and `last_error` stored the body of the internal
     * page, which the API returns to the owner of the snap.
     */
    public function test_webhook_does_not_follow_a_redirect()
    {
        Http::fake([
            'https://example.com/snap-hook' => Http::response(null, 302, ['Location' => 'http://10.0.0.5/admin']),
            'http://10.0.0.5/*' => Http::response('INTERNAL-ONLY admin panel', 500),
        ]);

        [$snap] = $this->makeSnapWithImport();
        $snap->forceFill(['status' => Snap::STATUS_COMPLETED, 'webhook_url' => 'https://example.com/snap-hook'])->save();

        (new ProcessSnap($snap))->handle();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '10.0.0.5'));
        $this->assertSame('webhook_redirect_refused', $snap->fresh()->last_error);
    }

    public function test_webhook_failure_stores_a_fixed_code_not_the_response_body()
    {
        Http::fake([
            'https://example.com/snap-hook' => Http::response('SECRET-RECEIVER-BODY', 500),
        ]);

        [$snap] = $this->makeSnapWithImport();
        $snap->forceFill(['status' => Snap::STATUS_COMPLETED, 'webhook_url' => 'https://example.com/snap-hook'])->save();

        (new ProcessSnap($snap))->handle();

        $this->assertSame('webhook_http_500', $snap->fresh()->last_error);
    }

    public function test_webhook_connection_failure_stores_a_fixed_code()
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to 10.0.0.5 port 80');
        });

        [$snap] = $this->makeSnapWithImport();
        $snap->forceFill(['status' => Snap::STATUS_COMPLETED, 'webhook_url' => 'https://example.com/snap-hook'])->save();

        (new ProcessSnap($snap))->handle();

        $this->assertSame('webhook_unreachable', $snap->fresh()->last_error);
    }

    /**
     * The address passed `SafeUrl` when the client sent it. At send time the
     * host resolves to loopback, so the job sends nothing.
     */
    public function test_webhook_to_a_host_that_now_resolves_to_an_internal_address_stores_a_fixed_code()
    {
        Http::fake();

        [$snap] = $this->makeSnapWithImport();
        $snap->forceFill(['status' => Snap::STATUS_COMPLETED, 'webhook_url' => 'http://localhost:6379/'])->save();

        (new ProcessSnap($snap))->handle();

        Http::assertNothingSent();
        $this->assertSame('webhook_address_refused', $snap->fresh()->last_error);
    }
}
