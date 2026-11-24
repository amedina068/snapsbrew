<?php

namespace Tests\Feature;

use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapImportWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_company_gets_its_own_webhook_secret(): void
    {
        $first = Company::factory()->create();
        $second = Company::factory()->create();

        $this->assertStringStartsWith('whsec_', $first->webhook_secret);
        $this->assertNotSame($first->webhook_secret, $second->webhook_secret);
    }

    public function test_the_secret_is_encrypted_in_the_database(): void
    {
        $company = Company::factory()->create();

        $stored = DB::table('companies')->where('id', $company->id)->value('webhook_secret');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString($company->webhook_secret, $stored);
    }

    public function test_a_snap_webhook_signs_the_timestamp_and_the_raw_body(): void
    {
        Http::fake(['https://example.com/snap-hook' => Http::response(null, 204)]);

        $snap = $this->completedSnap('https://example.com/snap-hook');

        (new ProcessSnap($snap))->handle();

        $secret = $snap->company->webhook_secret;

        Http::assertSent(function (Request $request) use ($secret) {
            $timestamp = $request->header('X-Snapsbrew-Timestamp')[0];

            return ctype_digit($timestamp)
                && abs((int) $timestamp - now()->timestamp) < 5
                && $request->header('X-Snapsbrew-Signature')[0] === hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret)
                && $request->hasHeader('X-Snapsbrew-Delivery')
                && $request['event'] === 'snap.completed';
        });
    }

    public function test_an_import_webhook_is_signed_with_the_secret_of_its_company(): void
    {
        Http::fake(['https://example.com/import-hook' => Http::response(null, 200)]);

        $import = $this->import('https://example.com/import-hook');

        SnapImportWebhookDispatcher::dispatch($import, 'snap-import.completed');

        $secret = $import->company->webhook_secret;

        Http::assertSent(fn (Request $request) => $request->header('X-Snapsbrew-Signature')[0]
            === hash_hmac('sha256', $request->header('X-Snapsbrew-Timestamp')[0].'.'.$request->body(), $secret));
    }

    public function test_the_retries_of_one_delivery_share_its_delivery_id(): void
    {
        Http::fake(['https://example.com/snap-hook' => Http::response(null, 500)]);

        (new ProcessSnap($this->completedSnap('https://example.com/snap-hook')))->handle();

        $deliveries = Http::recorded()->map(fn ($pair) => $pair[0]->header('X-Snapsbrew-Delivery')[0]);

        $this->assertCount(3, $deliveries);
        $this->assertCount(1, $deliveries->unique());
        $this->assertTrue(str($deliveries->first())->isUuid());
    }

    public function test_the_developers_tab_shows_the_webhook_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/account?tab=developers')
            ->assertOk()
            ->assertSee('Webhook signing secret')
            ->assertSee($user->company->webhook_secret);
    }

    public function test_a_member_rotates_the_secret_of_the_company(): void
    {
        $user = User::factory()->create();
        $other = Company::factory()->create();
        $old = $user->company->webhook_secret;
        $untouched = $other->webhook_secret;

        $this->actingAs($user)
            ->from('/account?tab=developers')
            ->post('/account/webhook-secret')
            ->assertRedirect('/account?tab=developers')
            ->assertSessionHas('open_tab', 'developers');

        $this->assertNotSame($old, $user->company->fresh()->webhook_secret);
        $this->assertStringStartsWith('whsec_', $user->company->fresh()->webhook_secret);
        $this->assertSame($untouched, $other->fresh()->webhook_secret);
    }

    public function test_a_guest_cannot_rotate_a_secret(): void
    {
        $this->post('/account/webhook-secret')->assertRedirect('/login');
    }

    private function completedSnap(string $webhookUrl): Snap
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id]);

        return Snap::factory()->create([
            'company_id' => $user->company_id,
            'template_id' => $template->id,
            'user_id' => $user->id,
            'is_async' => true,
            'status' => Snap::STATUS_COMPLETED,
            'webhook_url' => $webhookUrl,
        ]);
    }

    private function import(string $webhookUrl): SnapImport
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id]);

        return SnapImport::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'template_id' => $template->id,
            'webhook_url' => $webhookUrl,
        ]);
    }
}
