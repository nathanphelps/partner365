<?php

use App\Models\PartnerOrganization;
use App\Services\DnsLookupService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'rdap.org/*' => Http::response([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z'],
            ],
        ]),
    ]);
});

test('score:partners calculates scores for partners with domains', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn('v=DMARC1; p=reject');
    $dns->shouldReceive('getSpfRecord')->andReturn('v=spf1 ~all');
    $dns->shouldReceive('getDkimRecord')->andReturn(null);
    $dns->shouldReceive('hasDnssec')->andReturn(false);

    app()->instance(DnsLookupService::class, $dns);

    $withDomain = PartnerOrganization::factory()->create(['domain' => 'example.com']);
    $withoutDomain = PartnerOrganization::factory()->create(['domain' => null]);

    $this->artisan('score:partners')
        ->expectsOutputToContain('Scored 1 partner')
        ->assertExitCode(0);

    expect($withDomain->fresh()->trust_score)->not->toBeNull();
    expect($withoutDomain->fresh()->trust_score)->toBeNull();
});

test('score:partners handles errors gracefully', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andThrow(new \RuntimeException('DNS timeout'));

    app()->instance(DnsLookupService::class, $dns);

    PartnerOrganization::factory()->create(['domain' => 'example.com']);

    $this->artisan('score:partners')
        ->expectsOutputToContain('Failed')
        ->assertExitCode(0);
});
