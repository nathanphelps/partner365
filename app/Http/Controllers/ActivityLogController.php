<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(): Response
    {
        $logs = ActivityLog::with('user')
            ->orderByDesc('created_at')
            ->paginate(50);

        return Inertia::render('activity/Index', [
            'logs' => $logs,
        ]);
    }
}
