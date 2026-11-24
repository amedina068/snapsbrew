<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SnapResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // The size the snap rendered at is the size of its source version. The
        // template is the fallback for a legacy row with no source, and an
        // inline design has no template at all.
        $source = $this->sourceTemplateVersion ?? $this->template;

        return [
            'id' => $this->uuid,
            'media_url' => $this->imageUrl,
            'width' => $source?->width ? (int) $source->width : null,
            'height' => $source?->height ? (int) $source->height : null,
            'type' => $this->type,
            'status' => $this->status,
            'is_async' => (bool) $this->is_async,
            'webhook_url' => $this->webhook_url,
            'completed_at' => $this->completed_at,
            'last_error' => $this->last_error,
            'variants' => json_decode($this->variants, true),
            'template_id' => optional($this->template)->uuid,
            'template_title' => optional($this->template)->title,
            'created_at' => $this->created_at,
        ];
    }
}
