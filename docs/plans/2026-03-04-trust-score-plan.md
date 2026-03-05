# Partner Trust Score Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a composite trust score (0-100) to partner organizations based on DNS hygiene and Entra ID metadata, displayed on the list and detail pages.

**Architecture:** A new `TrustScoreService` performs DNS and RDAP lookups, calculates a weighted score, and stores results on the `partner_organizations` table. A daily artisan command `score:partners` triggers recalculation. The frontend displays the score as a color-coded badge on the list and a breakdown card on the detail page.

**Tech Stack:** Laravel 12, PHP `dns_get_record()`, RDAP via HTTP, Vue 3 + shadcn-vue, Pest PHP tests

---

### Task 1: Migration — add trust score columns

**Files:**
- Create: `database/migrations/2026_03_04_500001_add_trust_score_to_partner_organizations.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_trust_score_to_partner_organizations --table=partner_organizations
```

Then replace the contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->unsignedTinyInteger('trust_score')->nullable()->after('last_synced_at');
            $table->json('trust_score_breakdown')->nullable()->after('trust_score');
            $table->timestamp('trust_score_calculated_at')->nullable()->after('trust_score_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn(['trust_score', 'trust_score_breakdown', 'trust_score_calculated_at']);
        });
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

**Step 3: Update the model**

Modify `app/Models/PartnerOrganization.php`:

Add to `$fillable` array:
```php
'trust_score', 'trust_score_breakdown', 'trust_score_calculated_at',
```

Add to `casts()` return array:
```php
'trust_score_breakdown' => 'array',
'trust_score_calculated_at' => 'datetime',
```

**Step 4: Commit**

```bash
git add database/migrations/*trust_score* app/Models/PartnerOrganization.php
git commit -m "feat: add trust score columns to partner_organizations table"
```

---

### Task 2: DnsLookupService — testable wrapper around dns_get_record

**Files:**
- Create: `app/Services/DnsLookupService.php`
- Create: `tests/Feature/DnsLookupServiceTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/DnsLookupServiceTest.php`:

```php
<?php

use App\Services\DnsLookupService;

test('getDmarcRecord returns DMARC TXT record', function () {
    $service = new DnsLookupService;

    // We'll test against a well-known domain that has DMARC
    // For unit testing, we mock via constructor injection
    $mockService = Mockery::mock(DnsLookupService::class)->makePartial();
    $mockService->shouldReceive('txtRecords')
        ->with('_dmarc.example.com')
        ->andReturn(['v=DMARC1; p=reject; rua=mailto:dmarc@example.com']);

    $result = $mockService->getDmarcRecord('example.com');

    expect($result)->toContain('v=DMARC1');
    expect($result)->toContain('p=reject');
});

test('getSpfRecord returns SPF TXT record', function () {
    $mockService = Mockery::mock(DnsLookupService::class)->makePartial();
    $mockService->shouldReceive('txtRecords')
        ->with('example.com')
        ->andReturn(['v=spf1 include:_spf.google.com ~all', 'some-other-txt-record']);

    $result = $mockService->getSpfRecord('example.com');

    expect($result)->toContain('v=spf1');
});

test('getDkimRecord returns DKIM TXT record for common selectors', function () {
    $mockService = Mockery::mock(DnsLookupService::class)->makePartial();
    $mockService->shouldReceive('txtRecords')
        ->with('selector1._domainkey.example.com')
        ->andReturn(['v=DKIM1; k=rsa; p=MIGf...']);

    $result = $mockService->getDkimRecord('example.com');

    expect($result)->toContain('v=DKIM1');
});

test('getDkimRecord returns null when no selector found', function () {
    $mockService = Mockery::mock(DnsLookupService::class)->makePartial();
    $mockService->shouldReceive('txtRecords')->andReturn([]);

    $result = $mockService->getDkimRecord('example.com');

    expect($result)->toBeNull();
});

test('hasDnssec returns boolean', function () {
    $mockService = Mockery::mock(DnsLookupService::class)->makePartial();
    $mockService->shouldReceive('queryDnssec')
        ->with('example.com')
        ->andReturn(true);

    expect($mockService->hasDnssec('example.com'))->toBeTrue();
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=DnsLookupServiceTest
```

