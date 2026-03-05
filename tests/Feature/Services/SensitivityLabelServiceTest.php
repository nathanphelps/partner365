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
    ]);

    Cache::forget('msgraph_access_token');
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
