<?php

namespace App\Support;

/**
 * The single source of the snap document shell.
 *
 * Wraps a SnapBuilder head + content into one standalone HTML document. Both the
 * render path (Snap::renderDocument) and the free preview path (PreviewRenderer)
 * build their markup through here so the two stay byte-identical — a render
 * must screenshot exactly what the preview showed.
 */
class SnapDocument
{
    public static function wrap(string $head, string $content): string
    {
        return '<!DOCTYPE html>'
            .'<html>'
            .'<head><meta name="viewport" content="width=device-width, initial-scale=1">'.$head.'</head>'
            .'<body style="margin: 0">'.$content.'</body>'
            .'</html>';
    }
}