Expected: FAIL — class does not exist.

**Step 3: Write the implementation**

Create `app/Services/DnsLookupService.php`:

```php
<?php

namespace App\Services;

class DnsLookupService
{
    private const DKIM_SELECTORS = [
        'selector1', 'selector2',  // Microsoft 365
        'google', 's1', 's2',      // Google Workspace
        'default', 'k1',           // Mailchimp / generic
    ];

    public function getDmarcRecord(string $domain): ?string
    {
        $records = $this->txtRecords("_dmarc.{$domain}");

        foreach ($records as $record) {
            if (stripos($record, 'v=DMARC1') !== false) {
                return $record;
            }
        }

        return null;
    }

    public function getSpfRecord(string $domain): ?string
    {
        $records = $this->txtRecords($domain);

        foreach ($records as $record) {
            if (stripos($record, 'v=spf1') !== false) {
                return $record;
            }
        }

        return null;
    }

    public function getDkimRecord(string $domain): ?string
    {
        foreach (self::DKIM_SELECTORS as $selector) {
            $records = $this->txtRecords("{$selector}._domainkey.{$domain}");

            foreach ($records as $record) {
                if (stripos($record, 'DKIM1') !== false || stripos($record, 'k=rsa') !== false) {
                    return $record;
                }
            }
        }

        return null;
    }

    public function hasDnssec(string $domain): bool
    {
        return $this->queryDnssec($domain);
    }

    /**
     * Returns all TXT record strings for a given hostname.
     * Extracted for mockability in tests.
     */
    public function txtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_map(fn (array $r) => $r['txt'] ?? '', $records);
    }

    /**
     * Check DNSSEC by querying for DNSKEY records.
     * Extracted for mockability in tests.
     */
    public function queryDnssec(string $domain): bool
    {
        $records = @dns_get_record($domain, DNS_ANY);

        if ($records === false) {
            return false;
        }

        // Check if any RRSIG records exist (indicates DNSSEC signing)
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'RRSIG') {
                return true;
            }
        }

        return false;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=DnsLookupServiceTest
```

Expected: PASS (all 5 tests).

**Step 5: Commit**

```bash
git add app/Services/DnsLookupService.php tests/Feature/DnsLookupServiceTest.php
git commit -m "feat: add DnsLookupService with testable DNS query methods"
```

---

### Task 3: TrustScoreService — score calculation logic

**Files:**
- Create: `app/Services/TrustScoreService.php`
- Create: `tests/Feature/TrustScoreServiceTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/TrustScoreServiceTest.php`:

