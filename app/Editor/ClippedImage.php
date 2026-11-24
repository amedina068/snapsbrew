<?php

namespace App\Editor;

/** Shared, fail-closed crop/mask grammar for editor images and renderer CSS. */
class ClippedImage
{
    public const FITS = ['cover', 'contain', 'fill'];

    public const MASKS = ['none', 'circle', 'rounded'];

    /** @param array<string, mixed> $image */
    public static function css(array $image): string
    {
        $fit = in_array($image['fit'] ?? null, self::FITS, true) ? $image['fit'] : 'cover';
        $mask = in_array($image['mask'] ?? null, self::MASKS, true) ? $image['mask'] : 'none';
        // Explicit focal coordinates win over the ordinary crop position.
        $x = self::position($image['focal_x'] ?? $image['x'] ?? 50);
        $y = self::position($image['focal_y'] ?? $image['y'] ?? 50);
        $clip = match ($mask) {
            'circle' => 'border-radius:50%;',
            'rounded' => 'border-radius:12%;',
            default => '',
        };

        return "background-size: {$fit};background-position:{$x}% {$y}%;overflow:hidden;{$clip}";
    }

    private static function position(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : 50;
    }
}
