<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedDataResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'access_type' => $this->access_type,
            'user' => new UserResource($this->whenLoaded('user')),
            'data' => new DataResource($this->whenLoaded('data')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
