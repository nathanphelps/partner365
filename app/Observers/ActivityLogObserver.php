<?php

namespace App\Observers;

use App\Jobs\ForwardToSyslog;
use App\Models\ActivityLog;

class ActivityLogObserver
{
    public function created(ActivityLog $activityLog): void
    {
        ForwardToSyslog::dispatch($activityLog);
    }
}
