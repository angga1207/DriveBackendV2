<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray($request)
    {
        $properties = json_decode($this->properties, true);
        $return = [
            'id' => $this->id,
            'description' => $this->description,
            'ip_address' => $properties['ip'] ?? null,
            'agent' => $properties['agent'] ?? null,
            'event' => $properties['event'] ?? null,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];

        return $return;
    }
}
