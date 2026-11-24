<?php

namespace App\Support;

/**
 * Emission-time guard for any user-derived value destined for an inline CSS
 * declaration rendered by headless Chrome. Two layers: truncate at the first
 * character that could close the value / attribute or start a new declaration
 * (`;{}<>"'\`), then HTML-escape the remainder for the style="" attribute
 * context. Legitimate colors, font names, sizes and URLs contain none of the
 * cut characters, so they pass through unchanged. Validation allowlists
 * (ValidCssColor) are the boundary defense; this is the last line at render.
 */
class CssSanitizer
{
    public static function value($value): string
    {
        $value = (string) $value;
        $value = substr($value, 0, strcspn($value, ";{}<>\"'\\"));

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
