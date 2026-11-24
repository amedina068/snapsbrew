<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenshotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->uuid,
            'url' => $this->url,
            'image_url' => $this->imageUrl,
            'width' => $this->width,
            'height' => $this->height,
            'created_at' => $this->created_at,
        ];
    }
}
