<?php

use App\Enums\AccessPackageResourceType;
use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant',
        'graph.client_id' => 'test-client',
        'graph.client_secret' => 'test-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
    Cache::forget('msgraph_access_token');
});

test('getOrCreateDefaultCatalog fetches existing default catalog from Graph', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/catalogs*' => Http::response([
            'value' => [
                ['id' => 'catalog-123', 'displayName' => 'General', 'isExternallyVisible' => true],
            ],
        ]),
    ]);

    $service = app(EntitlementService::class);
    $catalog = $service->getOrCreateDefaultCatalog();

    expect($catalog)->toBeInstanceOf(AccessPackageCatalog::class);
    expect($catalog->graph_id)->toBe('catalog-123');
    expect($catalog->display_name)->toBe('General');
    expect(AccessPackageCatalog::count())->toBe(1);
});

test('createAccessPackage creates package in Graph API and local DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/accessPackages' => Http::response([
            'id' => 'pkg-graph-123',
            'displayName' => 'Partner Dev Access',
        ], 201),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/assignmentPolicies' => Http::response([
            'id' => 'policy-123',
        ], 201),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/connectedOrganizations*' => Http::response([
            'value' => [],
        ]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/connectedOrganizations' => Http::response([
            'id' => 'conn-org-123',
        ], 201),
    ]);

    $catalog = AccessPackageCatalog::factory()->create(['graph_id' => 'catalog-123']);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'partner-tenant-id']);
    $admin = User::factory()->create(['role' => 'admin']);
    $approver = User::factory()->create(['role' => 'operator']);

    $service = app(EntitlementService::class);
    $package = $service->createAccessPackage($catalog, $partner, [
        'display_name' => 'Partner Dev Access',
        'description' => 'Development resources',
        'duration_days' => 90,
        'approval_required' => true,
        'approver_user_id' => $approver->id,
        'created_by_user_id' => $admin->id,
    ]);

    expect($package)->toBeInstanceOf(AccessPackage::class);
    expect($package->graph_id)->toBe('pkg-graph-123');
    expect($package->display_name)->toBe('Partner Dev Access');
    expect($package->partner_organization_id)->toBe($partner->id);
    expect(AccessPackage::count())->toBe(1);
});

test('deleteAccessPackage removes from Graph API and local DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/accessPackages/*' => Http::response([], 204),
    ]);

    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-graph-456']);

    $service = app(EntitlementService::class);
    $service->deleteAccessPackage($package);

    expect(AccessPackage::count())->toBe(0);
});

test('addResource adds resource role scope to package in Graph', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/accessPackages/*/accessPackageResourceRoleScopes' => Http::response([
            'id' => 'role-scope-123',
        ], 201),
    ]);

    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-graph-789']);

    $service = app(EntitlementService::class);
    $resource = $service->addResource($package, [
        'resource_type' => 'group',
        'resource_id' => 'group-abc-123',
        'resource_display_name' => 'Dev Team',
    ]);

    expect($resource)->toBeInstanceOf(AccessPackageResource::class);
    expect($resource->resource_type)->toBe(AccessPackageResourceType::Group);
    expect($resource->resource_display_name)->toBe('Dev Team');
    expect(AccessPackageResource::count())->toBe(1);
});

test('requestAssignment creates assignment request in Graph', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/assignmentRequests' => Http::response([
            'id' => 'request-123',
            'status' => 'PendingApproval',
        ], 201),
    ]);

    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-graph-111', 'duration_days' => 90]);

    $service = app(EntitlementService::class);
    $assignment = $service->requestAssignment($package, 'partner@external.com', 'Need access for project');

    expect($assignment)->toBeInstanceOf(AccessPackageAssignment::class);
    expect($assignment->target_user_email)->toBe('partner@external.com');
    expect($assignment->status)->toBe(AssignmentStatus::PendingApproval);
    expect($assignment->justification)->toBe('Need access for project');
    expect(AccessPackageAssignment::count())->toBe(1);
});

test('approveAssignment updates status and records approver', function () {
    $assignment = AccessPackageAssignment::factory()->create([
        'status' => AssignmentStatus::PendingApproval,
    ]);
    $operator = User::factory()->create(['role' => 'operator']);

    $service = app(EntitlementService::class);
    $service->approveAssignment($assignment, $operator);

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Approved);
    expect($assignment->approved_by_user_id)->toBe($operator->id);
    expect($assignment->approved_at)->not->toBeNull();
});

test('denyAssignment updates status', function () {
    $assignment = AccessPackageAssignment::factory()->create([
        'status' => AssignmentStatus::PendingApproval,
    ]);
    $operator = User::factory()->create(['role' => 'operator']);

    $service = app(EntitlementService::class);
    $service->denyAssignment($assignment, $operator);

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Denied);
});

test('revokeAssignment updates status to revoked', function () {
    $assignment = AccessPackageAssignment::factory()->create([
        'status' => AssignmentStatus::Delivered,
        'graph_id' => 'assign-graph-123',
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/assignmentRequests' => Http::response([
            'id' => 'revoke-request-123',
        ], 201),
    ]);

    $service = app(EntitlementService::class);
    $service->revokeAssignment($assignment);

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Revoked);
});

test('listGroups returns groups from Graph API', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/groups*' => Http::response([
            'value' => [
                ['id' => 'grp-1', 'displayName' => 'Dev Team', 'description' => 'Developers'],
                ['id' => 'grp-2', 'displayName' => 'QA Team', 'description' => 'Quality Assurance'],
            ],
        ]),
    ]);

    $service = app(EntitlementService::class);
    $groups = $service->listGroups();

    expect($groups)->toHaveCount(2);
    expect($groups[0]['displayName'])->toBe('Dev Team');
});

test('listSharePointSites returns sites from Graph API', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/sites*' => Http::response([
            'value' => [
                ['id' => 'site-1', 'displayName' => 'Project Docs', 'webUrl' => 'https://contoso.sharepoint.com/sites/docs'],
            ],
        ]),
    ]);

    $service = app(EntitlementService::class);
    $sites = $service->listSharePointSites();

    expect($sites)->toHaveCount(1);
    expect($sites[0]['displayName'])->toBe('Project Docs');
});
