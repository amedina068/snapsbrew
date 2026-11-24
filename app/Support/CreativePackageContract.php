<?php

namespace App\Support;

final class CreativePackageContract
{
    public const VERSION = 1;

    public const INTERNAL_KEY_PREFIX = 'snapsbrew_internal_';

    public const MAX_KEY_LENGTH = 100;

    public const MAX_ITEMS = 10;

    public const MAX_REQUEST_BYTES = 256 * 1024;

    public const MAX_METADATA_BYTES = 16 * 1024;

    public const MAX_METADATA_DEPTH = 6;

    public const MAX_ACTIVE_PACKAGES = 3;

    public const LEASE_SECONDS = 5 * 60;

    public const HEARTBEAT_SECONDS = 30;

    public const ATTEMPT_TIMEOUT_SECONDS = 120;

    public const MAX_ATTEMPTS = 3;

    public const ATTEMPT_BACKOFF_SECONDS = [5, 15, 45];

    public const URL_TTL_SECONDS = 10 * 60;

    public const URL_MAX_TTL_SECONDS = 15 * 60;

    public const REPLAY_DAYS = 90;

    public const DIAGNOSTIC_BYTES = 4 * 1024;

    public const DIAGNOSTIC_DAYS = 30;

    /** The width of `diagnostic_exception_class`. The executor cuts a longer class name to it. */
    public const DIAGNOSTIC_CLASS_BYTES = 255;

    /**
     * The only context keys a log line on the package path carries. A key
     * names an identifier, a state or a measure, never content. An exception
     * class is a name the code chose, so it is a safe field. The last two
     * lines are the counts the reconcile and prune commands report. `items` is
     * the item count of a claimed package, a size and never a content.
     */
    public const LOG_FIELDS = [
        'package_id', 'company_id', 'item_id', 'correlation_id', 'status', 'attempts',
        'duration_ms', 'replayed', 'error_code', 'exception_class', 'items',
        'scanned', 'deleted', 'cleared', 'released',
        'pending_redispatched', 'rendering_redispatched', 'failed_redispatched', 'exhausted', 'waiting', 'packages_renewed', 'packages_recounted',
    ];

    public const PACKAGE_STATUS_BUILDING = 'building';

    public const PACKAGE_STATUS_READY = 'ready';

    public const PACKAGE_STATUS_PARTIAL = 'partial';

    public const PACKAGE_STATUS_FAILED = 'failed';

    public const PACKAGE_STATUSES = [
        self::PACKAGE_STATUS_BUILDING,
        self::PACKAGE_STATUS_READY,
        self::PACKAGE_STATUS_PARTIAL,
        self::PACKAGE_STATUS_FAILED,
    ];

    public const ITEM_STATUSES = ['pending', 'rendering', 'ready', 'failed'];

    public const FORMATS = ['png', 'jpeg', 'webp'];

    public const RESERVED_METADATA_FIELDS = ['publisher', 'postiz', 'post_bridge', 'schedule', 'social_account', 'external_post_id'];

    /** A version 1 to 5 UUID, any case. The readers lowercase after the match. */
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    public const REQUEST_FIELDS = ['idempotency_key', 'items'];

    public const ITEM_FIELDS = [
        'client_creative_id', 'client_family_id', 'template_id', 'template_version',
        'label', 'placement_key', 'caption', 'alt_text', 'metadata', 'format',
    ];

    /** The one action a client takes next, keyed by package status. */
    public const NEXT_ACTIONS = [
        self::PACKAGE_STATUS_BUILDING => 'poll',
        self::PACKAGE_STATUS_READY => 'download',
        self::PACKAGE_STATUS_PARTIAL => 'retry_incomplete',
        self::PACKAGE_STATUS_FAILED => 'inspect_failures',
    ];

    /** Checks the claim proves before any item renders. */
    public const VALIDATION_CLAIM_CHECKS = ['request_shape', 'source_version', 'canvas_format', 'editor_assets', 'alt_text_presence'];

    /** Checks the executor proves on the rendered output. */
    public const VALIDATION_OUTPUT_CHECKS = ['render_output', 'stored_facts'];

    /** Quality checks no surface evaluates in this contract version. */
    public const VALIDATION_NOT_EVALUATED = ['alt_text_quality', 'contrast', 'ocr', 'safe_zones', 'brand_consistency', 'composition', 'platform_compliance'];

    public const RESPONSE_FIELDS = [
        'package_id', 'status', 'replayed', 'counts',
        'poll_after_seconds', 'updated_at', 'next_action',
        'url_expires_at', 'items', 'manifest',
    ];

    /** True for the two statuses that carry a manifest and signed URLs. */
    public static function hasOutput(string $status): bool
    {
        return in_array($status, [self::PACKAGE_STATUS_READY, self::PACKAGE_STATUS_PARTIAL], true);
    }

    /**
     * Honor a configured value below the ceiling. Clamp a higher one. The
     * ceiling is the default unless a caller names a higher one, as the URL
     * TTL does: 600 seconds by default, never above 900.
     */
    public static function limit(string $name, int $default, ?int $ceiling = null): int
    {
        $ceiling ??= $default;

        return min(max(1, (int) config("creative-packages.limits.{$name}", $default)), $ceiling);
    }
}
