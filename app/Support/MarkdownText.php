<?php

namespace App\Support;

/**
 * Text that a customer typed can go into a Markdown mail. Escape it here, so
 * that Markdown shows it as text and never as a link, an image or a heading.
 */
final class MarkdownText
{
    /**
     * Put a backslash before each Markdown punctuation character, and make the
     * text one line. The characters & < > " ' stay as they are, because Blade
     * escapes them as HTML entities after this step.
     */
    public static function escape(?string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~:=])/', '\\\\$1', $line);
    }
}