```php
<?php

use App\Models\PartnerOrganization;
use App\Services\DnsLookupService;
use App\Services\TrustScoreService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'rdap.org/*' => Http::response([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2018-01-15T00:00:00Z'],
            ],
        ]),
    ]);
});

test('perfect score with all signals passing', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn('v=DMARC1; p=reject; rua=mailto:d@example.com');
    $dns->shouldReceive('getSpfRecord')->andReturn('v=spf1 include:_spf.google.com ~all');
    $dns->shouldReceive('getDkimRecord')->andReturn('v=DKIM1; k=rsa; p=MIGf...');
    $dns->shouldReceive('hasDnssec')->andReturn(true);

    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => true,
    ]);

    $result = $service->calculateScore($partner);

    // DNS: DMARC present (15) + DMARC reject (5) + SPF (15) + DKIM (5) + DNSSEC (10) + age>=2yr (5) + age>=5yr (5) = 60
    // Entra: verified domain(15) + mfa trust(10) + tenant age>=1yr(5) + tenant age>=3yr(5) = 35
    // Missing: multiple verified domains (5)
    // Total: 95
    expect($result['score'])->toBe(95);
    expect($result['breakdown'])->toBeArray();
    expect($result['breakdown'])->toHaveKey('dmarc_present');
    expect($result['breakdown']['dmarc_present']['passed'])->toBeTrue();
    expect($result['breakdown']['dmarc_present']['points'])->toBe(15);
});

test('zero score with no signals passing', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn(null);
    $dns->shouldReceive('getSpfRecord')->andReturn(null);
    $dns->shouldReceive('getDkimRecord')->andReturn(null);
    $dns->shouldReceive('hasDnssec')->andReturn(false);

    Http::fake([
        'rdap.org/*' => Http::response([], 404),
    ]);

    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'sketchy.xyz',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['score'])->toBe(0);
});

test('dmarc quarantine policy gets bonus points', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn('v=DMARC1; p=quarantine');
    $dns->shouldReceive('getSpfRecord')->andReturn(null);
    $dns->shouldReceive('getDkimRecord')->andReturn(null);
    $dns->shouldReceive('hasDnssec')->andReturn(false);

    Http::fake([
        'rdap.org/*' => Http::response([], 404),
    ]);

    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['breakdown']['dmarc_present']['passed'])->toBeTrue();
    expect($result['breakdown']['dmarc_enforced']['passed'])->toBeTrue();
    // DMARC present (15) + DMARC enforced (5) = 20
    expect($result['score'])->toBe(20);
});

test('dmarc none policy does not get bonus points', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn('v=DMARC1; p=none');
    $dns->shouldReceive('getSpfRecord')->andReturn(null);
    $dns->shouldReceive('getDkimRecord')->andReturn(null);
    $dns->shouldReceive('hasDnssec')->andReturn(false);

    Http::fake([
        'rdap.org/*' => Http::response([], 404),
    ]);

    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['breakdown']['dmarc_present']['passed'])->toBeTrue();
    expect($result['breakdown']['dmarc_enforced']['passed'])->toBeFalse();
    // DMARC present (15) only
    expect($result['score'])->toBe(15);
});

test('returns null score for partner without domain', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create(['domain' => null]);

    $result = $service->calculateScore($partner);

    expect($result)->toBeNull();
});

test('domain age scoring uses RDAP registration date', function () {
    $dns = Mockery::mock(DnsLookupService::class);
    $dns->shouldReceive('getDmarcRecord')->andReturn(null);
    $dns->shouldReceive('getSpfRecord')->andReturn(null);
    $dns->shouldReceive('getDkimRecord')->andReturn(null);
    $dns->shouldReceive('hasDnssec')->andReturn(false);

    // Domain registered 3 years ago (>= 2 years but < 5 years)
    Http::fake([
        'rdap.org/*' => Http::response([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => now()->subYears(3)->toIso8601String()],
            ],
        ]),
    ]);

    $service = new TrustScoreService($dns);

    $partner = PartnerOrganization::factory()->create([
        'domain' => 'example.com',
        'mfa_trust_enabled' => false,
    ]);

    $result = $service->calculateScore($partner);

    expect($result['breakdown']['domain_age_2yr']['passed'])->toBeTrue();
    expect($result['breakdown']['domain_age_5yr']['passed'])->toBeFalse();
    // domain age >= 2yr (5) only
    expect($result['score'])->toBe(5);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=TrustScoreServiceTest
```

Expected: FAIL — class does not exist.

**Step 3: Write the implementation**

Create `app/Services/TrustScoreService.php`:

