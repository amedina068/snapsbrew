<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class CreativePackageRequestValidator
{
    private const STRING_LIMITS = [
        'client_creative_id' => 100,
        'client_family_id' => 100,
        'label' => 200,
        'placement_key' => 150,
        'caption' => 2200,
        'alt_text' => 1000,
    ];

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function normalize(array $request): array
    {
        $this->assertClosed($request, CreativePackageContract::REQUEST_FIELDS);

        $key = $request['idempotency_key'] ?? null;
        $this->assert(is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $key) === 1, 'idempotency_key');
        $this->assert(mb_strlen($key) <= CreativePackageContract::MAX_KEY_LENGTH, 'idempotency_key');
        $this->assert(! str_starts_with(strtolower($key), CreativePackageContract::INTERNAL_KEY_PREFIX), 'idempotency_key');

        $items = $request['items'] ?? null;
        $this->assert(is_array($items) && array_is_list($items), 'items');
        $this->assert(count($items) >= 1 && count($items) <= CreativePackageContract::limit('max_items', CreativePackageContract::MAX_ITEMS), 'items');

        $normalized = [];
        $identifiers = [];
        foreach ($items as $index => $item) {
            $path = "items.{$index}";
            $this->assert(is_array($item), $path);
            $this->assertClosed($item, CreativePackageContract::ITEM_FIELDS, $path.'.');
            $item = $this->normalizeItem($item, $path);
            $this->assert(! isset($identifiers[$item['client_creative_id']]), $path.'.client_creative_id');
            $identifiers[$item['client_creative_id']] = true;
            $normalized[] = $item;
        }

        return ['idempotency_key' => $key, 'items' => $normalized];
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, string $path): array
    {
        foreach (['client_family_id', 'placement_key', 'caption', 'metadata'] as $optional) {
            if (array_key_exists($optional, $item) && $item[$optional] === null) {
                unset($item[$optional]);
            }
        }

        foreach (self::STRING_LIMITS as $field => $limit) {
            if (! array_key_exists($field, $item) && in_array($field, ['client_family_id', 'placement_key', 'caption'], true)) {
                continue;
            }
            $this->assert(is_string($item[$field] ?? null), "{$path}.{$field}");
            $item[$field] = $this->unicode($item[$field]);
            if ($field === 'alt_text') {
                $item[$field] = preg_replace('/^\s+|\s+$/u', '', $item[$field]);
            }
            $this->assert(mb_strlen($item[$field]) <= $limit && ($field !== 'label' || $item[$field] !== ''), "{$path}.{$field}");
        }

        $this->assert(preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $item['client_creative_id']) === 1, "{$path}.client_creative_id");
        $this->assert($item['alt_text'] !== '', "{$path}.alt_text");
        $this->assert(is_string($item['template_id'] ?? null) && preg_match(CreativePackageContract::UUID_PATTERN, $item['template_id']) === 1, "{$path}.template_id");
        $item['template_id'] = strtolower($item['template_id']);
        $item['template_version'] = $this->version($item['template_version'] ?? null, "{$path}.template_version");
        $this->assert(is_string($item['format'] ?? null) && in_array($item['format'], CreativePackageContract::FORMATS, true), "{$path}.format");

        if (array_key_exists('metadata', $item)) {
            $this->assert(is_array($item['metadata']), "{$path}.metadata");
            $this->assert($item['metadata'] === [] || ! array_is_list($item['metadata']), "{$path}.metadata");
            $item['metadata'] = $this->metadata($item['metadata'], "{$path}.metadata");
            $this->assert($this->depth($item['metadata']) <= CreativePackageContract::limit('metadata_depth', CreativePackageContract::MAX_METADATA_DEPTH), "{$path}.metadata");
            $this->assertBytes($item['metadata'], CreativePackageContract::limit('metadata_bytes', CreativePackageContract::MAX_METADATA_BYTES), "{$path}.metadata");
        }

        $ordered = [];
        foreach (CreativePackageContract::ITEM_FIELDS as $field) {
            if (array_key_exists($field, $item)) {
                $ordered[$field] = $item[$field];
            }
        }

        return $ordered;
    }

    private function metadata(array $value, string $path): array
    {
        if ($value === []) {
            return [];
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $child) => $this->metadataValue($child, $path), $value);
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $this->assert(is_string($key), $path);
            $key = $this->unicode($key);
            $lower = mb_strtolower($key);
            $this->assert(! array_key_exists($key, $normalized), $path);
            $this->assert(! in_array($lower, CreativePackageContract::RESERVED_METADATA_FIELDS, true), $path);
            $this->assert(! str_starts_with($lower, CreativePackageContract::INTERNAL_KEY_PREFIX), $path);
            $normalized[$key] = $this->metadataValue($child, $path);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function metadataValue(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            return $this->metadata($value, $path);
        }
        $this->assert(is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value), $path);
        if (is_string($value)) {
            return $this->unicode($value);
        }

        return is_float($value) && is_finite($value) && floor($value) === $value
            && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX ? (int) $value : $value;
    }

    private function version(mixed $value, string $path): int
    {
        $this->assert(is_int($value) || (is_string($value) && ctype_digit($value)), $path);
        $digits = ltrim((string) $value, '0') ?: '0';
        $this->assert(ctype_digit($digits), $path);
        $this->assert(strlen($digits) < 10 || (strlen($digits) === 10 && strcmp($digits, '4294967295') <= 0), $path);
        $this->assert($digits !== '0', $path);

        return (int) $digits;
    }

    private function depth(mixed $value): int
    {
        return is_array($value) && $value !== []
            ? 1 + max(array_map(fn (mixed $child) => $this->depth($child), $value))
            : 0;
    }

    private function assertBytes(mixed $value, int $limit, string $path): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $this->assert(strlen($json) <= $limit, $path);
    }

    private function assertClosed(array $value, array $allowed, string $prefix = ''): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $field) {
            $this->fail($prefix.(string) $field);
        }
    }

    private function unicode(string $value): string
    {
        return \Normalizer::normalize($value, \Normalizer::FORM_C);
    }

    private function assert(bool $condition, string $path): void
    {
        if (! $condition) {
            $this->fail($path);
        }
    }

    private function fail(string $path): never
    {
        throw ValidationException::withMessages([$path => 'The creative package request is invalid.']);
    }
}
