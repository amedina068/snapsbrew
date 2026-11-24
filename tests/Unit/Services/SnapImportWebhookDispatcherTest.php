<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapImportWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SnapImportWebhookDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(?string $webhookUrl): SnapImport
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
            'webhook_url' => $webhookUrl,
        ]);
    }

    public function test_no_webhook_url_skips_dispatch()
    {
        Http::fake();

        $import = $this->makeImport(null);

        SnapImportWebhookDispatcher::dispatch($import, 'snap-import.completed');

        Http::assertNothingSent();
    }

    public function test_posts_webhook_when_configured()
    {
        Http::fake([
            'https://example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $import = $this->makeImport('https://example.com/webhook');

        SnapImportWebhookDispatcher::dispatch($import, 'snap-import.completed');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/webhook'
                && $request['event'] === 'snap-import.completed'
                && isset($request['import']['uuid']);
        });
    }

    public function test_retries_on_failure_up_to_max_attempts()
    {
        Http::fake([
            'https://example.com/webhook' => Http::response(null, 500),
        ]);

        $import = $this->makeImport('https://example.com/webhook');

        SnapImportWebhookDispatcher::dispatch($import, 'snap-import.failed');

        Http::assertSentCount(SnapImportWebhookDispatcher::MAX_ATTEMPTS);
    }

    public function test_stops_retrying_once_a_call_succeeds()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts < 2
                ? Http::response(null, 500)
                : Http::response(['ok' => true], 200);
        });

        $import = $this->makeImport('https://example.com/webhook');

        SnapImportWebhookDispatcher::dispatch($import, 'snap-import.completed');

        $this->assertSame(2, $attempts);
        Http::assertSentCount(2);
    }

    public function test_does_not_follow_a_redirect()
    {
        Http::fake([
            'https://example.com/webhook' => Http::response(null, 307, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            'http://169.254.169.254/*' => Http::response('metadata', 200),
        ]);

        $import = $this->makeImport('https://example.com/webhook');

        SnapImportWebhookDispatcher::dispatch($import, 'snap-import.completed');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_the_failure_log_holds_a_fixed_code_not_the_response_body()
    {
        Http::fake([
            'https://example.com/webhook' => Http::response('SECRET-RECEIVER-BODY', 500),
        ]);
        Log::spy();

        SnapImportWebhookDispatcher::dispatch($this->makeImport('https://example.com/webhook'), 'snap-import.failed');

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $context['error'] === 'webhook_http_500'
                && ! str_contains(json_encode($context), 'SECRET-RECEIVER-BODY');
        });
    }
}