```php
<?php

namespace App\Services;

use App\Models\PartnerOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrustScoreService
{
    public function __construct(private DnsLookupService $dns) {}

    /**
     * Calculate trust score for a partner. Returns null if partner has no domain.
     *
     * @return array{score: int, breakdown: array<string, array{label: string, passed: bool, points: int, max_points: int}>}|null
     */
    public function calculateScore(PartnerOrganization $partner): ?array
    {
        if (empty($partner->domain)) {
            return null;
        }

        $breakdown = [];

        // --- DNS Hygiene (60 points) ---

        // DMARC present (15 points)
        $dmarcRecord = $this->dns->getDmarcRecord($partner->domain);
        $breakdown['dmarc_present'] = [
            'label' => 'DMARC record present',
            'passed' => $dmarcRecord !== null,
            'points' => $dmarcRecord !== null ? 15 : 0,
            'max_points' => 15,
        ];

        // DMARC enforced — reject or quarantine (5 points)
        $dmarcEnforced = false;
        if ($dmarcRecord !== null) {
            $dmarcEnforced = (bool) preg_match('/p\s*=\s*(reject|quarantine)/i', $dmarcRecord);
        }
        $breakdown['dmarc_enforced'] = [
            'label' => 'DMARC policy enforced (reject/quarantine)',
            'passed' => $dmarcEnforced,
            'points' => $dmarcEnforced ? 5 : 0,
            'max_points' => 5,
        ];

        // SPF present (15 points)
        $spfRecord = $this->dns->getSpfRecord($partner->domain);
        $breakdown['spf_present'] = [
            'label' => 'SPF record present',
            'passed' => $spfRecord !== null,
            'points' => $spfRecord !== null ? 15 : 0,
            'max_points' => 15,
        ];

        // DKIM discoverable (5 points)
        $dkimRecord = $this->dns->getDkimRecord($partner->domain);
        $breakdown['dkim_present'] = [
            'label' => 'DKIM record discoverable',
            'passed' => $dkimRecord !== null,
            'points' => $dkimRecord !== null ? 5 : 0,
            'max_points' => 5,
        ];

        // DNSSEC enabled (10 points)
        $dnssec = $this->dns->hasDnssec($partner->domain);
        $breakdown['dnssec_enabled'] = [
            'label' => 'DNSSEC enabled',
            'passed' => $dnssec,
            'points' => $dnssec ? 10 : 0,
            'max_points' => 10,
        ];

        // Domain age (5 + 5 points)
        $registrationDate = $this->lookupDomainAge($partner->domain);
        $domainAge2yr = $registrationDate !== null && $registrationDate->diffInYears(now()) >= 2;
        $domainAge5yr = $registrationDate !== null && $registrationDate->diffInYears(now()) >= 5;

        $breakdown['domain_age_2yr'] = [
            'label' => 'Domain age >= 2 years',
            'passed' => $domainAge2yr,
            'points' => $domainAge2yr ? 5 : 0,
            'max_points' => 5,
        ];

        $breakdown['domain_age_5yr'] = [
            'label' => 'Domain age >= 5 years',
            'passed' => $domainAge5yr,
            'points' => $domainAge5yr ? 5 : 0,
            'max_points' => 5,
        ];

        // --- Entra ID / Graph Metadata (40 points) ---

        // Verified domain (15 points) — partner has a domain, which came from Graph's defaultDomainName
        $hasVerifiedDomain = ! empty($partner->domain);
        $breakdown['verified_domain'] = [
            'label' => 'Tenant has verified domain',
            'passed' => $hasVerifiedDomain,
            'points' => $hasVerifiedDomain ? 15 : 0,
            'max_points' => 15,
        ];

        // Multiple verified domains (5 points) — check raw_policy_json for additional signals
        // For now, this defaults to false since we only store defaultDomainName
        $hasMultipleDomains = false;
        $breakdown['multiple_domains'] = [
            'label' => 'Tenant has multiple verified domains',
            'passed' => $hasMultipleDomains,
            'points' => $hasMultipleDomains ? 5 : 0,
            'max_points' => 5,
        ];

        // MFA trust reciprocated (10 points)
        $breakdown['mfa_trust'] = [
            'label' => 'MFA trust enabled',
            'passed' => (bool) $partner->mfa_trust_enabled,
            'points' => $partner->mfa_trust_enabled ? 10 : 0,
            'max_points' => 10,
        ];

        // Partner tenant age >= 1 year (5 points)
        $tenantAge1yr = $partner->created_at->diffInYears(now()) >= 1;
        $breakdown['tenant_age_1yr'] = [
            'label' => 'Partner relationship >= 1 year',
            'passed' => $tenantAge1yr,
            'points' => $tenantAge1yr ? 5 : 0,
            'max_points' => 5,
        ];

        // Partner tenant age >= 3 years (5 points)
        $tenantAge3yr = $partner->created_at->diffInYears(now()) >= 3;
        $breakdown['tenant_age_3yr'] = [
            'label' => 'Partner relationship >= 3 years',
            'passed' => $tenantAge3yr,
            'points' => $tenantAge3yr ? 5 : 0,
            'max_points' => 5,
        ];

        $score = array_sum(array_column($breakdown, 'points'));

        return [
            'score' => $score,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Store calculated trust score on the partner model.
     */
    public function storeScore(PartnerOrganization $partner, array $result): void
    {
        $partner->update([
            'trust_score' => $result['score'],
            'trust_score_breakdown' => $result['breakdown'],
            'trust_score_calculated_at' => now(),
        ]);
    }

    private function lookupDomainAge(string $domain): ?Carbon
    {
        try {
            // Extract the registrable domain (e.g., "sub.example.com" -> "example.com")
            $parts = explode('.', $domain);
            $registrableDomain = count($parts) > 2
                ? implode('.', array_slice($parts, -2))
                : $domain;

            $response = Http::timeout(5)->get("https://rdap.org/domain/{$registrableDomain}");

            if ($response->failed()) {
                return null;
            }

            $events = $response->json('events', []);

            foreach ($events as $event) {
                if (($event['eventAction'] ?? '') === 'registration') {
                    return Carbon::parse($event['eventDate']);
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning("RDAP lookup failed for {$domain}: {$e->getMessage()}");

            return null;
        }
    }
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=TrustScoreServiceTest
```

