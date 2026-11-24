<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Allowlist for any user-supplied CSS color (S3).
 *
 * Colors flow into inline style="" declarations rendered by headless Chrome.
 * A value like `red}body{background:url(//x)` or `expression(alert(1))` would
 * otherwise inject CSS. We accept only three unambiguous, injection-free
 * shapes: hex (#rgb/#rgba/#rrggbb/#rrggbbaa), functional rgb/rgba/hsl/hsla with
 * numeric arguments only, and a bare alphabetic named-color keyword. None of
 * these can contain `;`, `{`, `}`, `<`, quotes, or backslashes.
 */
class ValidCssColor implements ValidationRule
{
    public static function matches(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        // Hex: #abc, #abcd, #aabbcc, #aabbccdd
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return true;
        }

        // Functional rgb/rgba/hsl/hsla — numeric arguments only.
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/]+\)$/i', $value)) {
            return true;
        }

        // Named keyword (e.g. red, transparent, currentColor) — alpha only, so
        // it cannot carry any injection character.
        if (preg_match('/^[a-zA-Z]{1,30}$/', $value)) {
            return true;
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::matches($value)) {
            $fail('The :attribute must be a valid CSS color (hex, rgb()/rgba()/hsl()/hsla(), or a named color).');
        }
    }
}
