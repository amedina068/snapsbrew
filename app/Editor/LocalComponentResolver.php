<?php

namespace App\Editor;

/** Resolves document-contained component instances; no cross-template lookup. */
class LocalComponentResolver
{
    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function resolve(array $document): array
    {
        $components = [];
        foreach ($document['components'] ?? [] as $component) {
            if (is_array($component) && is_string($component['id'] ?? null) && is_array($component['layers'] ?? null)) {
                $components[$component['id']] = $component['layers'];
            }
        }

        foreach ($document['layers'] ?? [] as $index => $layer) {
            if (! is_array($layer) || ! is_string($layer['component_id'] ?? null) || ! isset($components[$layer['component_id']])) {
                continue;
            }

            $overrides = is_array($layer['overrides'] ?? null) ? $layer['overrides'] : [];
            $document['layers'][$index]['resolved_layers'] = array_map(
                fn (array $componentLayer) => array_replace($componentLayer, $overrides[$componentLayer['id'] ?? ''] ?? []),
                $components[$layer['component_id']]
            );
        }

        return $document;
    }
}
