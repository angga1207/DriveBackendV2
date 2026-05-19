<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadBatch extends Model
{
    use HasFactory;

    protected $table = 'upload_batches';

    protected $fillable = [
        'user_id',
        'batch_id',
        'total_files',
        'processed_files',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_files' => 'integer',
            'processed_files' => 'integer',
        ];
    }

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function datas(): HasMany
    {
        return $this->hasMany(Data::class, 'upload_batch_id');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Helper methods
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_files == 0) {
            return 0;
        }

        return round(($this->processed_files / $this->total_files) * 100, 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' || $this->processed_files >= $this->total_files;
    }

    public function incrementProcessed(): void
    {
        $this->increment('processed_files');

        if ($this->isCompleted()) {
            $this->update(['status' => 'completed']);
        }
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }
}
