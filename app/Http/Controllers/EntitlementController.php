<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\StoreAccessPackageRequest;
use App\Http\Requests\UpdateAccessPackageRequest;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntitlementController extends Controller
{
    public function __construct(
        private EntitlementService $entitlementService,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $packages = AccessPackage::with(['partnerOrganization', 'approver', 'createdBy'])
            ->withCount(['resources', 'assignments'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('entitlements/Index', [
            'packages' => $packages,
            'canManage' => $request->user()->role->canManage(),
            'isAdmin' => $request->user()->role->isAdmin(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        return Inertia::render('entitlements/Create', [
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name', 'tenant_id']),
            'approvers' => User::whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function store(StoreAccessPackageRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $partner = PartnerOrganization::findOrFail($validated['partner_organization_id']);
        $catalog = $this->entitlementService->getOrCreateDefaultCatalog();

        $package = $this->entitlementService->createAccessPackage($catalog, $partner, [
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => $validated['duration_days'],
            'approval_required' => $validated['approval_required'],
            'approver_user_id' => $validated['approver_user_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        foreach ($validated['resources'] as $resourceData) {
            $this->entitlementService->addResource($package, $resourceData);
        }

        $this->activityLog->log($request->user(), ActivityAction::AccessPackageCreated, $package, [
            'display_name' => $package->display_name,
            'partner' => $partner->display_name,
        ]);

        return redirect()->route('entitlements.index')->with('success', "Access package '{$package->display_name}' created.");
    }

    public function show(Request $request, AccessPackage $entitlement): Response
    {
        $entitlement->load([
            'partnerOrganization', 'catalog', 'approver', 'createdBy',
            'resources',
            'assignments' => fn ($q) => $q->orderByDesc('requested_at'),
            'assignments.approvedBy',
        ]);

        return Inertia::render('entitlements/Show', [
            'package' => $entitlement,
            'canManage' => $request->user()->role->canManage(),
            'isAdmin' => $request->user()->role->isAdmin(),
        ]);
    }

    public function update(UpdateAccessPackageRequest $request, AccessPackage $entitlement): RedirectResponse
    {
        $this->entitlementService->updateAccessPackage($entitlement, $request->validated());

        $this->activityLog->log($request->user(), ActivityAction::AccessPackageUpdated, $entitlement, [
            'display_name' => $entitlement->display_name,
        ]);

        return redirect()->back()->with('success', 'Access package updated.');
    }

    public function destroy(Request $request, AccessPackage $entitlement): RedirectResponse
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        $name = $entitlement->display_name;
        $this->entitlementService->deleteAccessPackage($entitlement);

        $this->activityLog->log($request->user(), ActivityAction::AccessPackageDeleted, null, [
            'display_name' => $name,
        ]);

        return redirect()->route('entitlements.index')->with('success', 'Access package deleted.');
    }

    public function createAssignment(Request $request, AccessPackage $entitlement): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $validated = $request->validate([
            'target_user_email' => ['required', 'email', 'max:255'],
            'justification' => ['nullable', 'string', 'max:5000'],
        ]);

        $assignment = $this->entitlementService->requestAssignment(
            $entitlement,
            $validated['target_user_email'],
            $validated['justification'] ?? null,
        );

        $this->activityLog->log($request->user(), ActivityAction::AssignmentRequested, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', "Assignment requested for {$assignment->target_user_email}.");
    }

    public function approveAssignment(Request $request, AccessPackage $entitlement, AccessPackageAssignment $assignment): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->entitlementService->approveAssignment($assignment, $request->user());

        $this->activityLog->log($request->user(), ActivityAction::AssignmentApproved, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', 'Assignment approved.');
    }

    public function denyAssignment(Request $request, AccessPackage $entitlement, AccessPackageAssignment $assignment): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->entitlementService->denyAssignment($assignment, $request->user());

        $this->activityLog->log($request->user(), ActivityAction::AssignmentDenied, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', 'Assignment denied.');
    }

    public function revokeAssignment(Request $request, AccessPackage $entitlement, AccessPackageAssignment $assignment): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->entitlementService->revokeAssignment($assignment);

        $this->activityLog->log($request->user(), ActivityAction::AssignmentRevoked, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', 'Assignment revoked.');
    }

    public function groups(): JsonResponse
    {
        return response()->json($this->entitlementService->listGroups());
    }

    public function sharepointSites(): JsonResponse
    {
        return response()->json($this->entitlementService->listSharePointSites());
    }
}
