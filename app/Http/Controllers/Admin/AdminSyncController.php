<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSyncSettingsRequest;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class AdminSyncController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/Sync', [
            'intervals' => [
                'partners_interval_minutes' => Setting::get('sync', 'partners_interval_minutes', config('graph.sync_interval_minutes')),
                'guests_interval_minutes' => Setting::get('sync', 'guests_interval_minutes', config('graph.sync_interval_minutes')),
            ],
            'logs' => [
                'partners' => SyncLog::byType('partners')->recent(10)->get(),
                'guests' => SyncLog::byType('guests')->recent(10)->get(),
            ],
        ]);
    }

    public function update(UpdateSyncSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('sync', 'partners_interval_minutes', (string) $validated['partners_interval_minutes']);
        Setting::set('sync', 'guests_interval_minutes', (string) $validated['guests_interval_minutes']);

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'sync',
        ]);

        return redirect()->back()->with('success', 'Sync settings updated.');
    }

    public function run(Request $request, string $type): JsonResponse
    {
        if (!in_array($type, ['partners', 'guests'])) {
            abort(404);
        }

        Artisan::queue("sync:{$type}");

        $this->activityLog->log($request->user(), ActivityAction::SyncTriggered, null, [
            'type' => $type,
        ]);

        return response()->json(['message' => 'Sync started.']);
    }
}
