<?php

namespace App\Services;

use App\Models\Data;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FolderSizeService
{
    public static function getFolderSize(Data $folder): int
    {
        if ($folder->type !== 'folder') {
            return $folder->size;
        }

        $cacheKey = "folder_size_{$folder->id}";

        return Cache::remember($cacheKey, 3600, function () use ($folder) {
            return DB::table('datas')
                ->where('_lft', '>', $folder->_lft)
                ->where('_rgt', '<', $folder->_rgt)
                ->where('type', 'file')
                ->whereNull('upload_batch_id')
                ->whereNull('temp_path')
                ->whereNull('deleted_at')
                ->sum('size');
        });
    }

    public static function clearFolderSizeCache(Data $folder): void
    {
        $cacheKey = "folder_size_{$folder->id}";
        Cache::forget($cacheKey);

        if ($folder->parent_id) {
            $ancestors = $folder->ancestors;
            foreach ($ancestors as $ancestor) {
                Cache::forget("folder_size_{$ancestor->id}");
            }
        }
    }

    public static function clearUserFolderCaches(int $userId): void
    {
        $folders = Data::where('user_id', $userId)
            ->where('type', 'folder')
            ->pluck('id');

        foreach ($folders as $folderId) {
            Cache::forget("folder_size_{$folderId}");
        }
    }
}
