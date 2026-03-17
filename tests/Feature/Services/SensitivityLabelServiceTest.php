<?php

use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SensitivityLabelPolicy;
use App\Models\SiteSensitivityLabel;
use App\Services\SensitivityLabelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
        'graph.cloud_environment' => 'commercial',
        'graph.sharepoint_tenant' => 'contoso',
        'graph.compliance_certificate_path' => null,
    ]);

    Cache::forget('msgraph_access_token');
    Cache::forget('spo_admin_access_token');
});

function makeGraphLabel(array $overrides = []): array
{
    return array_merge([
        'id' => 'label-1',
        'name' => 'Confidential',
        'description' => 'Confidential data',
        'color' => 'red',
        'tooltip' => 'Apply to confidential content',
        'isActive' => true,
        'priority' => 2,
        'parent' => null,
        'contentFormats' => ['file', 'email', 'site', 'group'],
        'protectionSettings' => [
            'encryptionEnabled' => true,
        ],
    ], $overrides);
}

function fakeGraphForSensitivityLabels(array $labels = []): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels' => Http::response([
            'value' => $labels,
        ]),
    ]);
}

test('syncLabels upserts labels from Graph API', function () {
    fakeGraphForSensitivityLabels([makeGraphLabel()]);

    $service = app(SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect(SensitivityLabel::count())->toBe(1);
    $label = SensitivityLabel::first();
    expect($label->label_id)->toBe('label-1');
    expect($label->name)->toBe('Confidential');
    expect($label->is_active)->toBeTrue();
    expect($label->protection_type)->toBe('encryption');
    expect($result['labels_synced'])->toBe(1);
});

test('syncLabels handles sub-labels via parent reference', function () {
    $parent = makeGraphLabel(['id' => 'parent-1', 'name' => 'Confidential']);
    $child = makeGraphLabel([
        'id' => 'child-1',
        'name' => 'Confidential - Internal Only',
        'parent' => ['id' => 'parent-1'],
    ]);

    fakeGraphForSensitivityLabels([$parent, $child]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    expect(SensitivityLabel::count())->toBe(2);
    $childModel = SensitivityLabel::where('label_id', 'child-1')->first();
    expect($childModel->parent_label_id)->toBe(SensitivityLabel::where('label_id', 'parent-1')->first()->id);
});

test('syncLabels removes stale labels on re-sync', function () {
    SensitivityLabel::create([
        'label_id' => 'stale-label',
        'name' => 'Old Label',
        'protection_type' => 'none',
        'synced_at' => now()->subDay(),
    ]);

    fakeGraphForSensitivityLabels([makeGraphLabel()]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    expect(SensitivityLabel::count())->toBe(1);
    expect(SensitivityLabel::first()->label_id)->toBe('label-1');
});

test('syncLabels parses scope from contentFormats', function () {
    $label = makeGraphLabel([
        'contentFormats' => ['file', 'email'],
    ]);

    fakeGraphForSensitivityLabels([$label]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    $model = SensitivityLabel::first();
    expect($model->scope)->toBe(['files_emails']);
});

test('syncLabels detects protection type', function () {
    $noProtection = makeGraphLabel([
        'id' => 'label-none',
        'protectionSettings' => null,
    ]);
    $encrypted = makeGraphLabel([
        'id' => 'label-enc',
        'protectionSettings' => ['encryptionEnabled' => true],
    ]);

    fakeGraphForSensitivityLabels([$noProtection, $encrypted]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    expect(SensitivityLabel::where('label_id', 'label-none')->first()->protection_type)->toBe('none');
    expect(SensitivityLabel::where('label_id', 'label-enc')->first()->protection_type)->toBe('encryption');
});

test('buildPartnerMappings maps all partners when policy targets all users and guests', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'label-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);

    SensitivityLabelPolicy::create([
        'policy_id' => 'policy-1',
        'name' => 'Default Policy',
        'target_type' => 'all_users_and_guests',
        'labels' => ['label-1'],
        'synced_at' => now(),
    ]);

    PartnerOrganization::factory()->count(2)->create();

    $service = app(SensitivityLabelService::class);
    $service->buildPartnerMappings();

    expect($label->partners()->count())->toBe(2);
    expect($label->partners()->first()->pivot->matched_via)->toBe('label_policy');
    expect($label->partners()->first()->pivot->policy_name)->toBe('Default Policy');
});

test('buildPartnerMappings maps via site assignment when site has external sharing', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'label-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);

    SiteSensitivityLabel::create([
        'site_id' => 'site-1',
        'site_name' => 'Project Alpha',
        'site_url' => 'https://contoso.sharepoint.com/sites/alpha',
        'sensitivity_label_id' => $label->id,
        'external_sharing_enabled' => true,
        'synced_at' => now(),
    ]);

    PartnerOrganization::factory()->create();

    $service = app(SensitivityLabelService::class);
    $service->buildPartnerMappings();

    expect($label->partners()->count())->toBeGreaterThanOrEqual(1);
});

test('getUncoveredPartners returns partners with no sensitivity labels', function () {
    $covered = PartnerOrganization::factory()->create();
    $uncovered = PartnerOrganization::factory()->create();

    $label = SensitivityLabel::create([
        'label_id' => 'l1',
        'name' => 'Test',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    $label->partners()->attach($covered->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default',
    ]);

    $service = app(SensitivityLabelService::class);
    $result = $service->getUncoveredPartners();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($uncovered->id);
});

test('syncLabels falls back to CSV when Graph API fails', function () {
    // Create a CSV file with label data
    $csvContent = implode("\n", [
        '"Settings","LabelActions","DisplayName","ParentId","Tooltip","ContentType","Disabled","ImmutableId","Priority","Guid","Comment"',
        '"[color, #FF0000]","","Confidential","","Sensitive data","File, Email, Site, UnifiedGroup","False","csv-label-1","2","csv-label-1","From CSV"',
        '"[color, #0000FF]","","Internal Only","csv-label-1","Internal","File, Email","False","csv-label-2","3","csv-label-2",""',
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'labels_csv_test_');
    file_put_contents($tmpFile, $csvContent);
    config(['graph.labels_csv_path' => $tmpFile]);

    // Graph API fails
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels' => Http::response(
            ['error' => ['code' => 'Forbidden', 'message' => 'Insufficient privileges']],
            403
        ),
    ]);

    try {
        $service = app(SensitivityLabelService::class);
        $result = $service->syncLabels();

        expect($result['labels_synced'])->toBe(2);
        expect($result['source'])->toBe('csv');

        $label = SensitivityLabel::where('label_id', 'csv-label-1')->first();
        expect($label)->not->toBeNull();
        expect($label->name)->toBe('Confidential');
        expect($label->color)->toBe('#FF0000');
        expect($label->description)->toBe('From CSV');

        // Sub-label parent relationship
        $child = SensitivityLabel::where('label_id', 'csv-label-2')->first();
        expect($child)->not->toBeNull();
        expect($child->parent_label_id)->toBe($label->id);
    } finally {
        @unlink($tmpFile);
    }
});

test('syncLabels CSV source deletes stale labels', function () {
    SensitivityLabel::create([
        'label_id' => 'stale-label',
        'name' => 'Old Label',
        'protection_type' => 'none',
        'synced_at' => now()->subDay(),
    ]);

    $csvContent = implode("\n", [
        '"Settings","LabelActions","DisplayName","ParentId","Tooltip","ContentType","Disabled","ImmutableId","Priority","Guid","Comment"',
        '"[color, #FF0000]","","New Label","","","File, Email","False","new-label-1","0","new-label-1",""',
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'labels_csv_test_');
    file_put_contents($tmpFile, $csvContent);
    config(['graph.labels_csv_path' => $tmpFile]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels' => Http::response(
            ['error' => ['code' => 'Forbidden']],
            403
        ),
    ]);

    try {
        $service = app(SensitivityLabelService::class);
        $service->syncLabels();

        expect(SensitivityLabel::count())->toBe(1);
        expect(SensitivityLabel::first()->label_id)->toBe('new-label-1');
    } finally {
        @unlink($tmpFile);
    }
});

test('syncLabels gracefully handles Graph API failure without deleting existing labels', function () {
    // Pre-existing label from a previous sync
    SensitivityLabel::create([
        'label_id' => 'existing-label',
        'name' => 'Previously Synced',
        'protection_type' => 'encryption',
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels' => Http::response(
            ['error' => ['code' => 'ResourceNotFound', 'message' => 'Resource not found for the segment informationProtection']],
            404
        ),
    ]);

    $service = app(SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect($result['labels_synced'])->toBe(0);
    // Existing label should NOT be deleted
    expect(SensitivityLabel::count())->toBe(1);
    expect(SensitivityLabel::first()->label_id)->toBe('existing-label');
});

test('syncPolicies gracefully handles Graph API failure', function () {
    SensitivityLabelPolicy::create([
        'policy_id' => 'existing-policy',
        'name' => 'Previously Synced Policy',
        'target_type' => 'all_users',
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels/policies' => Http::response(
            ['error' => ['code' => 'ResourceNotFound']],
            404
        ),
    ]);

    $service = app(SensitivityLabelService::class);
    $result = $service->syncPolicies();

    expect($result)->toBe(0);
    // Existing policy should NOT be deleted
    expect(SensitivityLabelPolicy::count())->toBe(1);
});

