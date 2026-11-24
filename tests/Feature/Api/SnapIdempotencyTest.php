<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessSnap;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * An agent retries on a timeout. A retry must return the same snap and record
 * one render, never two. Every value asserted here is a usage guarantee.
 */
class SnapIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    private function template(User $user): Template
    {
        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
    }

    private function createSnapRequest(string $token, array $payload)
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', $payload);
    }

    public function test_a_repeated_key_returns_the_same_snap_and_records_one_render()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $payload = ['template_id' => $template->uuid, 'idempotency_key' => 'retry-once'];

        $first = $this->createSnapRequest($token, $payload);
        $second = $this->createSnapRequest($token, $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);

        $this->assertSame(1, Snap::query()->count());
        $this->assertSame($first->json('id'), $second->json('id'));

        $first->assertHeaderMissing('X-Idempotent-Replay');
        $second->assertHeader('X-Idempotent-Replay', 'true');

        // The whole point. Two requests, one render.
        $this->assertSame(1, (int) ApiLog::query()->sum('renders'));
    }

    public function test_two_companies_can_use_the_same_key_without_seeing_each_other()
    {
        Bus::fake();
        [$userA, $tokenA] = $this->createApiUser();
        [$userB, $tokenB] = $this->createApiUser();

        $templateA = $this->template($userA);
        $templateB = $this->template($userB);

        $first = $this->createSnapRequest($tokenA, ['template_id' => $templateA->uuid, 'idempotency_key' => 'shared']);

        // The test process keeps one application instance, so the auth guard
        // still holds company A. Production builds a fresh instance per request.
        $this->app['auth']->forgetGuards();

        $second = $this->createSnapRequest($tokenB, ['template_id' => $templateB->uuid, 'idempotency_key' => 'shared']);

        $first->assertStatus(201);

        // A guessed key that belongs to another company must read exactly like an
        // unused key. No replay header, a new snap, and no sight of company A.
        $second->assertStatus(201);
        $second->assertHeaderMissing('X-Idempotent-Replay');
        $this->assertNotSame($first->json('id'), $second->json('id'));

        $this->assertSame(2, Snap::query()->count());
        $this->assertSame(1, Snap::query()->where('company_id', $userA->company_id)->count());
        $this->assertSame(1, Snap::query()->where('company_id', $userB->company_id)->count());
    }

    public function test_a_key_sent_with_a_collection_is_refused()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();

        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $response = $this->createSnapRequest($token, [
            'collection_id' => $collection->uuid,
            'idempotency_key' => 'not-for-collections',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_a_claim_still_in_progress_returns_409_and_records_nothing()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        // Stand in for a first request that holds the claim and has not linked a
        // snap yet. A real race lands in the same state for a few milliseconds.
        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'in-flight',
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        ]);

        $response = $this->createSnapRequest($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'in-flight',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'idempotency_key_in_progress');
        $this->assertSame(0, Snap::query()->count());
        $this->assertSame(0, (int) ApiLog::query()->sum('renders'));
    }

    /**
     * A key that a batch already owns names a batch, not a snap. The snap
     * endpoint must refuse it before it renders, with a code that is not the
     * retryable "in progress" code: a retry with the same key fails again.
     */
    public function test_a_key_that_belongs_to_a_batch_returns_409_with_its_own_code_and_renders_nothing()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $batch = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps/batches', [
                'template_id' => $template->uuid,
                'idempotency_key' => 'batch-key',
                'items' => [['variants' => []]],
            ])->assertStatus(202);

        $snapsBefore = Snap::query()->count();

        $response = $this->createSnapRequest($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'batch-key',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'idempotency_key_belongs_to_batch');
        $this->assertSame($snapsBefore, Snap::query()->count(), 'The refused request must not create a snap.');
        $this->assertSame(0, (int) ApiLog::query()->sum('renders'));
        $this->assertNotNull($batch->json('uuid'));
    }

    public function test_a_failed_claim_can_be_retried_with_the_same_key()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        // A render that threw must not burn the key for the whole window.
        IdempotencyKey::create([
            'company_id' => $user->company_id,
            'key' => 'try-again',
            'status' => IdempotencyKey::STATUS_FAILED,
        ]);

        $response = $this->createSnapRequest($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'try-again',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Snap::query()->count());

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'try-again',
            'status' => IdempotencyKey::STATUS_COMPLETED,
        ]);
    }

    public function test_an_async_replay_returns_202_and_records_nothing_extra()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $payload = [
            'template_id' => $template->uuid,
            'async' => true,
            'webhook_url' => 'https://example.com/hooks/snap',
            'idempotency_key' => 'async-retry',
        ];

        $first = $this->createSnapRequest($token, $payload);
        $second = $this->createSnapRequest($token, $payload);

        $first->assertStatus(202);
        $second->assertStatus(202);
        $second->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame(1, Snap::query()->count());

        // Name the invariant instead of inferring it from the row count. A
        // replay that re-queues would record a second render on completion.
        Bus::assertDispatchedTimes(ProcessSnap::class, 1);

        // 202 records no render. The render is recorded when the job completes.
        $this->assertSame(0, (int) ApiLog::query()->sum('renders'));
    }

    public function test_a_request_without_a_key_behaves_exactly_as_before()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $first = $this->createSnapRequest($token, ['template_id' => $template->uuid]);
        $second = $this->createSnapRequest($token, ['template_id' => $template->uuid]);

        $first->assertStatus(201);
        $second->assertStatus(201);

        $this->assertSame(2, Snap::query()->count());
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_a_retry_after_the_key_expires_renders_again_as_a_new_request()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $payload = ['template_id' => $template->uuid, 'idempotency_key' => 'expires'];

        $this->createSnapRequest($token, $payload)->assertStatus(201);

        // Age the key past the window and run the real prune command.
        IdempotencyKey::query()->update(['created_at' => now()->subHours(25)]);
        $this->artisan('snaps:prune-idempotency-keys');

        $second = $this->createSnapRequest($token, $payload);

        // A retry after expiry is a new request and a new render. That is the
        // documented behavior, not an accident. `ApiSpec.php` states the window.
        $second->assertStatus(201);
        $second->assertHeaderMissing('X-Idempotent-Replay');

        $this->assertSame(2, Snap::query()->count());
        $this->assertSame(2, (int) ApiLog::query()->sum('renders'));
    }

    public function test_a_repeated_key_with_different_parameters_returns_the_first_snap()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $templateA = $this->template($user);
        $templateB = $this->template($user);

        $first = $this->createSnapRequest($token, [
            'template_id' => $templateA->uuid,
            'idempotency_key' => 'same-key-other-template',
        ]);

        $second = $this->createSnapRequest($token, [
            'template_id' => $templateB->uuid,
            'idempotency_key' => 'same-key-other-template',
        ]);

        $first->assertStatus(201);

        // The key wins, not the payload. The council cut the request fingerprint
        // from this slice on purpose, so the rule is "same key, first snap,
        // always". A client that changes the payload under a used key gets the
        // first snap back and no new render. This test pins that decision.
        $second->assertStatus(201);
        $second->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($templateA->uuid, $second->json('template_id'));

        $this->assertSame(1, Snap::query()->count());
        $this->assertSame(1, (int) ApiLog::query()->sum('renders'));
    }

    public function test_an_empty_key_counts_as_no_key_and_is_never_stored()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        // ConvertEmptyStringsToNull (app/Http/Kernel.php:29) turns '' into null
        // before validation, so an empty key reads as "no key" and the request
        // renders normally. The rule that matters is the one below: '' must
        // never reach the table, because every later empty key would collide
        // with it and replay a stranger's snap.
        $response = $this->createSnapRequest($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => '',
        ]);

        $response->assertStatus(201);
        $response->assertHeaderMissing('X-Idempotent-Replay');

        $this->assertSame(1, Snap::query()->count());
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_an_over_long_key_fails_validation_and_not_the_database()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->createSnapRequest($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => str_repeat('a', 192),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Snap::query()->count());
    }

    public function test_a_key_with_unsupported_characters_is_refused()
    {
        Bus::fake();
        [$user, $token] = $this->createApiUser();
        $template = $this->template($user);

        $response = $this->createSnapRequest($token, [
            'template_id' => $template->uuid,
            'idempotency_key' => 'has spaces and/slashes',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Snap::query()->count());
    }
}
