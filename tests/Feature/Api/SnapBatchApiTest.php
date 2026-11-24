<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapBatchService;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeRenderer;
use Tests\Support\SelectivelyFailingRenderer;
use Tests\TestCase;

class SnapBatchApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        return [$user, $token];
    }

    private function template(User $user): Template
    {
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'layers' => json_encode([[
                'key' => 'headline',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'X']],
            ]]),
        ]);
    }

    /** @param array<int, string> $headlines */
    private function items(array $headlines): array
    {
        return collect($headlines)->map(fn ($headline, $index) => [
            'identifier' => 'row-'.($index + 1),
            'variants' => [['layer' => 'headline', 'text' => $headline]],
        ])->all();
    }

    /**
     * Start a request as the owner of this token.
     *
     * `forgetGuards()` is a test-process artifact, not a production concern. One
     * PHP process serves every request here, and the `sanctum` guard caches the
     * user it resolved first. Without this, a second request with a different
     * token still reads the first user, and a company-scoped rule rejects a
     * template its real owner sent. A real client opens independent requests.
     */
    private function asApi(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function createBatch(string $token, array $payload)
    {
        return $this->asApi($token)->postJson('/api/v1/snaps/batches', $payload);
    }

    private function readBatch(string $token, string $uuid)
    {
        return $this->asApi($token)->getJson("/api/v1/snaps/batches/{$uuid}");
    }

    private function work(): void
    {
        // The worker runs inside the PHPUnit process, which sits near the
        // default 128 MB limit late in a full run. Without --memory, the
        // worker stops after one job and the later items stay pending.
        $this->artisan('queue:work', [
            '--queue' => Queues::IMPORTS,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--memory' => 512,
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_an_unauthenticated_request_is_rejected()
    {
        $this->postJson('/api/v1/snaps/batches', [])->assertStatus(401);
    }

    public function test_a_token_without_the_api_requests_ability_is_rejected()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['other_ability'])->plainTextToken;

        $this->createBatch($token, [])->assertStatus(403);
    }

    // ---------------------------------------------------------------- create

    public function test_a_batch_is_accepted_with_202_and_records_no_render_at_accept()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->createBatch($token, [
            'template_id' => $template->uuid,
            'title' => 'October launch',
            'items' => $this->items(['One', 'Two', 'Three']),
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', SnapImport::STATUS_PROCESSING)
            ->assertJsonPath('total_rows', 3)
            ->assertJsonPath('pending', 3)
            ->assertJsonMissingPath('credits_charged');

        $this->assertSame(3, Snap::count());
        $this->assertSame(
            0,
            (int) ApiLog::sum('renders'),
            'HTTP 202 records no render. Items are recorded on completion.'
        );
    }

    public function test_the_item_identifier_defaults_when_the_caller_sends_none()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => [
                ['variants' => [['layer' => 'headline', 'text' => 'One']]],
                ['variants' => [['layer' => 'headline', 'text' => 'Two']]],
            ],
        ])->assertStatus(202);

        $this->assertSame(
            ['item_1', 'item_2'],
            Snap::orderBy('id')->pluck('identifier')->all()
        );
    }

    public function test_a_batch_row_carries_no_per_item_webhook()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'webhook_url' => 'https://example.com/hooks/batch',
            'items' => $this->items(['One', 'Two']),
        ])->assertStatus(202);

        $this->assertSame(
            'https://example.com/hooks/batch',
            SnapImport::first()->webhook_url
        );
        $this->assertSame(
            [null, null],
            Snap::orderBy('id')->pluck('webhook_url')->all(),
            'This surface sends one webhook when the batch ends, not one per item.'
        );
    }

    public function test_the_batch_posts_exactly_one_webhook_when_it_finishes()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'webhook_url' => 'https://example.com/hooks/batch',
            'items' => $this->items(['One', 'Two', 'Three']),
        ])->assertStatus(202);

        $this->work();

        $this->assertSame(
            1,
            Http::recorded(fn ($request) => $request->url() === 'https://example.com/hooks/batch')->count(),
            'Three items must produce one webhook, not four.'
        );
        Http::assertSent(fn ($request) => $request['event'] === 'snap-import.completed');
    }

    public function test_items_record_one_render_each_on_completion()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', SelectivelyFailingRenderer::MARKER, 'Three']),
        ])->assertStatus(202);

        $this->work();

        $import = SnapImport::first();

        $this->assertSame(
            2,
            (int) ApiLog::where('url', 'snap-import:'.$import->uuid)->sum('renders'),
            'Two items rendered, so the batch records two renders.'
        );
        $this->assertSame(SnapImport::STATUS_COMPLETED, $import->fresh()->status);
    }

    // ------------------------------------------------------------ validation

    public function test_a_batch_over_the_cap_is_rejected_and_creates_nothing()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => $this->items(array_fill(0, SnapBatchService::MAX_ITEMS + 1, 'Row')),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('100 items', $response->json('errors.items.0'));
        $this->assertSame(0, Snap::count(), 'The cap must stop the write before any row exists.');
        $this->assertSame(0, SnapImport::count());
    }

    public function test_a_batch_at_the_cap_is_accepted()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => $this->items(array_fill(0, SnapBatchService::MAX_ITEMS, 'Row')),
        ])->assertStatus(202);

        $this->assertSame(SnapBatchService::MAX_ITEMS, Snap::count());
    }

    public function test_an_empty_item_list_is_rejected()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, ['template_id' => $template->uuid, 'items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_an_internal_webhook_url_is_refused()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->createBatch($token, [
            'template_id' => $template->uuid,
            'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
            'items' => $this->items(['One']),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('webhook_url');
        $this->assertSame(0, Snap::count());
    }

    public function test_a_template_from_another_company_is_refused()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherTemplate = $this->template($otherUser);

        $this->createBatch($token, [
            'template_id' => $otherTemplate->uuid,
            'items' => $this->items(['One']),
        ])->assertStatus(422)->assertJsonValidationErrors('template_id');
    }

    public function test_transparent_jpeg_is_refused()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'format' => 'jpeg',
            'transparent' => true,
            'items' => $this->items(['One']),
        ])->assertStatus(422)->assertJsonValidationErrors('transparent');
    }

    // ----------------------------------------------------------- idempotency

    public function test_a_repeated_key_returns_the_first_batch_and_creates_no_second_one()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $payload = [
            'template_id' => $template->uuid,
            'idempotency_key' => 'launch-oct-01',
            'items' => $this->items(['One', 'Two']),
        ];

        $first = $this->createBatch($token, $payload)->assertStatus(202);
        $second = $this->createBatch($token, $payload)->assertStatus(202);

        $this->assertSame($first->json('uuid'), $second->json('uuid'));
        $this->assertSame(1, SnapImport::count(), 'A retry must not start a second batch.');
        $this->assertSame(2, Snap::count(), 'A retry must not create a second set of items.');

        $first->assertHeaderMissing('X-Idempotent-Replay');
        $second->assertHeader('X-Idempotent-Replay', 'true');
    }

    public function test_a_key_already_used_by_a_single_snap_is_refused()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'shared-key',
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'snap_id' => Snap::factory()->create([
                'template_id' => $template->id,
                'company_id' => $user->company_id,
                'user_id' => $user->id,
            ])->id,
        ]);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'shared-key',
            'items' => $this->items(['One']),
        ])->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_belongs_to_snap');
    }

    public function test_a_batch_claim_still_in_progress_returns_409_with_the_in_progress_code()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'in-flight',
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        ]);

        $this->createBatch($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'in-flight',
            'items' => $this->items(['One']),
        ])->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_in_progress');

        $this->assertSame(0, SnapImport::count());
    }

    public function test_one_company_cannot_replay_another_companys_key()
    {
        Bus::fake();
        [$userA, $tokenA] = $this->createApiUser();
        [$userB, $tokenB] = $this->createApiUser();

        $templateA = $this->template($userA);
        $templateB = $this->template($userB);

        $this->createBatch($tokenA, [
            'template_id' => $templateA->uuid,
            'idempotency_key' => 'same-key',
            'items' => $this->items(['A']),
        ])->assertStatus(202);

        $responseB = $this->createBatch($tokenB, [
            'template_id' => $templateB->uuid,
            'idempotency_key' => 'same-key',
            'items' => $this->items(['B']),
        ])->assertStatus(202);

        $this->assertSame(2, SnapImport::count());
        $responseB->assertHeaderMissing('X-Idempotent-Replay');
    }

    // ------------------------------------------------------------------ read

    public function test_the_batch_read_returns_the_item_list()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $uuid = $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', SelectivelyFailingRenderer::MARKER]),
        ])->json('uuid');

        $this->work();

        $response = $this->readBatch($token, $uuid);

        $response->assertStatus(200)
            ->assertJsonPath('status', SnapImport::STATUS_COMPLETED)
            ->assertJsonPath('completed', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonMissingPath('credits_charged')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.identifier', 'row-1')
            ->assertJsonPath('items.0.status', Snap::STATUS_COMPLETED)
            ->assertJsonPath('items.1.identifier', 'row-2')
            ->assertJsonPath('items.1.status', Snap::STATUS_FAILED);

        $this->assertNotNull($response->json('items.0.media_url'));
        $this->assertNull($response->json('items.1.media_url'));
    }

    public function test_the_terminal_counts_add_up_to_the_total()
    {
        $this->app->bind(SnapRenderer::class, SelectivelyFailingRenderer::class);
        config(['queue.default' => 'database']);
        Mail::fake();
        Http::fake();

        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $uuid = $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => $this->items(['One', SelectivelyFailingRenderer::MARKER, 'Three']),
        ])->json('uuid');

        $this->work();

        $body = $this->readBatch($token, $uuid)->json();

        $this->assertContains($body['status'], [
            SnapImport::STATUS_COMPLETED,
            SnapImport::STATUS_FAILED,
            SnapImport::STATUS_CANCELED,
        ]);
        $this->assertSame(
            $body['total_rows'],
            $body['completed'] + $body['failed'] + $body['canceled'],
            'At a terminal status every item holds a terminal state.'
        );
        $this->assertSame(0, $body['pending']);
    }

    public function test_one_company_cannot_read_another_companys_batch()
    {
        Bus::fake();
        [$userA, $tokenA] = $this->createApiUser();
        [$userB, $tokenB] = $this->createApiUser();

        $uuid = $this->createBatch($tokenA, [
            'template_id' => $this->template($userA)->uuid,
            'items' => $this->items(['One']),
        ])->json('uuid');

        $this->readBatch($tokenB, $uuid)->assertStatus(404);
    }

    public function test_an_unknown_batch_id_returns_404()
    {
        [$user, $token] = $this->createApiUser();

        $this->readBatch($token, (string) Str::uuid())->assertStatus(404);
    }

    public function test_the_item_list_does_not_scale_its_query_count_with_the_item_count()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $uuid = $this->createBatch($token, [
            'template_id' => $template->uuid,
            'items' => $this->items(array_fill(0, 50, 'Row')),
        ])->json('uuid');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->readBatch($token, $uuid)
            ->assertStatus(200)
            ->assertJsonCount(50, 'items');

        $this->assertLessThan(
            25,
            $queries,
            "A 50-item read ran {$queries} queries. The media relation must be eager loaded."
        );
    }
}
