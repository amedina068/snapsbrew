<?php

namespace App\Services;

use App\Models\CreativePackageItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves the stored media of one package item for one company. Every read
 * of a package file or preview goes through this class before a URL is
 * signed or a byte is served. The answer is the media or null. The null
 * never says which condition failed, so a foreign, missing and non-ready
 * item look the same to the caller.
 */
final class CreativePackageMediaResolver
{
    public function resolve(int $companyId, CreativePackageItem $item): ?Media
    {
        if ((int) $item->company_id !== $companyId || $item->status !== CreativePackageItem::STATUS_READY || $item->media_id === null) {
            return null;
        }

        $media = $item->media;
        $snap = $item->snap;

        if ($media === null || $snap === null || $media->collection_name !== 'image') {
            return null;
        }

        if ($media->model_type !== $snap->getMorphClass() || (int) $media->model_id !== (int) $snap->id || (int) $snap->company_id !== $companyId) {
            return null;
        }

        return $media;
    }
}
