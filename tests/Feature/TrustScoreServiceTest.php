<?php

use App\Models\PartnerOrganization;
use App\Services\DnsLookupService;
use App\Services\TrustScoreService;
use Illuminate\Support\Facades\Http;

function makeDnsMock(
    ?string $dmarc = null,
    ?string $spf = null,
    ?string $dkim = null,
    bool $dnssec = false,
): DnsLookupService {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn($dmarc);
    $dns->shouldReceive('getSpfRecord')->andReturn($spf);
    $dns->shouldReceive('getDkimRecord')->andReturn($dkim);
    $dns->shouldReceive('hasDnssec')->andReturn($dnssec);

    return $dns;
}

test('high score with all DNS signals passing', function () {
    Http::fake([
        'rdap.org/*' => Http::response([
            'events' => [['eventAction' => 'registration', 'eventDate' => '2018-01-15T00:00:00Z']],
        ]),
    ]);

    $dns = makeDnsMock(
        dmarc: 'v=DMARC1; p=reject; rua=mailto:d@example.com',
        spf: 'v=spf1 include:_spf.google.com ~all',
        dkim: 'v=DKIM1; k=rsa; p=MIGf...',
        dnssec: true,
    );

    $service = new TrustScoreService($dns);
    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => true,
    ]);

    $result = $service->calculateScore($partner);

    // DNS: 15+5+15+5+10+5+5=60, Entra: 15+0+10+0+0=25 (new partner, no age)
    expect($result['score'])->toBe(85);
    expect($result['breakdown']['dmarc_present']['passed'])->toBeTrue();
    expect($result['breakdown']['dmarc_present']['points'])->toBe(15);
});

test('minimal score with no DNS signals and no RDAP', function () {
    Http::fake([
        'rdap.org/*' => Http::response([], 404),
    ]);

    $dns = makeDnsMock();
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'sketchy.xyz',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    // Only verified_domain (15)
    expect($result['score'])->toBe(15);
});

test('dmarc quarantine policy gets bonus points', function () {
    Http::fake([
        'rdap.org/*' => Http::response([], 404),
    ]);

    $dns = makeDnsMock(dmarc: 'v=DMARC1; p=quarantine');
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['breakdown']['dmarc_present']['passed'])->toBeTrue();
    expect($result['breakdown']['dmarc_enforced']['passed'])->toBeTrue();
    // DMARC present(15) + enforced(5) + verified_domain(15) = 35
    expect($result['score'])->toBe(35);
});

test('dmarc none policy does not get bonus points', function () {
    Http::fake([
        'rdap.org/*' => Http::response([], 404),
    ]);

    $dns = makeDnsMock(dmarc: 'v=DMARC1; p=none');
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['breakdown']['dmarc_present']['passed'])->toBeTrue();
    expect($result['breakdown']['dmarc_enforced']['passed'])->toBeFalse();
    // DMARC present(15) + verified_domain(15) = 30
    expect($result['score'])->toBe(30);
});

test('returns null score for partner without domain', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create(['domain' => null]);

    expect($service->calculateScore($partner))->toBeNull();
});

test('domain age scoring uses RDAP registration date', function () {
    Http::fake([
        'rdap.org/*' => Http::response([
            'events' => [['eventAction' => 'registration', 'eventDate' => now()->subYears(3)->toIso8601String()]],
        ]),
    ]);

    $dns = makeDnsMock();
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['breakdown']['domain_age_2yr']['passed'])->toBeTrue();
    expect($result['breakdown']['domain_age_5yr']['passed'])->toBeFalse();
    // domain_age_2yr(5) + verified_domain(15) = 20
    expect($result['score'])->toBe(20);
});

test('storeScore persists score to database', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create();

    $result = [
        'score' => 75,
        'breakdown' => ['dmarc_present' => ['label' => 'DMARC', 'passed' => true, 'points' => 15, 'max_points' => 15]],
    ];

    $service->storeScore($partner, $result);

    $partner->refresh();
    expect($partner->trust_score)->toBe(75);
    expect($partner->trust_score_breakdown)->toBeArray();
    expect($partner->trust_score_calculated_at)->not->toBeNull();
});
