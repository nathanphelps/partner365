<?php

use App\Models\PartnerOrganization;
use App\Services\FaviconService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('it parses favicon from link rel icon tag', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/assets/logo.png"></head></html>'),
        'https://example.com/assets/logo.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it falls back to favicon.ico when no link tag', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><title>Hi</title></head></html>'),
        'https://example.com/favicon.ico' => Http::response('fake-ico-bytes', 200, ['Content-Type' => 'image/x-icon']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it handles failed fetch gracefully', function () {
    Http::fake([
        'https://unreachable.test' => Http::response('', 500),
        'https://unreachable.test/favicon.ico' => Http::response('', 500),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'unreachable.test', 'favicon_path' => null]);
    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->toBeNull();
});

test('it skips partners without domain', function () {
    $partner = PartnerOrganization::factory()->create(['domain' => null, 'favicon_path' => null]);
    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->toBeNull();
    Http::assertNothingSent();
});

test('it resolves relative icon href to absolute URL', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" type="image/png" href="favicon-32x32.png"></head></html>'),
        'https://example.com/favicon-32x32.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it prefers larger icon when multiple link tags exist', function () {
    $html = '<html><head>
        <link rel="icon" href="/icon-16.png" sizes="16x16">
        <link rel="icon" href="/icon-32.png" sizes="32x32">
        <link rel="icon" href="/icon-64.png" sizes="64x64">
    </head></html>';

    Http::fake([
        'https://example.com' => Http::response($html),
        'https://example.com/icon-64.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);
    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    expect($partner->favicon_path)->toContain('favicons/');
    Storage::disk('public')->assertExists($partner->favicon_path);
});

test('it deletes old favicon when re-fetching', function () {
    Storage::disk('public')->put('favicons/old.png', 'old-bytes');

    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/new-icon.png"></head></html>'),
        'https://example.com/new-icon.png' => Http::response('new-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'favicon_path' => 'favicons/old.png',
    ]);

    app(FaviconService::class)->fetchForPartner($partner);

    $partner->refresh();
    Storage::disk('public')->assertMissing('favicons/old.png');
    Storage::disk('public')->assertExists($partner->favicon_path);
});
