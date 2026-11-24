<?php

namespace App\Support;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use Carbon\CarbonInterface;

/**
 * Serializes one package to the canonical response that export, replay and
 * get all return. It reads the stored rows only. It never renders or signs a
 * URL. `url_expires_at` and the manifest's per-item `file_url` and
 * `preview_url` stay null unless the caller passes the signer's output as
 * `$urls`, and they stay null on a package without output. The top-level
 * items never carry URLs.
 */
final class CreativePackageSerializer
{
    /** The public message and fix for each item failure code. The stored `failure_details` text never leaves the server. */
    public const FAILURES = [
        'creative_package_entitlement_required' => ['The creator of this package can no longer create snaps for the company, so this item did not render.', 'Ask the human for an account that belongs to the company. Then export the package again with the same idempotency_key.'],
        'creative_package_attempts_exhausted' => ['The item used every automatic render attempt.', 'Export the package again with the same idempotency_key to start a new attempt cycle.'],
        'creative_package_render_failed' => ['The renderer did not produce an image.', 'Preview the template version and fix its layers. Then export again with a new idempotency_key.'],
        'creative_package_output_invalid' => ['The rendered image did not match the source dimensions or the requested format.', 'Preview the template version. Then export again with a new idempotency_key.'],
    ];

    /**
     * @param  array{url_expires_at: string|null, items: array<int, array{file_url: string|null, preview_url: string|null}>}|null  $urls  the output of CreativePackageMediaSigner::sign()
     * @return array<string, mixed> One key for each entry of CreativePackageContract::RESPONSE_FIELDS, in that order.
     */
    public function serialize(CreativePackage $package, bool $replayed, ?array $urls = null): array
    {
        $items = $package->items->sortBy('position')->values();
        $building = $package->status === CreativePackageContract::PACKAGE_STATUS_BUILDING;
        $hasOutput = CreativePackageContract::hasOutput($package->status);

        return [
            'package_id' => $package->uuid,
            'status' => $package->status,
            'replayed' => $replayed,
            'counts' => [
                'total' => (int) $package->total_items,
                'pending' => (int) $package->pending_items_count,
                'rendering' => (int) $package->rendering_items_count,
                'ready' => (int) $package->ready_items_count,
                'failed' => (int) $package->failed_items_count,
            ],
            'poll_after_seconds' => $building ? CreativePackageContract::limit('heartbeat_seconds', CreativePackageContract::HEARTBEAT_SECONDS) : null,
            'updated_at' => self::timestamp($package->updated_at),
            'next_action' => CreativePackageContract::NEXT_ACTIONS[$package->status],
            'url_expires_at' => $hasOutput ? ($urls['url_expires_at'] ?? null) : null,
            'items' => $items->map(fn (CreativePackageItem $item) => self::item($item))->all(),
            'manifest' => $hasOutput ? CreativePackageManifest::build($package, $items, $urls['items'] ?? []) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function item(CreativePackageItem $item): array
    {
        return self::identity($item) + [
            'status' => $item->status,
            'attempts' => (int) $item->attempts,
            'retryable' => (bool) $item->retryable,
            'failure' => self::failure($item),
            'editable_source' => self::editableSource($item),
        ];
    }

    /** @return array<string, mixed> */
    public static function identity(CreativePackageItem $item): array
    {
        return [
            'creative_id' => $item->client_creative_id,
            'family_id' => $item->client_family_id,
            'label' => $item->label,
            'placement_key' => $item->placement_key,
        ];
    }

    /** @return array{template_id: string, template_version: int, editor_document_version: int|null} */
    public static function editableSource(CreativePackageItem $item): array
    {
        return [
            'template_id' => $item->source_template_uuid,
            'template_version' => (int) $item->source_template_version,
            'editor_document_version' => $item->source_editor_document_version,
        ];
    }

    /** @return array{code: string, message: string, retryable: bool}|null */
    public static function failure(CreativePackageItem $item): ?array
    {
        if ($item->status !== CreativePackageItem::STATUS_FAILED || $item->failure_code === null) {
            return null;
        }

        return [
            'code' => $item->failure_code,
            'message' => self::FAILURES[$item->failure_code][0] ?? 'This item did not render.',
            'retryable' => (bool) $item->retryable,
        ];
    }

    public static function timestamp(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->utc()->toIso8601ZuluString();
    }
}
