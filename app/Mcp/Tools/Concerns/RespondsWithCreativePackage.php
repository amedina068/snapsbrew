<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\CreativePackage;
use App\Services\CreativePackageMediaSigner;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageSerializer;
use Laravel\Mcp\Response;

/**
 * The one response body that export, replay and lookup return.
 *
 * URLs are signed on every call that has output. The server persists no URL
 * expiry, so there is nothing to compare a refresh threshold against, and a
 * fresh signature on every poll is the only rule that needs no stored input.
 */
trait RespondsWithCreativePackage
{
    private function packageResponse(CreativePackage $package, bool $replayed): Response
    {
        $package->load('items');
        $urls = CreativePackageContract::hasOutput($package->status) ? app(CreativePackageMediaSigner::class)->sign($package, now()) : null;

        // Slashes stay unescaped so that every signed URL reads as a URL.
        return Response::text(json_encode(
            app(CreativePackageSerializer::class)->serialize($package, $replayed, $urls),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
