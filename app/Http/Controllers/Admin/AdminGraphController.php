<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGraphSettingsRequest;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
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
            $masked = '••••••••'.substr($secret, -4);
        }

        return Inertia::render('admin/Graph', [
            'settings' => [
                'cloud_environment' => $settings['cloud_environment'] ?? config('graph.cloud_environment'),
                'tenant_id' => $settings['tenant_id'] ?? config('graph.tenant_id'),
                'client_id' => $settings['client_id'] ?? config('graph.client_id'),
                'client_secret_masked' => $masked,
                'scopes' => $settings['scopes'] ?? config('graph.scopes'),
                'base_url' => $settings['base_url'] ?? config('graph.base_url'),
                'sharepoint_tenant' => $settings['sharepoint_tenant'] ?? config('graph.sharepoint_tenant'),
                'sync_interval_minutes' => $settings['sync_interval_minutes'] ?? config('graph.sync_interval_minutes'),
                'compliance_certificate_path' => Setting::get('graph', 'compliance_certificate_path', config('graph.compliance_certificate_path')),
                'compliance_certificate_password' => Setting::get('graph', 'compliance_certificate_password') ? '********' : '',
            ],
        ]);
    }

    public function update(UpdateGraphSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('graph', 'cloud_environment', $validated['cloud_environment']);
        Setting::set('graph', 'tenant_id', $validated['tenant_id']);
        Setting::set('graph', 'client_id', $validated['client_id']);
        Setting::set('graph', 'scopes', $validated['scopes']);
        Setting::set('graph', 'base_url', $validated['base_url']);
        Setting::set('graph', 'sharepoint_tenant', $validated['sharepoint_tenant'] ?? null);
        Setting::set('graph', 'sync_interval_minutes', (string) $validated['sync_interval_minutes']);

        if (! empty($validated['client_secret'])) {
            Setting::set('graph', 'client_secret', $validated['client_secret'], encrypted: true);
        }

        Setting::set('graph', 'compliance_certificate_path', $validated['compliance_certificate_path'] ?? '');

        if (! empty($validated['compliance_certificate_password']) && $validated['compliance_certificate_password'] !== '********') {
            Setting::set('graph', 'compliance_certificate_password', $validated['compliance_certificate_password'], encrypted: true);
        }

        Cache::forget('msgraph_access_token');
        Cache::forget('spo_admin_access_token');

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'graph',
        ]);

        return redirect()->route('admin.graph.edit')->with('success', 'Graph settings updated.');
    }

    public function testConnection(Request $request): JsonResponse
    {
        try {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
            $cloudEnv = \App\Enums\CloudEnvironment::tryFrom(
                Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
            ) ?? \App\Enums\CloudEnvironment::Commercial;
            $loginUrl = $cloudEnv->loginUrl();

            $response = Http::asForm()->post(
                "https://{$loginUrl}/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                    'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                    'scope' => Setting::get('graph', 'scopes', config('graph.scopes')),
                ]
            );

            $success = $response->successful() && $response->json('access_token');

            $this->activityLog->log($request->user(), ActivityAction::GraphConnectionTested, null, [
                'success' => $success,
            ]);

            if ($success) {
                return response()->json(['success' => true, 'message' => 'Connection successful.']);
            }

            return response()->json([
                'success' => false,
                'message' => $response->json('error_description', 'Authentication failed.'),
            ]);
        } catch (\Throwable $e) {
            $this->activityLog->log($request->user(), ActivityAction::GraphConnectionTested, null, [
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ]);
        }
    }

    public function consentUrl(Request $request): JsonResponse
    {
        $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
        $clientId = Setting::get('graph', 'client_id', config('graph.client_id'));
        $cloudEnv = \App\Enums\CloudEnvironment::tryFrom(
            Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
        ) ?? \App\Enums\CloudEnvironment::Commercial;

        $state = bin2hex(random_bytes(32));
        $request->session()->put('graph_consent_state', $state);

        $redirectUri = route('admin.graph.consent.callback');
        $loginUrl = $cloudEnv->loginUrl();

        $url = "https://{$loginUrl}/{$tenantId}/adminconsent?"
            .http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ]);

        return response()->json(['url' => $url]);
    }

    public function consentCallback(Request $request): View
    {
        $expectedState = $request->session()->pull('graph_consent_state');
        $receivedState = $request->query('state');

        if (! $expectedState || ! hash_equals($expectedState, (string) $receivedState)) {
            return view('admin.consent-callback', [
                'success' => false,
                'error' => 'Invalid or missing state parameter. The consent request may have been tampered with.',
            ]);
        }

        $success = $request->query('admin_consent') === 'True';
        $error = $request->query('error_description');

        ActivityLog::create([
            'user_id' => null,
            'action' => ActivityAction::ConsentGranted,
            'details' => [
                'success' => $success,
                'error' => $error ? mb_substr((string) $error, 0, 1000) : null,
            ],
            'created_at' => now(),
        ]);

        return view('admin.consent-callback', [
            'success' => $success,
            'error' => $error ? mb_substr((string) $error, 0, 1000) : null,
        ]);
    }
}