Expected: PASS (all 6 tests).

**Step 5: Commit**

```bash
git add app/Services/TrustScoreService.php tests/Feature/TrustScoreServiceTest.php
git commit -m "feat: add TrustScoreService with DNS and Entra ID scoring"
```

---

### Task 4: ScorePartners artisan command

**Files:**
- Create: `app/Console/Commands/ScorePartners.php`
- Create: `tests/Feature/ScorePartnersCommandTest.php`
- Modify: `routes/console.php` (add daily schedule)

**Step 1: Write the failing test**

Create `tests/Feature/ScorePartnersCommandTest.php`:

```php
<?php

use App\Models\PartnerOrganization;
use App\Services\DnsLookupService;
use App\Services\TrustScoreService;
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
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=ScorePartnersCommandTest
```

Expected: FAIL — command not found.

**Step 3: Write the command**

Create `app/Console/Commands/ScorePartners.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Services\TrustScoreService;
use Illuminate\Console\Command;

class ScorePartners extends Command
{
    protected $signature = 'score:partners';

    protected $description = 'Calculate trust scores for all partner organizations';

    public function handle(TrustScoreService $scoreService): int
    {
        $partners = PartnerOrganization::whereNotNull('domain')->get();
        $scored = 0;
        $failed = 0;

        foreach ($partners as $partner) {
            try {
                $result = $scoreService->calculateScore($partner);

                if ($result !== null) {
                    $scoreService->storeScore($partner, $result);
                    $scored++;
                    $this->line("{$partner->display_name}: {$result['score']}/100");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Failed to score {$partner->display_name}: {$e->getMessage()}");
            }
        }

        $this->info("Scored {$scored} partner(s)." . ($failed > 0 ? " Failed: {$failed}." : ''));

        return Command::SUCCESS;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=ScorePartnersCommandTest
```

Expected: PASS (both tests).

**Step 5: Add to scheduler**

Modify `routes/console.php` — add after the existing Schedule lines (line 21):

```php
Schedule::command('score:partners')->daily();
```

**Step 6: Commit**

```bash
git add app/Console/Commands/ScorePartners.php tests/Feature/ScorePartnersCommandTest.php routes/console.php
git commit -m "feat: add score:partners command with daily schedule"
```

---

### Task 5: Seed trust scores in DevSeeder

**Files:**
- Modify: `database/seeders/DevSeeder.php`

**Step 1: Add trust score seeding to seedPartners method**

In `database/seeders/DevSeeder.php`, modify the `seedPartners` method. After the `PartnerOrganization::factory()->create(...)` call (around line 110), add trust score data directly in the create array:

Add these fields to the `PartnerOrganization::factory()->create([...])` call, after `'notes'`:

```php
'trust_score' => fake()->optional(0.8)->numberBetween(15, 98),
'trust_score_breakdown' => null, // will be set below
'trust_score_calculated_at' => fake()->optional(0.8)->dateTimeBetween('-3 days', 'now'),
```

Then, after the `$partner = PartnerOrganization::factory()->create(...)` block, add logic to generate a realistic breakdown if the partner got a score:

```php
if ($partner->trust_score !== null) {
    $score = $partner->trust_score;
    $breakdown = $this->buildTrustScoreBreakdown($score, $policyProfile);
    $partner->update(['trust_score_breakdown' => $breakdown]);
}
```

