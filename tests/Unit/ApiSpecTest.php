<?php

namespace Tests\Unit;

use App\Support\ApiDocs\ApiSpec;
use App\Support\ApiDocs\OpenApiGenerator;
use Tests\TestCase;

class ApiSpecTest extends TestCase
{
    public function test_preview_group_documents_two_endpoints()
    {
        $group = ApiSpec::group('preview');

        $this->assertNotNull($group, 'The preview group should be registered.');
        $this->assertCount(2, $group['endpoints']);

        $ids = array_column($group['endpoints'], 'id');
        $this->assertContains('preview.template', $ids);
        $this->assertContains('preview.snap', $ids);
    }

    public function test_preview_endpoints_need_the_api_requests_ability()
    {
        foreach (ApiSpec::group('preview')['endpoints'] as $endpoint) {
            $this->assertSame(['api_requests'], $endpoint['abilities']);
        }
    }

    public function test_no_endpoint_carries_a_billing_key_or_a_subscription_middleware()
    {
        foreach (ApiSpec::endpoints() as $endpoint) {
            $this->assertArrayNotHasKey('billable', $endpoint, $endpoint['id'].' still has a billable key.');
            $this->assertNotContains('hasActiveSubscription', $endpoint['middleware'] ?? [], $endpoint['id']);
        }
    }

    public function test_the_batch_group_documents_every_endpoint()
    {
        $group = ApiSpec::group('snap-batches');

        $this->assertNotNull($group, 'The snap-batches group must be registered.');

        $ids = array_column($group['endpoints'], 'id');
        $this->assertContains('snap-batches.store', $ids);
        $this->assertContains('snap-batches.show', $ids);
        $this->assertContains('snap-batches.cancel', $ids);
    }

    /**
     * The status vocabulary is the contract a polling client is written against.
     * `completed` does not mean every item rendered, so the docs must say what it
     * does mean and give the invariant that lets a machine check the counts.
     */
    public function test_the_batch_docs_state_the_terminal_status_rule()
    {
        $notes = collect(ApiSpec::group('snap-batches')['endpoints'])
            ->flatMap(fn ($endpoint) => $endpoint['notes'] ?? [])
            ->implode(' ');

        foreach (['completed', 'failed', 'canceled', 'total_rows'] as $term) {
            $this->assertStringContainsString($term, $notes);
        }

        $this->assertStringContainsString(
            'at least one item rendered',
            $notes,
            'The docs must say that `completed` allows a nonzero failed count.'
        );
    }

    public function test_openapi_still_generates_with_every_batch_path()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        $this->assertArrayHasKey('/v1/snaps/batches', $openapi['paths']);
        $this->assertArrayHasKey('/v1/snaps/batches/{batchUuid}', $openapi['paths']);
        $this->assertArrayHasKey('/v1/snaps/batches/{batchUuid}/cancel', $openapi['paths']);
    }

    public function test_openapi_still_generates_with_preview_paths()
    {
        $openapi = (new OpenApiGenerator)->toArray();

        $this->assertArrayHasKey('/v1/snaps/preview', $openapi['paths']);
        $this->assertArrayHasKey('/v1/templates/{templateUuid}/preview', $openapi['paths']);
    }
}
