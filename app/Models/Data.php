<?php

namespace App\Models;

use App\Traits\Searchable;
use App\Traits\NodeTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Data extends Model
{
    use HasFactory, SoftDeletes, NodeTrait, Searchable;

    protected $table = 'datas';

    protected $fillable = [
        'id',
        'upload_batch_id',
        'slug',
        'type',
        'store_to',
        '_lft',
        '_rgt',
        'parent_id',
        'name',
        'path',
        'temp_path',
        'size',
        'extension',
        'mimes',
        'user_id',
        'pd_id',
        'status',
        'shared',
        'expired_at',
        'no_expired',
        'favorite',
        's_editable',
        'skip_upload_to_google',
        'gd_folder',
    ];

    protected $searchable = [
        'name',
    ];

    public function generateSlug()
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        $length = 11;
        do {
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
        } while (Data::where('slug', '=', $randomString)->first());
        return $randomString;
    }

    public function isoSize($size = null)
    {
        if (!$size) {
            $size = $this->size;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        for ($i = 0; $size > 1024; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    public static function isoSizeInGb($size = null)
    {
        return round($size / 1073741824, 2);
    }

    public static function generateSize($size)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        for ($i = 0; $size > 1024; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    // Relationships - matching original naming convention
    public function Parent()
    {
        return $this->belongsTo(Data::class, 'parent_id', 'id');
    }

    public function Childs()
    {
        return $this->hasMany(Data::class, 'parent_id', 'id');
    }

    public function User()
    {
        return $this->belongsTo(User::class);
    }

    public function transfer()
    {
        return $this->belongsTo(UploadBatch::class);
    }

    public function sharedWith()
    {
        return $this->hasMany(SharedData::class, 'data_id');
    }

    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }
}
