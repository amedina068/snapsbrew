<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One image of the media library, as `ApiSpec` documents the `Media` schema.
 *
 * The Spatie model serializes the owner (`model_type`, `model_id`), the disks
 * and the conversion data. Those are internal, so this resource lists the
 * public fields one by one.
 *
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'original_url' => $this->original_url,
            'src' => $this->getUrl('image'),
            'created_at' => $this->created_at,
        ];
    }
}
