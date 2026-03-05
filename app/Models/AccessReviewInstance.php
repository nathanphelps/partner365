<?php

namespace App\Models;

use App\Enums\ReviewInstanceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessReviewInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_review_id', 'status', 'started_at', 'due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewInstanceStatus::class,
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function accessReview(): BelongsTo
    {
        return $this->belongsTo(AccessReview::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AccessReviewDecision::class);
    }
}
