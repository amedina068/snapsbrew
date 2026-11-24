<?php

namespace Tests\Unit\Support;

use App\Mcp\ToolError;
use App\Services\CreativePackageRetention;
use App\Support\CreativePackageContract;
use Illuminate\Support\Arr;
use Tests\TestCase;

class CreativePackageContractTest extends TestCase
{
    public function test_v1_hard_ceilings_are_immutable_constants(): void
    {
        $this->assertSame([
            'max_items' => 10, 'request_bytes' => 262144, 'metadata_bytes' => 16384,
            'metadata_depth' => 6, 'active_per_company' => 3, 'lease_seconds' => 300,
            'heartbeat_seconds' => 30,
            'attempt_timeout_seconds' => 120, 'max_attempts' => 3,
            'attempt_backoff_seconds' => [5, 15, 45], 'url_ttl_seconds' => 600,
            'replay_days' => 90, 'diagnostic_bytes' => 4096, 'diagnostic_days' => 30,
            'retention_limit' => 1000, 'retention_page' => 100,
        ], $this->ceilings());
    }

    public function test_deploy_defaults_match_the_v1_ceilings(): void
    {
        // The backoff list and the diagnostic width are fixed ceilings with no config key.
        $this->assertSame(Arr::except($this->ceilings(), ['attempt_backoff_seconds', 'diagnostic_bytes']), config('creative-packages.limits'));
        $this->assertArrayNotHasKey('version', config('creative-packages'));
    }

    public function test_a_limit_clamps_to_its_own_ceiling_or_to_a_named_higher_one(): void
    {
        config(['creative-packages.limits.url_ttl_seconds' => 1200, 'creative-packages.limits.max_items' => 20]);

        $this->assertSame(10, CreativePackageContract::limit('max_items', CreativePackageContract::MAX_ITEMS));
        $this->assertSame(600, CreativePackageContract::limit('url_ttl_seconds', CreativePackageContract::URL_TTL_SECONDS));
        $this->assertSame(900, CreativePackageContract::limit('url_ttl_seconds', CreativePackageContract::URL_TTL_SECONDS, CreativePackageContract::URL_MAX_TTL_SECONDS));
        $this->assertSame(900, CreativePackageContract::URL_MAX_TTL_SECONDS);
    }

    public function test_request_response_and_state_vocabularies_are_frozen(): void
    {
        $this->assertSame(1, CreativePackageContract::VERSION);
        $this->assertSame(100, CreativePackageContract::MAX_KEY_LENGTH);
        $this->assertSame(['building', 'ready', 'partial', 'failed'], CreativePackageContract::PACKAGE_STATUSES);
        $this->assertSame(['pending', 'rendering', 'ready', 'failed'], CreativePackageContract::ITEM_STATUSES);
        $this->assertSame(['png', 'jpeg', 'webp'], CreativePackageContract::FORMATS);
        $this->assertSame('snapsbrew_internal_', CreativePackageContract::INTERNAL_KEY_PREFIX);
        $this->assertSame(
            ['publisher', 'postiz', 'post_bridge', 'schedule', 'social_account', 'external_post_id'],
            CreativePackageContract::RESERVED_METADATA_FIELDS
        );
        $this->assertSame(['idempotency_key', 'items'], CreativePackageContract::REQUEST_FIELDS);
        $this->assertSame([
            'client_creative_id', 'client_family_id', 'template_id', 'template_version',
            'label', 'placement_key', 'caption', 'alt_text', 'metadata', 'format',
        ], CreativePackageContract::ITEM_FIELDS);
        $this->assertSame([
            'package_id', 'status', 'replayed', 'counts',
            'poll_after_seconds', 'updated_at', 'next_action',
            'url_expires_at', 'items', 'manifest',
        ], CreativePackageContract::RESPONSE_FIELDS);
    }

    public function test_tool_error_is_the_only_package_error_registry(): void
    {
        $this->assertSame([
            'creative_package_invalid_request' => ['retryable' => false, 'details' => ['fields']],
            'creative_package_source_not_found' => ['retryable' => false, 'details' => ['item_index', 'client_creative_id']],
            'creative_package_source_invalid' => ['retryable' => false, 'details' => ['issues']],
            'creative_package_idempotency_conflict' => ['retryable' => false, 'details' => ['idempotency_key', 'package_id']],
            'creative_package_rate_limited' => ['retryable' => true, 'details' => ['poll_after_seconds']],
            'creative_package_entitlement_required' => ['retryable' => false, 'details' => []],
            'creative_package_not_found' => ['retryable' => false, 'details' => ['package_id']],
        ], array_map(fn (array $entry) => array_intersect_key($entry, array_flip(['retryable', 'details'])), ToolError::CREATIVE_PACKAGE_ERRORS));
        // The fix is prose the builder adds. Every code carries one.
        $this->assertSame([], array_keys(array_filter(ToolError::CREATIVE_PACKAGE_ERRORS, fn (array $entry) => ($entry['fix'] ?? '') === '')));
    }

    public function test_canonical_fixture_covers_the_three_ratio_family(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/creative-package-v1.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('launch-fall-2026', $fixture['idempotency_key']);
        $this->assertSame(['1:1', '4:5', '9:16'], array_column(array_column($fixture['items'], 'metadata'), 'ratio'));
        $this->assertSame(['png', 'jpeg', 'webp'], array_column($fixture['items'], 'format'));
        $this->assertCount(3, array_unique(array_column($fixture['items'], 'template_id')));
    }

    /** @return array<string, int|array<int, int>> */
    private function ceilings(): array
    {
        return [
            'max_items' => CreativePackageContract::MAX_ITEMS,
            'request_bytes' => CreativePackageContract::MAX_REQUEST_BYTES,
            'metadata_bytes' => CreativePackageContract::MAX_METADATA_BYTES,
            'metadata_depth' => CreativePackageContract::MAX_METADATA_DEPTH,
            'active_per_company' => CreativePackageContract::MAX_ACTIVE_PACKAGES,
            'lease_seconds' => CreativePackageContract::LEASE_SECONDS,
            'heartbeat_seconds' => CreativePackageContract::HEARTBEAT_SECONDS,
            'attempt_timeout_seconds' => CreativePackageContract::ATTEMPT_TIMEOUT_SECONDS,
            'max_attempts' => CreativePackageContract::MAX_ATTEMPTS,
            'attempt_backoff_seconds' => CreativePackageContract::ATTEMPT_BACKOFF_SECONDS,
            'url_ttl_seconds' => CreativePackageContract::URL_TTL_SECONDS,
            'replay_days' => CreativePackageContract::REPLAY_DAYS,
            'diagnostic_bytes' => CreativePackageContract::DIAGNOSTIC_BYTES,
            'diagnostic_days' => CreativePackageContract::DIAGNOSTIC_DAYS,
            'retention_limit' => CreativePackageRetention::LIMIT,
            'retention_page' => CreativePackageRetention::PAGE,
        ];
    }
}
