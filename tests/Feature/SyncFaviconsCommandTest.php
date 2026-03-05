<?php

use App\Models\PartnerOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('sync:favicons fetches favicons for partners missing them', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>'),
        'https://example.com/icon.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => null]);

    $this->artisan('sync:favicons')->assertExitCode(0);

    expect(PartnerOrganization::first()->favicon_path)->not->toBeNull();
});

test('sync:favicons skips partners that already have a favicon', function () {
    Storage::disk('public')->put('favicons/1.png', 'existing');

    PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => 'favicons/1.png']);

    $this->artisan('sync:favicons')->assertExitCode(0);

    Http::assertNothingSent();
});

test('sync:favicons --force re-fetches all', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>'),
        'https://example.com/icon.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    Storage::disk('public')->put('favicons/1.png', 'old-bytes');
    PartnerOrganization::factory()->create(['domain' => 'example.com', 'favicon_path' => 'favicons/1.png']);

    $this->artisan('sync:favicons --force')->assertExitCode(0);

    Http::assertSentCount(2);
});
