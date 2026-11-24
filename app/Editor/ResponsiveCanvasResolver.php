<?php

namespace App\Editor;

use InvalidArgumentException;

class ResponsiveCanvasResolver
{
    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function resolve(array $document, ?string $canvas = null): array
    {
        $canvas ??= $document['default_canvas'] ?? 'default';
        $canvases = $document['canvases'] ?? [];
        $selected = $canvases[$canvas] ?? null;

        if (! is_array($selected)) {
            throw new InvalidArgumentException('responsive_canvas_missing');
        }

        $base = is_array($canvases['default'] ?? null) ? $canvases['default'] : [];

        return array_replace_recursive($base, $selected, ['name' => $canvas]);
    }
}
