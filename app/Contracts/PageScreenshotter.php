<?php

namespace App\Contracts;

/**
 * Takes a screenshot of a public web page from its URL.
 *
 * The free screenshot tool uses this contract. The render driver selects the
 * implementation, so the tool and the snaps use the same browser.
 */
interface PageScreenshotter
{
    public function screenshotUrlBase64(string $url, int $width, int $height, bool $fullPage): string;
}
