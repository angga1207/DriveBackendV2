<?php

namespace App\Models;

use App\Traits\Searchable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, Searchable;

    protected $fillable = [
        'fullname',
        'firstname',
        'lastname',
        'username',
        'photo',
        'status',
        'google_id',
        'apple_id',
        'perangkat_daerah_id',
        'email',
        'password',
        'drive_capacity',
        'fcm_token',
        'access',
        'isAdmin',
    ];

    protected $searchable = [
        'fullname',
        'firstname',
        'lastname',
        'username',
        'email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function hasAdminAccess(): bool
    {
        return in_array($this->isAdmin, [true, 'true', 1, '1'], true);
    }

    public function OPD()
    {
        return $this->hasOne(RefPerangkatDaerah::class, 'id', 'perangkat_daerah_id');
    }

    public function MyDrive()
    {
        return $this->hasMany(Data::class, 'user_id', 'id')->whereNull('temp_path');
    }

    public function MyDriveSize()
    {
        return $this->hasMany(Data::class, 'user_id', 'id')
            ->where('type', 'file')
            ->whereNull('temp_path')
            ->whereNull('deleted_at')
            ->sum('size');
    }

    public function MyDriveRestCapacity()
    {
        return $this->drive_capacity - $this->MyDriveSize();
    }

    public function MyDriveCount()
    {
        return $this->hasMany(Data::class, 'user_id', 'id')
            ->whereNull('temp_path')
            ->whereNull('deleted_at')
            ->count();
    }

    public function MyDriveCountFolder()
    {
        return $this->hasMany(Data::class, 'user_id', 'id')
            ->whereNull('temp_path')
            ->where('type', 'folder')
            ->whereNull('deleted_at')
            ->count();
    }

    public function MyDriveCountFile()
    {
        return $this->hasMany(Data::class, 'user_id', 'id')
            ->where('type', 'file')
            ->whereNull('deleted_at')
            ->count();
    }

    public function UploadBatch()
    {
        return $this->hasMany(UploadBatch::class, 'user_id', 'id');
    }
}
