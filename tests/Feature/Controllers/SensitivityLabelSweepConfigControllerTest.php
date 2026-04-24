<?php

use App\Enums\UserRole;
use App\Models\LabelRule;
use App\Models\SensitivityLabel;
use App\Models\Setting;
use App\Models\SiteExclusion;
use App\Models\User;
use App\Services\BridgeClient;
use App\Services\DTOs\BridgeHealth;

function adminUser(): User
{
    return User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
}

function operatorUser(): User
{
    return User::factory()->create(['role' => UserRole::Operator, 'approved_at' => now(), 'email_verified_at' => now()]);
}

function viewerUser(): User
{
    return User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]);
}

beforeEach(function () {
    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('health')->andReturn(new BridgeHealth('ok', 'commercial', 'abc'))->byDefault();
    $this->app->instance(BridgeClient::class, $mock);
});

test('admin can view sweep config page', function () {
    SensitivityLabel::create(['label_id' => 'l1', 'name' => 'Confidential', 'protection_type' => 'none']);

    $this->actingAs(adminUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertInertia(fn ($p) => $p->component('sensitivity-labels/Sweep/Config')
            ->has('labels')
            ->has('bridgeHealth')
        );
});

test('operator forbidden from sweep config page', function () {
    $this->actingAs(operatorUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertForbidden();
});

test('viewer forbidden from sweep config page', function () {
    $this->actingAs(viewerUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertForbidden();
});

test('update saves settings, rules, exclusions', function () {
    $payload = [
        'enabled' => true,
        'interval_minutes' => 120,
        'default_label_id' => 'default-lbl',
        'bridge_url' => 'http://bridge:8080',
        'bridge_shared_secret' => 'secret',
        'rules' => [
            ['prefix' => 'EXT', 'label_id' => 'ext-lbl', 'priority' => 3],
            ['prefix' => 'INT', 'label_id' => 'int-lbl', 'priority' => 1],
        ],
        'exclusions' => [
            ['pattern' => '/sites/contentTypeHub'],
            ['pattern' => '/sites/Archive'],
        ],
    ];

    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), $payload)
        ->assertRedirect();

    expect((bool) Setting::get('sensitivity_sweep', 'enabled'))->toBeTrue();
    expect((int) Setting::get('sensitivity_sweep', 'interval_minutes'))->toBe(120);
    expect((string) Setting::get('sensitivity_sweep', 'bridge_shared_secret'))->toBe('secret');

    $rules = LabelRule::orderBy('priority')->get();
    expect($rules)->toHaveCount(2);
    expect($rules[0]->priority)->toBe(1);
    expect($rules[0]->prefix)->toBe('INT');
    expect($rules[1]->priority)->toBe(2);
    expect($rules[1]->prefix)->toBe('EXT');

    expect(SiteExclusion::count())->toBe(2);
});

test('update with empty secret keeps existing secret', function () {
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'old-secret', encrypted: true);

    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), [
            'enabled' => true,
            'interval_minutes' => 60,
            'default_label_id' => 'x',
            'bridge_url' => 'http://bridge:8080',
            'bridge_shared_secret' => '',
            'rules' => [],
            'exclusions' => [],
        ])
        ->assertRedirect();

    // Existing encrypted secret is preserved on empty-string submit.
    expect((string) Setting::get('sensitivity_sweep', 'bridge_shared_secret'))->toBe('old-secret');
});

test('show page masks the shared secret', function () {
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'top-secret-value', encrypted: true);

    $this->actingAs(adminUser())
        ->get(route('sensitivity-labels.sweep.config'))
        ->assertInertia(fn ($p) => $p->component('sensitivity-labels/Sweep/Config')
            ->where('settings.bridge_shared_secret_configured', true)
            ->missing('settings.bridge_shared_secret')
        );
});

test('update rejects empty rule prefix', function () {
    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), [
            'enabled' => true,
            'interval_minutes' => 90,
            'default_label_id' => 'x',
            'bridge_url' => 'http://bridge:8080',
            'bridge_shared_secret' => '',
            'rules' => [['prefix' => '', 'label_id' => 'a', 'priority' => 1]],
            'exclusions' => [],
        ])
        ->assertSessionHasErrors('rules.0.prefix');
});

test('update rejects empty exclusion pattern', function () {
    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), [
            'enabled' => true,
            'interval_minutes' => 90,
            'default_label_id' => 'x',
            'bridge_url' => 'http://bridge:8080',
            'bridge_shared_secret' => '',
            'rules' => [],
            'exclusions' => [['pattern' => '']],
        ])
        ->assertSessionHasErrors('exclusions.0.pattern');
});

test('update replaces existing rules and exclusions', function () {
    LabelRule::create(['prefix' => 'OLD', 'label_id' => 'stale', 'priority' => 1]);
    SiteExclusion::create(['pattern' => '/sites/oldPattern']);

    $this->actingAs(adminUser())
        ->put(route('sensitivity-labels.sweep.config.update'), [
            'enabled' => true,
            'interval_minutes' => 60,
            'default_label_id' => 'x',
            'bridge_url' => 'http://bridge:8080',
            'bridge_shared_secret' => '',
            'rules' => [['prefix' => 'NEW', 'label_id' => 'new-lbl', 'priority' => 1]],
            'exclusions' => [['pattern' => '/sites/newPattern']],
        ])
        ->assertRedirect();

    expect(LabelRule::count())->toBe(1);
    expect(LabelRule::first()->prefix)->toBe('NEW');
    expect(SiteExclusion::count())->toBe(1);
    expect(SiteExclusion::first()->pattern)->toBe('/sites/newPattern');
});