**Step 2: Add the buildTrustScoreBreakdown helper method**

Add this private method to `DevSeeder`:

```php
private function buildTrustScoreBreakdown(int $targetScore, string $policyProfile): array
{
    $signals = [
        'dmarc_present' => ['label' => 'DMARC record present', 'max' => 15],
        'dmarc_enforced' => ['label' => 'DMARC policy enforced (reject/quarantine)', 'max' => 5],
        'spf_present' => ['label' => 'SPF record present', 'max' => 15],
        'dkim_present' => ['label' => 'DKIM record discoverable', 'max' => 5],
        'dnssec_enabled' => ['label' => 'DNSSEC enabled', 'max' => 10],
        'domain_age_2yr' => ['label' => 'Domain age >= 2 years', 'max' => 5],
        'domain_age_5yr' => ['label' => 'Domain age >= 5 years', 'max' => 5],
        'verified_domain' => ['label' => 'Tenant has verified domain', 'max' => 15],
        'multiple_domains' => ['label' => 'Tenant has multiple verified domains', 'max' => 5],
        'mfa_trust' => ['label' => 'MFA trust enabled', 'max' => 10],
        'tenant_age_1yr' => ['label' => 'Partner relationship >= 1 year', 'max' => 5],
        'tenant_age_3yr' => ['label' => 'Partner relationship >= 3 years', 'max' => 5],
    ];

    // Distribute points to roughly match the target score
    $remaining = $targetScore;
    $breakdown = [];

    foreach ($signals as $key => $signal) {
        $pass = $remaining > 0 && fake()->boolean($policyProfile === 'permissive' ? 75 : 50);
        $points = $pass ? min($signal['max'], $remaining) : 0;
        $remaining -= $points;

        $breakdown[$key] = [
            'label' => $signal['label'],
            'passed' => $pass,
            'points' => $points,
            'max_points' => $signal['max'],
        ];
    }

    return $breakdown;
}
```

**Step 3: Run the seeder to verify**

```bash
php artisan db:seed --class=DevSeeder
```

Expected: No errors. Partners should have trust scores populated.

**Step 4: Commit**

```bash
git add database/seeders/DevSeeder.php
git commit -m "feat: seed trust score data in DevSeeder"
```

---

### Task 6: TypeScript types update

**Files:**
- Modify: `resources/js/types/partner.ts`

**Step 1: Update the PartnerOrganization type**

Add these three fields to the `PartnerOrganization` type in `resources/js/types/partner.ts`, after the `last_synced_at` field:

```typescript
trust_score: number | null;
trust_score_breakdown: Record<string, {
    label: string;
    passed: boolean;
    points: number;
    max_points: number;
}> | null;
trust_score_calculated_at: string | null;
```

**Step 2: Run type check**

```bash
npm run types:check
```

Expected: PASS.

**Step 3: Commit**

```bash
git add resources/js/types/partner.ts
git commit -m "feat: add trust score fields to PartnerOrganization TypeScript type"
```

---

### Task 7: TrustScoreBadge component

**Files:**
- Create: `resources/js/components/TrustScoreBadge.vue`

**Step 1: Create the component**

Create `resources/js/components/TrustScoreBadge.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    score: number | null;
}>();

const tier = computed(() => {
    if (props.score === null) return null;
    if (props.score >= 80) return { label: 'High', color: 'text-green-700 bg-green-100 dark:text-green-400 dark:bg-green-950' };
    if (props.score >= 50) return { label: 'Medium', color: 'text-amber-700 bg-amber-100 dark:text-amber-400 dark:bg-amber-950' };
    return { label: 'Low', color: 'text-red-700 bg-red-100 dark:text-red-400 dark:bg-red-950' };
});
</script>

<template>
    <span v-if="score !== null" class="inline-flex items-center gap-1.5">
        <span
            class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
            :class="tier?.color"
        >
            {{ score }}
        </span>
    </span>
    <span v-else class="text-sm text-muted-foreground">—</span>
</template>
```

**Step 2: Run type check and lint**

```bash
npm run types:check && npm run lint
```

Expected: PASS.

**Step 3: Commit**

