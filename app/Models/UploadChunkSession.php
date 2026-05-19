<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadChunkSession extends Model
{
    use HasFactory;

    protected $table = 'upload_chunk_sessions';

    protected $fillable = [
        'user_id',
        'data_id',
        'chunk_id',
        'file_size',
        'total_chunks',
        'uploaded_parts',
        'status',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'total_chunks' => 'integer',
        'uploaded_parts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function data(): BelongsTo
    {
        return $this->belongsTo(Data::class, 'data_id');
    }

    public function getProgressPercentage(): float
    {
        if ($this->total_chunks <= 0) {
            return 0;
        }

        return round(($this->uploaded_parts / $this->total_chunks) * 100, 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' || $this->uploaded_parts >= $this->total_chunks;
    }

    public function incrementUploadedParts(int $uploadedParts = 1): void
    {
        $this->uploaded_parts += $uploadedParts;
        $this->save();

        if ($this->isCompleted()) {
            $this->status = 'completed';
            $this->save();
        }
    }
}
