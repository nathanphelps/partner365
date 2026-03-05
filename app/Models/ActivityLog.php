<?php

namespace App\Models;

use App\Enums\ActivityAction;
use App\Observers\ActivityLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[ObservedBy(ActivityLogObserver::class)]
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'details', 'created_at'];

    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
