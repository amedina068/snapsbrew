<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * T-802: the F8 published-version contract is only enforceable if EVERY render
 * surface builds from `renderableLayers()`, never the raw draft `layers`. T-701
 * named this as a CI gate but shipped it as prose; this encodes it as an
 * assertion so the next surface that bypasses the seam fails here, not in prod.
 */
class RenderSurfaceSeamTest extends TestCase
{
    public function test_no_render_surface_builds_from_raw_draft_layers()
    {
        $appPath = base_path('app');

        // The render-bypass signature: feeding a SnapBuilder the draft column
        // directly instead of the published-or-draft seam.
        // The pattern starts with a dash. Pass it after -e, or grep reads it as
        // a bundle of flags, exits with code 2, and prints no line at all.
        exec(
            'grep -rn --include=*.php -e '.escapeshellarg('->setLayers($template->layers)').' '.escapeshellarg($appPath),
            $hits,
            $exitCode
        );

        // grep answers 0 when it finds a match and 1 when it finds none. Any
        // other code means the search itself broke, and an empty $hits then
        // proves nothing.
        $this->assertContains(
            $exitCode,
            [0, 1],
            "The grep search failed with exit code {$exitCode}. The empty result is not proof."
        );

        $this->assertEmpty(
            $hits,
            "Render surfaces must call \$template->renderableLayers(), not \$template->layers:\n".implode("\n", $hits)
        );
    }
}
