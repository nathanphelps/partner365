<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ActivityLog::with('user')->orderByDesc('created_at');

        if ($request->filled('actions')) {
            $query->whereIn('action', $request->input('actions'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $query->where('details', 'like', '%'.$request->input('search').'%');
        }

        return Inertia::render('activity/Index', [
            'logs' => $query->paginate(50)->withQueryString(),
            'filters' => $request->only(['actions', 'user_id', 'date_from', 'date_to', 'search']),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
