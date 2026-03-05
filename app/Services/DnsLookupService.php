<?php

namespace App\Services;

class DnsLookupService
{
    private const DKIM_SELECTORS = [
        'selector1', 'selector2',
        'google', 's1', 's2',
        'default', 'k1',
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

    public function txtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_map(fn (array $r) => $r['txt'] ?? '', $records);
    }

    public function queryDnssec(string $domain): bool
    {
        $records = @dns_get_record($domain, DNS_ANY);

        if ($records === false) {
            return false;
        }

        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'RRSIG') {
                return true;
            }
        }

        return false;
    }
}
