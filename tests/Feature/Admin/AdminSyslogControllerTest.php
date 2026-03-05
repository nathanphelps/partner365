<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view syslog settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.syslog.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Syslog'));
});

test('non-admin cannot view syslog settings', function () {
    $user = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.syslog.edit'))
        ->assertForbidden();
});

test('admin can save syslog settings', function () {
    $this->actingAs($this->admin)->put(route('admin.syslog.update'), [
        'enabled' => true,
        'host' => '10.0.0.1',
        'port' => 514,
        'transport' => 'tcp',
        'facility' => 16,
    ])->assertRedirect();

    expect(Setting::get('syslog', 'enabled'))->toBe('true');
    expect(Setting::get('syslog', 'host'))->toBe('10.0.0.1');
    expect(Setting::get('syslog', 'port'))->toBe('514');
    expect(Setting::get('syslog', 'transport'))->toBe('tcp');
});

test('syslog settings require host when enabled', function () {
    $this->actingAs($this->admin)->put(route('admin.syslog.update'), [
        'enabled' => true,
        'host' => '',
        'port' => 514,
        'transport' => 'udp',
        'facility' => 16,
    ])->assertSessionHasErrors('host');
});

test('syslog settings validate port range', function () {
    $this->actingAs($this->admin)->put(route('admin.syslog.update'), [
        'enabled' => false,
        'host' => '10.0.0.1',
        'port' => 70000,
        'transport' => 'udp',
        'facility' => 16,
    ])->assertSessionHasErrors('port');
});

test('syslog settings validate transport protocol', function () {
    $this->actingAs($this->admin)->put(route('admin.syslog.update'), [
        'enabled' => false,
        'host' => '10.0.0.1',
        'port' => 514,
        'transport' => 'invalid',
        'facility' => 16,
    ])->assertSessionHasErrors('transport');
});

test('test connection endpoint returns result', function () {
    Setting::set('syslog', 'host', '127.0.0.1');
    Setting::set('syslog', 'port', '51499');
    Setting::set('syslog', 'transport', 'udp');

    $this->actingAs($this->admin)
        ->post(route('admin.syslog.test'))
        ->assertOk()
        ->assertJsonStructure(['success', 'message']);
});
