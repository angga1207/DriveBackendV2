<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use App\Services\FolderSizeService;
use Illuminate\Http\Resources\Json\JsonResource;

class DataResource extends JsonResource
{
    public function toArray($request)
    {
        $size = 0;
        if ($this->type == 'file') {
            $size = $this->size ?? 0;
        } elseif ($this->type == 'folder') {
            try {
                $size = FolderSizeService::getFolderSize($this->resource);
            } catch (\Exception $e) {
                $size = 0;
            }
        }

        $noExpired = false;
        if ($this->shared == 'public' && $this->expired_at == '9999-12-31 23:59:59') {
            $noExpired = true;
        }

        $localPath = '';
        if ($this->temp_path) {
            $localPath = basename($this->temp_path);
        }

        $user = $this->User ? $this->User : null;
        $parent = $this->Parent ? $this->Parent : null;
        $childsCount = $this->childs_count ?? ($this->Childs ? $this->Childs->count() : 0);

        $return = [
            'id' => $this->id,
            'parent_id' => $this->parent_id ?? 0,
            'parent_slug' => $parent ? $parent->slug : 0,
            'parent_name' => $parent ? $parent->name : 'Root',
            'name' => $this->name ?? '',
            'type' => $this->type ?? 'file',
            'extension' => $this->extension ?? '',
            'mime' => $this->mimes ? strtok($this->mimes, '/') : '',
            'full_mime' => $this->mimes ?? '',
            'size' => $this->isoSize($size),
            'size_bytes' => $size,
            'slug' => $this->slug ?? '',
            'favorite' => $this->favorite ?? false,
            'path' => $this->path ?: asset('storage/temp/' . $localPath),
            'sv_in' => $this->path ? 1 : 2,
            'publicity' => [
                'status' => $this->shared ?? 'private',
                'expired_at' => $this->expired_at,
                'forever' => $noExpired,
                'editable' => $this->s_editable ? true : false,
            ],
            'author' => [
                'id' => $user->id ?? null,
                'fullname' => $user->fullname ?? null,
                'firstname' => $user->firstname ?? null,
                'lastname' => $user->lastname ?? null,
                'email' => $user->email ?? null,
                'photo' => $user ? asset($user->photo ?? '') : null,
            ],
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,
            'deleted_at' => $this->deleted_at ? Carbon::parse($this->deleted_at)->format('Y-m-d H:i:s') : null,
            'childs' => $childsCount
        ];

        return $return;
    }
}
