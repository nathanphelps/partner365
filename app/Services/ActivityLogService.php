<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ActivityLogService
{
    public function log(User $user, ActivityAction $action, ?Model $subject = null, array $details = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public function logSystem(ActivityAction $action, ?Model $subject = null, array $details = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => null,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public function recent(int $limit = 20): Collection
    {
        return ActivityLog::with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function forSubject(Model $subject): Collection
    {
        return ActivityLog::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->get();
    }
}
