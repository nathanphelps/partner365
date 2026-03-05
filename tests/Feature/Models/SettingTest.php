<?php

use App\Models\Setting;

test('Setting::set stores a value', function () {
    Setting::set('graph', 'tenant_id', 'abc-123');

    $this->assertDatabaseHas('settings', [
        'group' => 'graph',
        'key' => 'tenant_id',
        'encrypted' => false,
    ]);
});

test('Setting::get retrieves a stored value', function () {
    Setting::set('graph', 'tenant_id', 'abc-123');

    expect(Setting::get('graph', 'tenant_id'))->toBe('abc-123');
});

test('Setting::get returns fallback when no value exists', function () {
    expect(Setting::get('graph', 'tenant_id', 'fallback-value'))->toBe('fallback-value');
});

test('Setting::set upserts on duplicate group+key', function () {
    Setting::set('graph', 'tenant_id', 'first');
    Setting::set('graph', 'tenant_id', 'second');

    expect(Setting::where('group', 'graph')->where('key', 'tenant_id')->count())->toBe(1);
    expect(Setting::get('graph', 'tenant_id'))->toBe('second');
});

test('Setting::set encrypts value when encrypted flag is true', function () {
    Setting::set('graph', 'client_secret', 'super-secret', encrypted: true);

    $raw = Setting::where('group', 'graph')->where('key', 'client_secret')->first();
    expect($raw->encrypted)->toBeTrue();
    expect($raw->getRawOriginal('value'))->not->toBe('super-secret');
});

test('Setting::get decrypts encrypted values', function () {
    Setting::set('graph', 'client_secret', 'super-secret', encrypted: true);

    expect(Setting::get('graph', 'client_secret'))->toBe('super-secret');
});

test('Setting::getGroup returns all values for a group', function () {
    Setting::set('graph', 'tenant_id', 'tid');
    Setting::set('graph', 'client_id', 'cid');
    Setting::set('sync', 'interval', '15');

    $group = Setting::getGroup('graph');
    expect($group)->toHaveKey('tenant_id', 'tid');
    expect($group)->toHaveKey('client_id', 'cid');
    expect($group)->not->toHaveKey('interval');
});
