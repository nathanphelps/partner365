<?php

use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actingAs($this->user);
});

test('index page renders with labels', function () {
    SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'scope' => ['files_emails', 'sites_groups'],
        'is_active' => true,
        'priority' => 2,
        'synced_at' => now(),
    ]);

    $response = $this->get('/sensitivity-labels');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sensitivity-labels/Index')
        ->has('labels.data', 1)
        ->where('labels.data.0.name', 'Confidential')
        ->has('uncoveredPartnerCount')
    );
});

test('index shows uncovered partner count', function () {
    PartnerOrganization::factory()->count(3)->create();

    $label = SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Test',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    $partner = PartnerOrganization::first();
    $label->partners()->attach($partner->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default',
    ]);

    $response = $this->get('/sensitivity-labels');

    $response->assertInertia(fn ($page) => $page
        ->where('uncoveredPartnerCount', 2)
    );
});

test('show page renders with label and partners', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'scope' => ['files_emails'],
        'synced_at' => now(),
    ]);
    $partner = PartnerOrganization::factory()->create();
    $label->partners()->attach($partner->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default Policy',
    ]);

    $response = $this->get("/sensitivity-labels/{$label->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sensitivity-labels/Show')
        ->where('label.name', 'Confidential')
        ->has('label.partners', 1)
    );
});

test('show page includes sub-labels', function () {
    $parent = SensitivityLabel::create([
        'label_id' => 'parent-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    SensitivityLabel::create([
        'label_id' => 'child-1',
        'name' => 'Confidential - Internal',
        'protection_type' => 'encryption',
        'parent_label_id' => $parent->id,
        'synced_at' => now(),
    ]);

    $response = $this->get("/sensitivity-labels/{$parent->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('label.children', 1)
    );
});

test('partner show page includes sensitivity labels', function () {
    $partner = PartnerOrganization::factory()->create();
    $label = SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Secret',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    $label->partners()->attach($partner->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default',
    ]);

    $response = $this->get("/partners/{$partner->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('sensitivityLabels', 1)
    );
});

test('viewer role can access sensitivity labels index', function () {
    $viewer = User::factory()->create(['role' => 'viewer', 'approved_at' => now()]);
    $this->actingAs($viewer);

    $response = $this->get('/sensitivity-labels');

    $response->assertOk();
});
