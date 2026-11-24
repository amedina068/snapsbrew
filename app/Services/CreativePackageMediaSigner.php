<?php

namespace App\Services;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageSerializer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * Issues the file and preview URLs of one package. Every URL is signed for
 * one exact route, package and item, and every URL of one call shares one
 * expiry. The result lives in the response only. Nothing here writes a row,
 * renders an image.
 */
final class CreativePackageMediaSigner
{
    public function __construct(private CreativePackageMediaResolver $resolver) {}

    /** @return array{url_expires_at: string|null, items: array<int, array{file_url: string|null, preview_url: string|null}>} */
    public function sign(CreativePackage $package, CarbonInterface $now): array
    {
        $package->loadMissing('items.media', 'items.snap');
        $expiresAt = $now->copy()->addSeconds(self::ttl());
        $issued = false;
        $items = [];

        foreach ($package->items as $item) {
            $media = $this->resolver->resolve((int) $package->company_id, $item);
            $items[$item->id] = ['file_url' => null, 'preview_url' => null];
            if ($media === null) {
                continue;
            }
            $issued = true;
            $items[$item->id] = [
                'file_url' => self::url('creative-packages.file', $package, $item, $expiresAt),
                'preview_url' => self::url('creative-packages.preview', $package, $item, $expiresAt),
            ];
        }

        return ['url_expires_at' => $issued ? CreativePackageSerializer::timestamp($expiresAt) : null, 'items' => $items];
    }

    /** The configured TTL, never above the contract ceiling. */
    public static function ttl(): int
    {
        return CreativePackageContract::limit('url_ttl_seconds', CreativePackageContract::URL_TTL_SECONDS, CreativePackageContract::URL_MAX_TTL_SECONDS);
    }

    private static function url(string $route, CreativePackage $package, CreativePackageItem $item, CarbonInterface $expiresAt): string
    {
        return URL::temporarySignedRoute($route, $expiresAt, ['package' => $package->uuid, 'item' => $item->uuid]);
    }
}