test('syncSiteLabels auto-creates label stubs from group data when label not in DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        // Group root site (must be before groups* wildcard)
        'graph.microsoft.com/v1.0/groups/group-1/sites/root*' => Http::response([
            'id' => 'site-1',
            'webUrl' => 'https://contoso.sharepoint.com/sites/alpha',
        ]),
        // Groups endpoint — returns a group with an assigned label
        'graph.microsoft.com/v1.0/groups*' => Http::response([
            'value' => [
                [
                    'id' => 'group-1',
                    'displayName' => 'Project Alpha',
                    'assignedLabels' => [
                        ['labelId' => 'new-label-guid', 'displayName' => 'Highly Confidential'],
                    ],
                ],
            ],
        ]),
        // Per-site label (fallback, must be before sites* wildcard)
        'graph.microsoft.com/v1.0/sites/site-1/sensitivityLabel' => Http::response(
            ['error' => ['code' => 'ResourceNotFound']],
            404
        ),
        // Sites endpoint
        'graph.microsoft.com/v1.0/sites*' => Http::response([
            'value' => [
                [
                    'id' => 'site-1',
                    'displayName' => 'Project Alpha',
                    'name' => 'alpha',
                    'webUrl' => 'https://contoso.sharepoint.com/sites/alpha',
                ],
            ],
        ]),
    ]);

    config(['graph.sharepoint_tenant' => null]); // SPO Admin not configured

    $service = app(SensitivityLabelService::class);
    $count = $service->syncSiteLabels();

    expect($count)->toBe(1);

    // Label stub should have been auto-created
    $label = SensitivityLabel::where('label_id', 'new-label-guid')->first();
    expect($label)->not->toBeNull();
    expect($label->name)->toBe('Highly Confidential');
    expect($label->protection_type)->toBe('unknown');

    // Site label mapping should exist
    $siteLabel = SiteSensitivityLabel::where('site_id', 'site-1')->first();
    expect($siteLabel)->not->toBeNull();
    expect($siteLabel->sensitivity_label_id)->toBe($label->id);
});
