<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RefPerangkatDaerah extends Model
{
    use HasFactory;

    protected $table = 'ref_perangkat_daerah';

    protected $fillable = [
        'nama',
        'singkatan',
        'kode',
        'status',
    ];

    /**
     * Relationships
     */
    public function users()
    {
        return $this->hasMany(User::class, 'perangkat_daerah_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
