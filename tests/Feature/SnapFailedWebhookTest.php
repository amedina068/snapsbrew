<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Jobs\ProcessSnap;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakeRenderer;
use Tests\TestCase;
use Throwable;

class SnapFailedWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_async_render_posts_a_signed_snap_failed_event(): void
    {
        Http::fake(['https://example.com/snap-hook' => Http::response(null, 204)]);
        $this->app->instance(SnapRenderer::class, new class extends FakeRenderer
        {
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                throw new RuntimeException('Chrome crashed at /internal/path');
            }
        });

        $snap = $this->asyncSnap('https://example.com/snap-hook');

        try {
            dispatch(new ProcessSnap($snap));
        } catch (Throwable) {
            // The sync queue rethrows after it calls ProcessSnap::failed().
        }

        $secret = $snap->company->webhook_secret;

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['event'] === 'snap.failed'
            && $request['snap']['id'] === $snap->uuid
            && $request['snap']['status'] === Snap::STATUS_FAILED
            && $request['snap']['last_error'] === Snap::ERROR_RENDER_FAILED
            && ! str_contains($request->body(), 'Chrome crashed')
            && $request->header('X-Snapsbrew-Signature')[0] === hash_hmac('sha256', $request->header('X-Snapsbrew-Timestamp')[0].'.'.$request->body(), $secret));
    }

    public function test_a_failed_delivery_keeps_the_render_error(): void
    {
        Http::fake(['https://example.com/snap-hook' => Http::response(null, 500)]);

        $snap = $this->asyncSnap('https://example.com/snap-hook');

        (new ProcessSnap($snap))->failed(new RuntimeException('timed out'));

        Http::assertSentCount(3);
        $this->assertSame(Snap::ERROR_RENDER_FAILED, $snap->fresh()->last_error);
    }

    public function test_a_snap_without_a_webhook_url_posts_nothing(): void
    {
        Http::fake();

        (new ProcessSnap($this->asyncSnap(null)))->failed(new RuntimeException('timed out'));

        Http::assertNothingSent();
    }

    public function test_a_sync_snap_posts_nothing(): void
    {
        Http::fake();

        $snap = $this->asyncSnap('https://example.com/snap-hook');
        $snap->forceFill(['is_async' => false])->save();

        (new ProcessSnap($snap))->failed(new RuntimeException('timed out'));

        Http::assertNothingSent();
    }

    private function asyncSnap(?string $webhookUrl): Snap
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id]);

        return Snap::factory()->create([
            'company_id' => $user->company_id,
            'template_id' => $template->id,
            'user_id' => $user->id,
            'is_async' => true,
            'status' => Snap::STATUS_PENDING,
            'webhook_url' => $webhookUrl,
        ]);
    }
}
