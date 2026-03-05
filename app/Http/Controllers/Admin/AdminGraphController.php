<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGraphSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AdminGraphController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        $settings = Setting::getGroup('graph');

        $masked = null;
        if ($secret = ($settings['client_secret'] ?? null)) {
            $masked = '••••••••' . substr($secret, -4);
        }

        return Inertia::render('admin/Graph', [
            'settings' => [
                'tenant_id' => $settings['tenant_id'] ?? config('graph.tenant_id'),
                'client_id' => $settings['client_id'] ?? config('graph.client_id'),
                'client_secret_masked' => $masked,
                'scopes' => $settings['scopes'] ?? config('graph.scopes'),
                'base_url' => $settings['base_url'] ?? config('graph.base_url'),
                'sync_interval_minutes' => $settings['sync_interval_minutes'] ?? config('graph.sync_interval_minutes'),
            ],
        ]);
    }

    public function update(UpdateGraphSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('graph', 'tenant_id', $validated['tenant_id']);
        Setting::set('graph', 'client_id', $validated['client_id']);
        Setting::set('graph', 'scopes', $validated['scopes']);
        Setting::set('graph', 'base_url', $validated['base_url']);
        Setting::set('graph', 'sync_interval_minutes', (string) $validated['sync_interval_minutes']);

        if (!empty($validated['client_secret'])) {
            Setting::set('graph', 'client_secret', $validated['client_secret'], encrypted: true);
        }

        Cache::forget('msgraph_access_token');

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'graph',
        ]);

        return redirect()->route('admin.graph.edit')->with('success', 'Graph settings updated.');
    }

    public function testConnection(): JsonResponse
    {
        try {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                    'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                    'scope' => Setting::get('graph', 'scopes', config('graph.scopes')),
                ]
            );

            if ($response->successful() && $response->json('access_token')) {
                return response()->json(['success' => true, 'message' => 'Connection successful.']);
            }

            return response()->json([
                'success' => false,
                'message' => $response->json('error_description', 'Authentication failed.'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }
}
