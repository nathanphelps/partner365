<?php

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Models\User;
use App\Services\BridgeClient;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeUnavailableException;

function makeSiteRow(): SharePointSite
{
    return SharePointSite::create([
        'site_id' => 'sid1',
        'display_name' => 'Test',
        'url' => 'https://a/sites/Test',
        'sensitivity_label_id' => null,
        'synced_at' => now(),
    ]);
}

test('admin can apply label to a site', function () {
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')
        ->with('https://a/sites/Test', 'lbl', true)
        ->andReturn(new SetLabelResult('https://a/sites/Test', 'lbl', false));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect();

    expect($site->fresh()->sensitivity_label_id)->toBe($label->id);
    expect(ActivityLog::where('action', ActivityAction::LabelApplied->value)->count())->toBe(1);
});

test('operator can apply label', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andReturn(new SetLabelResult('https://a/sites/Test', 'lbl', false));
    $this->app->instance(BridgeClient::class, $mock);

    $op = User::factory()->create(['role' => UserRole::Operator, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($op)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect();
});

test('viewer cannot apply label', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $viewer = User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($viewer)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertForbidden();
});

test('bridge auth exception surfaces friendly flash', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeAuthException('401'));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('refresh label calls bridge read and updates DB', function () {
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->with('https://a/sites/Test')->andReturn('lbl');
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.refresh-label', $site))
        ->assertRedirect();

    expect($site->fresh()->sensitivity_label_id)->toBe($label->id);
});

test('refresh with bridge unavailable flashes error', function () {
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->andThrow(new BridgeUnavailableException('down'));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.refresh-label', $site))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('refresh with bridge returning null clears the label locally', function () {
    $label = SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = SharePointSite::create([
        'site_id' => 'sid2',
        'display_name' => 'Test',
        'url' => 'https://a/sites/Test',
        'sensitivity_label_id' => $label->id,
        'synced_at' => now(),
    ]);

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->andReturn(null);
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.refresh-label', $site))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($site->fresh()->sensitivity_label_id)->toBeNull();
});

test('refresh with unknown GUID keeps local state and flashes warning', function () {
    $label = SensitivityLabel::create(['label_id' => 'known', 'name' => 'Known', 'protection_type' => 'none']);
    $site = SharePointSite::create([
        'site_id' => 'sid3',
        'display_name' => 'Test',
        'url' => 'https://a/sites/Test',
        'sensitivity_label_id' => $label->id,
        'synced_at' => now(),
    ]);

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->andReturn('orphan-guid-not-in-catalog');
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.refresh-label', $site))
        ->assertRedirect()
        ->assertSessionHas('warning');

    // Local state NOT overwritten — user would otherwise think the label was deleted.
    expect($site->fresh()->sensitivity_label_id)->toBe($label->id);
});

test('refresh emits LabelRefreshed audit log', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('readLabel')->andReturn('lbl');
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)->post(route('sharepoint-sites.refresh-label', $site));

    expect(ActivityLog::where('action', ActivityAction::LabelRefreshed->value)->count())->toBe(1);
});

test('apply flashes throttle friendly message', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new \App\Services\Exceptions\BridgeThrottleException('429'));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect();

    expect(session('error'))->toContain('rate-limiting');
});

test('apply flashes unavailable friendly message', function () {
    SensitivityLabel::create(['label_id' => 'lbl', 'name' => 'Conf', 'protection_type' => 'none']);
    $site = makeSiteRow();

    $mock = mock(BridgeClient::class);
    $mock->shouldReceive('setLabel')->andThrow(new BridgeUnavailableException('down'));
    $this->app->instance(BridgeClient::class, $mock);

    $admin = User::factory()->create(['role' => UserRole::Admin, 'approved_at' => now(), 'email_verified_at' => now()]);
    $this->actingAs($admin)
        ->post(route('sharepoint-sites.apply-label', $site), ['label_id' => 'lbl'])
        ->assertRedirect();

    expect(session('error'))->toContain('not reachable');
});
