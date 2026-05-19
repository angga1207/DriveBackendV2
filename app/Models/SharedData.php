<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedData extends Model
{
    use HasFactory;

    protected $table = 'shared_datas';

    protected $fillable = [
        'data_id',
        'user_id',
        'access_type',
    ];

    /**
     * Relationships
     */
    public function data(): BelongsTo
    {
        return $this->belongsTo(Data::class, 'data_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scopes
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithWriteAccess($query)
    {
        return $query->where('access_type', 'write');
    }

    public function scopeWithReadAccess($query)
    {
        return $query->where('access_type', 'read');
    }

    /**
     * Helper methods
     */
    public function canWrite(): bool
    {
        return $this->access_type === 'write';
    }

    public function canRead(): bool
    {
        return in_array($this->access_type, ['read', 'write']);
    }
}
