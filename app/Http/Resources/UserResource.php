<?php

namespace App\Http\Resources;

use App\Models\Data;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        // Use pre-loaded aggregates if available (from withCount/withSum),
        // otherwise fallback to individual queries for backward compatibility
        $driveSize = $this->drive_size_sum ?? $this->MyDriveSize();
        $fileCount = $this->files_count ?? $this->MyDriveCountFile();
        $folderCount = $this->folders_count ?? $this->MyDriveCountFolder();
        $sharedCount = $this->shared_count ?? Data::where('shared', 'public')->where('user_id', $this->id)->count();
        $restCapacity = $this->drive_capacity - $driveSize;

        $return = [
            'id' => $this->id,
            'fullname' => $this->fullname,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'username' => $this->username,
            'email' => $this->email,
            'googleIntegated' => $this->google_id ? true : false,
            'semestaIntegrated' => $this->perangkat_daerah_id ? true : false,
            'appleIntegrated' => $this->apple_id ? true : false,
            'photo' => asset($this->photo),
            'storage' => [
                'total' => Data::generateSize($this->drive_capacity),
                'used' => Data::generateSize($driveSize),
                'rest' => Data::generateSize($restCapacity),
                'percent' => $this->drive_capacity ? (($driveSize / $this->drive_capacity) * 100) : 0,
                'total_raw' => Data::isoSizeInGb($this->drive_capacity),
            ],
            'datas' => [
                'files' => $fileCount,
                'folders' => $folderCount,
                'shared' => $sharedCount,
            ],
            'access' => $this->access == 'true' ? true : false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        return $return;
    }
}
