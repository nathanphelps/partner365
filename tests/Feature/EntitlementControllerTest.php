<?php

use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\PartnerOrganization;
use App\Models\User;
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

test('index page lists access packages', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    AccessPackage::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get('/entitlements');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('entitlements/Index')
        ->has('packages.data', 3)
    );
});

test('create page shows form for admin', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    PartnerOrganization::factory()->count(2)->create();

    $response = $this->actingAs($admin)->get('/entitlements/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('entitlements/Create')
        ->has('partners', 2)
        ->has('approvers')
    );
});

test('create page returns 403 for viewer', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);

    $response = $this->actingAs($viewer)->get('/entitlements/create');

    $response->assertForbidden();
});

test('store creates access package with resources', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/catalogs*' => Http::response([
            'value' => [
                ['id' => 'catalog-123', 'displayName' => 'General'],
            ],
        ]),
        'graph.microsoft.com/*' => Http::response(['id' => 'graph-id', 'value' => []], 201),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-123']);
    $approver = User::factory()->create(['role' => 'operator']);
    AccessPackageCatalog::factory()->create(['graph_id' => 'catalog-123', 'is_default' => true]);

    $response = $this->actingAs($admin)->post('/entitlements', [
        'partner_organization_id' => $partner->id,
        'display_name' => 'Test Package',
        'description' => 'Test description',
        'duration_days' => 90,
        'approval_required' => true,
        'approver_user_id' => $approver->id,
        'resources' => [
            [
                'resource_type' => 'group',
                'resource_id' => 'group-123',
                'resource_display_name' => 'Dev Team',
            ],
        ],
    ]);

    $response->assertRedirect('/entitlements');
    expect(AccessPackage::count())->toBe(1);
    expect(AccessPackageResource::count())->toBe(1);
});

test('show page displays package details', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $package = AccessPackage::factory()->create();
    AccessPackageResource::factory()->count(2)->create(['access_package_id' => $package->id]);

    $response = $this->actingAs($admin)->get("/entitlements/{$package->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('entitlements/Show')
        ->has('package')
    );
});

test('destroy deletes package for admin', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response([], 204),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-123']);

    $response = $this->actingAs($admin)->delete("/entitlements/{$package->id}");

    $response->assertRedirect('/entitlements');
    expect(AccessPackage::count())->toBe(0);
});

test('operator can create assignment', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['id' => 'request-123'], 201),
    ]);

    $operator = User::factory()->create(['role' => 'operator']);
    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-123']);

    $response = $this->actingAs($operator)->post("/entitlements/{$package->id}/assignments", [
        'target_user_email' => 'user@external.com',
        'justification' => 'Need access',
    ]);

    $response->assertRedirect();
    expect(AccessPackageAssignment::count())->toBe(1);
});

test('operator can approve assignment', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $package = AccessPackage::factory()->create();
    $assignment = AccessPackageAssignment::factory()->create([
        'access_package_id' => $package->id,
        'status' => AssignmentStatus::PendingApproval,
    ]);

    $response = $this->actingAs($operator)->post("/entitlements/{$package->id}/assignments/{$assignment->id}/approve");

    $response->assertRedirect();
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Approved);
});

test('viewer cannot create assignment', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $package = AccessPackage::factory()->create();

    $response = $this->actingAs($viewer)->post("/entitlements/{$package->id}/assignments", [
        'target_user_email' => 'user@external.com',
    ]);

    $response->assertForbidden();
});
