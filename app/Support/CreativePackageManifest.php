<?php

namespace App\Support;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use Illuminate\Support\Collection;

/**
 * Manifest version 1. Every item carries the same keys. A ready item carries
 * its stored file facts, including the measured output dimensions, and the
 * evaluated checks. The dimensions are item facts, so a deleted source
 * version does not blank them. An item that is not ready
 * carries null file facts, its public failure, and the checks it never
 * reached. URLs stay null unless the caller passes the signer's per-item URLs.
 */
final class CreativePackageManifest
{
    /**
     * @param  Collection<int, CreativePackageItem>  $items  in position order
     * @param  array<int, array{file_url: string|null, preview_url: string|null}>  $urls  keyed by item id
     * @return array<string, mixed>
     */
    public static function build(CreativePackage $package, Collection $items, array $urls = []): array
    {
        return [
            'manifest_version' => CreativePackageContract::VERSION,
            'package_id' => $package->uuid,
            'status' => $package->status,
            'created_at' => CreativePackageSerializer::timestamp($package->created_at),
            'items' => $items->map(fn (CreativePackageItem $item) => self::item($item, $urls[$item->id] ?? []))->all(),
        ];
    }

    /**
     * @param  array{file_url?: string|null, preview_url?: string|null}  $urls
     * @return array<string, mixed>
     */
    private static function item(CreativePackageItem $item, array $urls): array
    {
        $ready = $item->status === CreativePackageItem::STATUS_READY;

        return CreativePackageSerializer::identity($item) + [
            'status' => $item->status,
            'failure' => CreativePackageSerializer::failure($item),
            'width' => $ready ? $item->output_width : null,
            'height' => $ready ? $item->output_height : null,
            'mime_type' => $ready ? $item->output_mime_type : null,
            'byte_size' => $ready ? $item->output_bytes : null,
            'sha256' => $ready ? $item->output_sha256 : null,
            'file_url' => $ready ? ($urls['file_url'] ?? null) : null,
            'preview_url' => $ready ? ($urls['preview_url'] ?? null) : null,
            'editable_source' => CreativePackageSerializer::editableSource($item),
            'caption' => $item->caption,
            'alt_text' => $item->alt_text,
            'metadata' => self::metadata($item),
            'validation' => self::validation($item),
        ];
    }

    /** A map, or an empty object so an empty map encodes as `{}`. */
    private static function metadata(CreativePackageItem $item): array|\stdClass
    {
        $metadata = array_diff_key($item->metadata ?? [], array_flip(CreativePackageContract::RESERVED_METADATA_FIELDS));

        return $metadata === [] ? new \stdClass : $metadata;
    }

    /** @return array{status: string, passed: array<int, string>, failed: array<int, array<string, string>>, not_evaluated: array<int, string>} */
    private static function validation(CreativePackageItem $item): array
    {
        $claim = CreativePackageContract::VALIDATION_CLAIM_CHECKS;
        $output = CreativePackageContract::VALIDATION_OUTPUT_CHECKS;
        $deferred = CreativePackageContract::VALIDATION_NOT_EVALUATED;

        return match ($item->status) {
            CreativePackageItem::STATUS_READY => ['status' => 'passed', 'passed' => [...$claim, ...$output], 'failed' => [], 'not_evaluated' => $deferred],
            CreativePackageItem::STATUS_FAILED => ['status' => 'failed', 'passed' => $claim, 'failed' => [self::result($item)], 'not_evaluated' => $deferred],
            default => ['status' => 'not_evaluated', 'passed' => [], 'failed' => [], 'not_evaluated' => [...$claim, ...$output, ...$deferred]],
        };
    }

    /** @return array{severity: string, code: string, path: string, message: string, fix: string} */
    private static function result(CreativePackageItem $item): array
    {
        $failure = CreativePackageSerializer::failure($item) ?? ['code' => 'creative_package_item_failed', 'message' => 'This item did not render.'];

        return [
            'severity' => 'error',
            'code' => $failure['code'],
            'path' => "items[{$item->position}]",
            'message' => $failure['message'],
            'fix' => CreativePackageSerializer::FAILURES[$failure['code']][1] ?? 'Read the failure code. Then export again with a new idempotency_key.',
        ];
    }
}
