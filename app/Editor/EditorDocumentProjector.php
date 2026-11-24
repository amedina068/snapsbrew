<?php

namespace App\Editor;

use Illuminate\Validation\ValidationException;

/**
 * Adapts the canonical direct v2 document collections to the immutable
 * JSON-string columns consumed by the legacy SnapBuilder renderer.
 *
 * This intentionally preserves direct layer and group records verbatim. Token
 * resolution, component expansion, and responsive-canvas selection require a
 * later projection stage and are deliberately not inferred here.
 */
final class EditorDocumentProjector
{
    /** @param array<string, mixed> $document @return array{layers: string, groups: string} */
    public function project(array $document): array
    {
        return [
            'layers' => json_encode($this->collection($document, 'layers'), JSON_THROW_ON_ERROR),
            'groups' => json_encode($this->collection($document, 'groups'), JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string, mixed> $document @return array<int, mixed> */
    private function collection(array $document, string $key): array
    {
        $value = $document[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages([
                'document' => ["editor_document_{$key}_must_be_a_list"],
            ]);
        }

        return $value;
    }
}
