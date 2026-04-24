<?php

namespace App\Models;

use App\Enums\SweepRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelSweepRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'started_at',
        'completed_at',
        'total_scanned',
        'already_labeled',
        'applied',
        'skipped_excluded',
        'failed',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_scanned' => 'integer',
            'already_labeled' => 'integer',
            'applied' => 'integer',
            'skipped_excluded' => 'integer',
            'failed' => 'integer',
            'status' => SweepRunStatus::class,
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LabelSweepRunEntry::class)->orderBy('processed_at');
    }
}
