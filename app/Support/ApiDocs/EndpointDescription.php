<?php

namespace App\Support\ApiDocs;

/**
 * The plain-text description of one endpoint, with everything ApiSpec attaches
 * to it.
 *
 * `ApiSpec` carries `middleware` and `notes` beside the prose of an
 * endpoint. A reader needs all three, and a generator that renders only the
 * prose drops the rest in silence.
 *
 * `PostmanGenerator` did exactly that. It read `$endpoint['description']` and
 * nothing else, so no `notes` entry ever reached the collection. The gap was
 * invisible until one note carried a breaking change, and `CLAUDE.md` promises
 * that every surface updates from `ApiSpec` on its own.
 *
 * Two generators read this class. `MarkdownGenerator` and the documentation
 * screen render the same three parts with their own formatting, because both
 * have real markup to spend. This class holds the plain-text form only.
 */
class EndpointDescription
{
    /** Fold the prose, the gates and every note into one string. */
    public static function plainText(array $endpoint): string
    {
        $lines = [$endpoint['description']];

        if (! empty($endpoint['middleware'])) {
            $lines[] = "\nGates: ".implode(', ', $endpoint['middleware']).'.';
        }
        foreach ($endpoint['notes'] ?? [] as $note) {
            $lines[] = "\nNote: ".$note;
        }

        return implode("\n", $lines);
    }
}
