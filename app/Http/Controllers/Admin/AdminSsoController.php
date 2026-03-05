<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSsoSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminSsoController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        $graphClientId = Setting::get('graph', 'client_id', config('graph.client_id'));
        $graphTenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

        return Inertia::render('admin/Sso', [
            'settings' => [
                'enabled' => Setting::get('sso', 'enabled', 'false') === 'true',
                'auto_approve' => Setting::get('sso', 'auto_approve', 'false') === 'true',
                'default_role' => Setting::get('sso', 'default_role', 'viewer'),
                'group_mapping_enabled' => Setting::get('sso', 'group_mapping_enabled', 'false') === 'true',
                'group_mappings' => json_decode(Setting::get('sso', 'group_mappings', '[]'), true),
                'restrict_provisioning_to_mapped_groups' => Setting::get('sso', 'restrict_provisioning_to_mapped_groups', 'false') === 'true',
            ],
            'graphConfigured' => ! empty($graphClientId) && ! empty($graphTenantId),
        ]);
    }

    public function update(UpdateSsoSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('sso', 'enabled', $validated['enabled'] ? 'true' : 'false');
        Setting::set('sso', 'auto_approve', $validated['auto_approve'] ? 'true' : 'false');
        Setting::set('sso', 'default_role', $validated['default_role']);
        Setting::set('sso', 'group_mapping_enabled', $validated['group_mapping_enabled'] ? 'true' : 'false');
        Setting::set('sso', 'group_mappings', json_encode($validated['group_mappings']));
        Setting::set('sso', 'restrict_provisioning_to_mapped_groups', $validated['restrict_provisioning_to_mapped_groups'] ? 'true' : 'false');

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'sso',
        ]);

        return redirect()->route('admin.sso.edit')->with('success', 'SSO settings updated.');
    }
}