```bash
git add resources/js/components/TrustScoreBadge.vue
git commit -m "feat: add TrustScoreBadge component with color-coded tiers"
```

---

### Task 8: Partners list page — add Trust Score column

**Files:**
- Modify: `resources/js/pages/partners/Index.vue`

**Step 1: Add the Trust Score column**

In `resources/js/pages/partners/Index.vue`:

1. Add import at the top of `<script setup>`:
```typescript
import TrustScoreBadge from '@/components/TrustScoreBadge.vue';
```

2. Add a new `<th>` after the "Domain" column header (after the `</th>` on ~line 128):
```html
<th class="px-4 py-3 text-left font-medium text-muted-foreground">
    Trust Score
</th>
```

3. Add a new `<td>` after the domain data cell (after `</td>` on ~line 171):
```html
<td class="px-4 py-3">
    <TrustScoreBadge :score="partner.trust_score" />
</td>
```

4. Update the empty-state colspan from `7` to `8`.

**Step 2: Run type check and lint**

```bash
npm run types:check && npm run lint
```

Expected: PASS.

**Step 3: Commit**

```bash
git add resources/js/pages/partners/Index.vue
git commit -m "feat: add trust score column to partners list page"
```

---

### Task 9: Partner detail page — add Trust Score card

**Files:**
- Modify: `resources/js/pages/partners/Show.vue`

**Step 1: Add the Trust Score breakdown card**

In `resources/js/pages/partners/Show.vue`:

1. Add import for `TrustScoreBadge` at the top of `<script setup>`:
```typescript
import TrustScoreBadge from '@/components/TrustScoreBadge.vue';
```

2. Add a `formatRelativeDate` helper in `<script setup>`:
```typescript
function formatRelativeDate(val: string | null): string {
    if (!val) return 'Never';
    const d = new Date(val);
    const diff = Date.now() - d.getTime();
    const hours = Math.floor(diff / 3_600_000);
    if (hours < 1) return 'Less than an hour ago';
    if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    const days = Math.floor(hours / 24);
    return `${days} day${days > 1 ? 's' : ''} ago`;
}
```

3. Add a Trust Score card in the template. Insert it as a new `<Card>` inside the grid (after the "Direct Connect" card, before the "Tenant Restrictions" card):

```html
<!-- Trust Score -->
<Card>
    <CardHeader>
        <CardTitle class="flex items-center gap-2">
            Trust Score
            <TrustScoreBadge :score="partner.trust_score" />
        </CardTitle>
    </CardHeader>
    <CardContent>
        <template v-if="partner.trust_score_breakdown">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="pb-2 text-left font-medium text-muted-foreground">Signal</th>
                        <th class="pb-2 text-center font-medium text-muted-foreground">Status</th>
                        <th class="pb-2 text-right font-medium text-muted-foreground">Points</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(signal, key) in partner.trust_score_breakdown"
                        :key="key"
                        class="border-b last:border-0"
                    >
                        <td class="py-2">{{ signal.label }}</td>
                        <td class="py-2 text-center">
                            <span
                                :class="signal.passed
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-red-500 dark:text-red-400'"
                            >
                                {{ signal.passed ? 'Pass' : 'Fail' }}
                            </span>
                        </td>
                        <td class="py-2 text-right text-muted-foreground">
                            {{ signal.points }}/{{ signal.max_points }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 text-xs text-muted-foreground">
                Last calculated: {{ formatRelativeDate(partner.trust_score_calculated_at) }}
            </p>
        </template>
        <p v-else class="text-sm text-muted-foreground">
            Trust score has not been calculated yet. Run <code>score:partners</code> to generate.
        </p>
    </CardContent>
</Card>
```

**Step 2: Run type check and lint**

```bash
npm run types:check && npm run lint
```

Expected: PASS.

**Step 3: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: add trust score breakdown card to partner detail page"
```

---

### Task 10: Full test suite verification

**Step 1: Run all backend tests**

```bash
php artisan test
```

Expected: All tests PASS.

**Step 2: Run full CI check**

```bash
composer run ci:check
```

Expected: All checks PASS (lint, format, types, tests).

**Step 3: Final commit (if any lint/format fixes needed)**

```bash
git add -A
git commit -m "chore: fix lint and formatting for trust score feature"
```
