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
     * @return array{score: int, breakdown: array<string, array{label: string, passed: bool, points: int, max_points: int}>}|null
     */
    public function calculateScore(PartnerOrganization $partner): ?array
    {
        if (empty($partner->domain)) {
            return null;
        }

        $breakdown = [];

        // --- DNS Hygiene (60 points) ---

        $dmarcRecord = $this->dns->getDmarcRecord($partner->domain);
        $breakdown['dmarc_present'] = [
            'label' => 'DMARC record present',
            'passed' => $dmarcRecord !== null,
            'points' => $dmarcRecord !== null ? 15 : 0,
            'max_points' => 15,
        ];

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

        $spfRecord = $this->dns->getSpfRecord($partner->domain);
        $breakdown['spf_present'] = [
            'label' => 'SPF record present',
            'passed' => $spfRecord !== null,
            'points' => $spfRecord !== null ? 15 : 0,
            'max_points' => 15,
        ];

        $dkimRecord = $this->dns->getDkimRecord($partner->domain);
        $breakdown['dkim_present'] = [
            'label' => 'DKIM record discoverable',
            'passed' => $dkimRecord !== null,
            'points' => $dkimRecord !== null ? 5 : 0,
            'max_points' => 5,
        ];

        $dnssec = $this->dns->hasDnssec($partner->domain);
        $breakdown['dnssec_enabled'] = [
            'label' => 'DNSSEC enabled',
            'passed' => $dnssec,
            'points' => $dnssec ? 10 : 0,
            'max_points' => 10,
        ];

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

        // Always true here (early return at line 19 guards empty domain), but kept as a
        // baseline signal so the breakdown always shows this criterion with its 15 points.
        $breakdown['verified_domain'] = [
            'label' => 'Partner has known domain',
            'passed' => true,
            'points' => 15,
            'max_points' => 15,
        ];

        $breakdown['multiple_domains'] = [
            'label' => 'Tenant has multiple verified domains',
            'passed' => false,
            'points' => 0,
            'max_points' => 5,
        ];

        $breakdown['mfa_trust'] = [
            'label' => 'MFA trust enabled',
            'passed' => (bool) $partner->mfa_trust_enabled,
            'points' => $partner->mfa_trust_enabled ? 10 : 0,
            'max_points' => 10,
        ];

        $tenantAge1yr = $partner->created_at->diffInYears(now()) >= 1;
        $breakdown['tenant_age_1yr'] = [
            'label' => 'Partner relationship >= 1 year',
            'passed' => $tenantAge1yr,
            'points' => $tenantAge1yr ? 5 : 0,
            'max_points' => 5,
        ];

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

    public function storeScore(PartnerOrganization $partner, array $result): void
    {
        $partner->updateTrustScore($result['score'], $result['breakdown']);
    }

    private function lookupDomainAge(string $domain): ?Carbon
    {
        try {
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
