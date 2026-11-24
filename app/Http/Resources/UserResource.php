<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'name' => $this->name,
            // The `whoami` MCP tool returns the same company name. The name is
            // null when the user belongs to no company, on both surfaces.
            'company' => optional($this->company)->name,
        ];
    }
}
