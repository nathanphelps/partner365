<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Exceptions\GraphApiException;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Models\PartnerOrganization;
use App\Models\PartnerTemplate;
use App\Services\ActivityLogService;
use App\Services\CrossTenantPolicyService;
use App\Services\TenantResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerOrganizationController extends Controller
{
    public function __construct(
        private CrossTenantPolicyService $policyService,
        private TenantResolverService $tenantResolver,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $query = PartnerOrganization::with('owner')
            ->withCount('guestUsers');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($request->has('mfa_trust')) {
            $query->where('mfa_trust_enabled', $request->boolean('mfa_trust'));
        }

        $partners = $query->orderBy('display_name')->paginate(25)->withQueryString();

        return Inertia::render('partners/Index', [
            'partners' => $partners,
            'filters' => $request->only(['search', 'category', 'mfa_trust']),
        ]);
    }

    public function create(): Response
    {
        if (! request()->user()->role->canManage()) {
            abort(403);
        }

        return Inertia::render('partners/Create', [
            'templates' => PartnerTemplate::all(['id', 'name', 'description', 'policy_config']),
        ]);
    }

    public function store(StorePartnerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $tenantInfo = $this->tenantResolver->resolve($validated['tenant_id']);
        } catch (GraphApiException $e) {
            return redirect()->back()->withInput()
                ->with('error', 'Could not resolve tenant from Microsoft Graph API: '.$e->getMessage());
        }

        if (! empty($validated['template_id'])) {
            $template = PartnerTemplate::findOrFail($validated['template_id']);
            $policyKeys = [
                'b2b_inbound_enabled', 'b2b_outbound_enabled', 'mfa_trust_enabled',
                'device_trust_enabled', 'direct_connect_inbound_enabled', 'direct_connect_outbound_enabled',
                'tenant_restrictions_enabled',
            ];
            $validated = array_merge(\Illuminate\Support\Arr::only($template->policy_config, $policyKeys), $validated);
        }

        $graphConfig = $this->buildGraphConfig($validated);

        try {
            $this->policyService->createPartner($validated['tenant_id'], $graphConfig);
        } catch (GraphApiException $e) {
            return redirect()->back()->withInput()
                ->with('error', 'Failed to create partner policy in Graph API: '.$e->getMessage());
        }

        $partner = PartnerOrganization::create([
            'tenant_id' => $validated['tenant_id'],
            'display_name' => $tenantInfo['displayName'] ?? $validated['tenant_id'],
            'domain' => $tenantInfo['defaultDomainName'] ?? null,
            'category' => $validated['category'],
            'notes' => $validated['notes'] ?? null,
            'b2b_inbound_enabled' => $validated['b2b_inbound_enabled'] ?? false,
            'b2b_outbound_enabled' => $validated['b2b_outbound_enabled'] ?? false,
            'mfa_trust_enabled' => $validated['mfa_trust_enabled'] ?? false,
            'device_trust_enabled' => $validated['device_trust_enabled'] ?? false,
            'direct_connect_inbound_enabled' => $validated['direct_connect_inbound_enabled'] ?? false,
            'direct_connect_outbound_enabled' => $validated['direct_connect_outbound_enabled'] ?? false,
        ]);
        $partner->updateFromSync($graphConfig, now());

        $this->activityLog->log($request->user(), ActivityAction::PartnerCreated, $partner, [
            'tenant_id' => $partner->tenant_id,
        ]);

        return redirect()->route('partners.index')->with('success', "Partner '{$partner->display_name}' added.");
    }

    public function show(Request $request, PartnerOrganization $partner): Response
    {
        $partner->load('owner');

        $guests = $partner->guestUsers()
            ->with('invitedBy')
            ->orderByDesc('created_at')
            ->paginate(25, ['*'], 'guests_page')
            ->withQueryString();

        $conditionalAccessPolicies = $partner->conditionalAccessPolicies()
            ->withPivot('matched_user_type')
            ->get();

        $sensitivityLabels = $partner->sensitivityLabels()
            ->withPivot('matched_via', 'policy_name', 'site_name')
            ->get();

        return Inertia::render('partners/Show', [
            'partner' => $partner,
            'guests' => $guests,
            'activity' => $this->activityLog->forSubject($partner),
            'canManage' => $request->user()->role->canManage(),
            'conditionalAccessPolicies' => $conditionalAccessPolicies,
            'sensitivityLabels' => $sensitivityLabels,
        ]);
    }

    public function guests(Request $request, PartnerOrganization $partner): JsonResponse
    {
        $query = $partner->guestUsers()->with('invitedBy');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('invitation_status', $status);
        }

        if ($request->has('account_enabled')) {
            $query->where('account_enabled', $request->boolean('account_enabled'));
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(25)->withQueryString()
        );
    }

    public function update(UpdatePartnerRequest $request, PartnerOrganization $partner): RedirectResponse
    {
        $validated = $request->validated();

        $graphConfig = $this->buildGraphConfig($validated);
        if (! empty($graphConfig)) {
            try {
                $this->policyService->updatePartner($partner->tenant_id, $graphConfig);
            } catch (GraphApiException $e) {
                return redirect()->back()->withInput()
                    ->with('error', 'Failed to update partner policy in Graph API: '.$e->getMessage());
            }
        }

        $partner->update($validated);

        $this->activityLog->log($request->user(), ActivityAction::PartnerUpdated, $partner, $validated);

        return redirect()->back()->with('success', 'Partner updated.');
    }

    public function destroy(PartnerOrganization $partner): RedirectResponse
    {
        if (! request()->user()->role->isAdmin()) {
            abort(403);
        }

        try {
            $this->policyService->deletePartner($partner->tenant_id);
        } catch (GraphApiException $e) {
            return redirect()->back()
                ->with('error', 'Failed to remove partner from Graph API: '.$e->getMessage());
        }

        $this->activityLog->log(request()->user(), ActivityAction::PartnerDeleted, $partner, [
            'tenant_id' => $partner->tenant_id,
            'display_name' => $partner->display_name,
        ]);

        $partner->delete();

        return redirect()->route('partners.index')->with('success', 'Partner removed.');
    }

    public function resolveTenant(Request $request): JsonResponse
    {
        $request->validate(['tenant_id' => ['required', 'string', 'uuid']]);

        if (! $this->tenantResolver->isValidTenantId($request->input('tenant_id'))) {
            return response()->json(['error' => 'Invalid tenant ID format'], 422);
        }

        $info = $this->tenantResolver->resolve($request->input('tenant_id'));

        return response()->json($info);
    }

    private function buildGraphConfig(array $data): array
    {
        $config = [];

        if (isset($data['mfa_trust_enabled'])) {
            $config['inboundTrust'] = [
                'isMfaAccepted' => (bool) $data['mfa_trust_enabled'],
                'isCompliantDeviceAccepted' => (bool) ($data['device_trust_enabled'] ?? false),
            ];
        }

        if (isset($data['b2b_inbound_enabled'])) {
            $accessType = $data['b2b_inbound_enabled'] ? 'allowed' : 'blocked';
            $config['b2bCollaborationInbound'] = [
                'usersAndGroups' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllUsers', 'targetType' => 'user']],
                ],
                'applications' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllApplications', 'targetType' => 'application']],
                ],
            ];
        }

        if (isset($data['b2b_outbound_enabled'])) {
            $accessType = $data['b2b_outbound_enabled'] ? 'allowed' : 'blocked';
            $config['b2bCollaborationOutbound'] = [
                'usersAndGroups' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllUsers', 'targetType' => 'user']],
                ],
                'applications' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllApplications', 'targetType' => 'application']],
                ],
            ];
        }

        if (isset($data['direct_connect_inbound_enabled'])) {
            $accessType = $data['direct_connect_inbound_enabled'] ? 'allowed' : 'blocked';
            $config['b2bDirectConnectInbound'] = [
                'usersAndGroups' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllUsers', 'targetType' => 'user']],
                ],
                'applications' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllApplications', 'targetType' => 'application']],
                ],
            ];
        }

        if (isset($data['direct_connect_outbound_enabled'])) {
            $accessType = $data['direct_connect_outbound_enabled'] ? 'allowed' : 'blocked';
            $config['b2bDirectConnectOutbound'] = [
                'usersAndGroups' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllUsers', 'targetType' => 'user']],
                ],
                'applications' => [
                    'accessType' => $accessType,
                    'targets' => [['target' => 'AllApplications', 'targetType' => 'application']],
                ],
            ];
        }

        if (isset($data['tenant_restrictions_enabled'])) {
            if ($data['tenant_restrictions_enabled'] && ! empty($data['tenant_restrictions_json'])) {
                $config['tenantRestrictions'] = $data['tenant_restrictions_json'];
            } elseif (! $data['tenant_restrictions_enabled']) {
                $config['tenantRestrictions'] = null;
            }
        }

        return $config;
    }
}
