<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $properties = match (true) {
            $this->properties instanceof Collection => $this->properties->toArray(),
            is_array($this->properties) => $this->properties,
            is_string($this->properties) => json_decode($this->properties, true) ?: [],
            default => [],
        };

        return [
            'id' => $this->id,
            'description' => $this->description,
            'ip_address' => $properties['ip'] ?? null,
            'agent' => $properties['agent'] ?? null,
            'event' => $properties['event'] ?? null,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
