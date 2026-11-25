<?php

namespace App\Rendering;

/**
 * Collects the problems of one render that did not stop the render, for
 * example a font that the server could not load.
 *
 * The container binds it as scoped, so each request and each queued job
 * starts with an empty list. Snap::getImage() flushes it before and after a
 * render and stores the warnings with the image.
 */
class RenderWarnings
{
    /** @var list<array<string, string>> */
    private array $warnings = [];

    /** @param  array<string, string>  $warning  a `code`, a `message` and the subject of the warning */
    public function add(array $warning): void
    {
        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /** @return list<array<string, string>> */
    public function all(): array
    {
        return $this->warnings;
    }

    /** @return list<array<string, string>> */
    public function flush(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];

        return $warnings;
    }
}
