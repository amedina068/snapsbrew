<?php

namespace App\Http\Controllers;

use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Services\CreativePackageMediaResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the bytes behind a signed package file or preview URL. The
 * signature is the only credential: the `signed` middleware rejects a
 * tampered or expired URL with 403 before this code runs. Every other
 * failure is 404 with no detail, so an absent package, a foreign tenant, a
 * non-ready item and missing media are indistinguishable.
 *
 * The preview route serves the same object as the file route today. A
 * smaller preview is not in scope. The two routes stay distinct so a
 * signature for one role never validates on the other.
 *
 * These are bearer URLs that the signer issues per response. They are not
 * public API endpoints and they are absent from `ApiSpec` on purpose.
 */
class CreativePackageMediaController extends Controller
{
    public function __construct(private CreativePackageMediaResolver $resolver) {}

    public function file(string $package, string $item): StreamedResponse
    {
        return $this->serve($package, $item);
    }

    public function preview(string $package, string $item): StreamedResponse
    {
        return $this->serve($package, $item);
    }

    private function serve(string $packageUuid, string $itemUuid): StreamedResponse
    {
        $package = CreativePackage::query()->where('uuid', $packageUuid)->first();
        $item = $package ? CreativePackageItem::query()->where('creative_package_id', $package->id)->where('uuid', $itemUuid)->first() : null;
        $media = $item ? $this->resolver->resolve((int) $package->company_id, $item) : null;
        $disk = $media ? Storage::disk($media->disk) : null;
        $path = $media?->getPathRelativeToRoot();

        if ($disk === null || ! $disk->exists($path)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        $filename = (Str::slug((string) $item->label) ?: 'creative').'-'.$item->uuid.($extension === '' ? '' : ".{$extension}");

        return $disk->response($path, $filename, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, no-store'], 'attachment');
    }
}
