<?php

namespace App\Services;

class EditorPreflight
{
    /** @param array<string, mixed>|null $document @return array<int, array{code: string, path: string, message: string}> */
    public function inspect(?array $document): array
    {
        if ($document === null) {
            return [];
        }

        $issues = [];
        $layers = $document['layers'] ?? null;
        if (! is_array($layers)) {
            return [['code' => 'layers_missing', 'path' => '/layers', 'message' => 'Document layers are required.']];
        }

        foreach ($layers as $index => $layer) {
            if (! is_array($layer)) {
                $issues[] = ['code' => 'layer_invalid', 'path' => "/layers/{$index}", 'message' => 'Layer must be an object.'];

                continue;
            }
            if (($layer['type'] ?? null) === 'image' && ! is_string($layer['source']['asset_id'] ?? null)) {
                $issues[] = ['code' => 'image_asset_missing', 'path' => "/layers/{$index}/source", 'message' => 'Image layers require a pinned asset id.'];
            }
        }

        return $issues;
    }
}
