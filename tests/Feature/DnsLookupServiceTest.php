<?php

use App\Services\DnsLookupService;

test('getDmarcRecord returns DMARC TXT record', function () {
    $service = Mockery::mock(DnsLookupService::class)->makePartial();
    $service->shouldReceive('txtRecords')
        ->with('_dmarc.example.com')
        ->andReturn(['v=DMARC1; p=reject; rua=mailto:dmarc@example.com']);

    $result = $service->getDmarcRecord('example.com');

    expect($result)->toContain('v=DMARC1');
    expect($result)->toContain('p=reject');
});

test('getDmarcRecord returns null when no DMARC record', function () {
    $service = Mockery::mock(DnsLookupService::class)->makePartial();
    $service->shouldReceive('txtRecords')
        ->with('_dmarc.example.com')
        ->andReturn([]);

    expect($service->getDmarcRecord('example.com'))->toBeNull();
});

test('getSpfRecord returns SPF TXT record', function () {
    $service = Mockery::mock(DnsLookupService::class)->makePartial();
    $service->shouldReceive('txtRecords')
        ->with('example.com')
        ->andReturn(['v=spf1 include:_spf.google.com ~all', 'some-other-txt']);

    $result = $service->getSpfRecord('example.com');

    expect($result)->toContain('v=spf1');
});

test('getDkimRecord returns DKIM TXT record for common selectors', function () {
    $service = Mockery::mock(DnsLookupService::class)->makePartial();
    $service->shouldReceive('txtRecords')
        ->with('selector1._domainkey.example.com')
        ->andReturn(['v=DKIM1; k=rsa; p=MIGf...']);

    $result = $service->getDkimRecord('example.com');

    expect($result)->toContain('v=DKIM1');
});

test('getDkimRecord returns null when no selector found', function () {
    $service = Mockery::mock(DnsLookupService::class)->makePartial();
    $service->shouldReceive('txtRecords')->andReturn([]);

    expect($service->getDkimRecord('example.com'))->toBeNull();
});

test('hasDnssec delegates to queryDnssec', function () {
    $service = Mockery::mock(DnsLookupService::class)->makePartial();
    $service->shouldReceive('queryDnssec')
        ->with('example.com')
        ->andReturn(true);

    expect($service->hasDnssec('example.com'))->toBeTrue();
});
