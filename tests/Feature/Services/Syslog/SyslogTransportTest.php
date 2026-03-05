<?php

use App\Services\Syslog\SyslogTransport;

test('it creates a transport from config', function () {
    $transport = new SyslogTransport('127.0.0.1', 514, 'udp', 16);

    expect($transport->host())->toBe('127.0.0.1');
    expect($transport->port())->toBe(514);
    expect($transport->protocol())->toBe('udp');
    expect($transport->facility())->toBe(16);
});

test('it formats syslog priority correctly', function () {
    $transport = new SyslogTransport('127.0.0.1', 514, 'udp', 16);

    // facility 16 (local0) * 8 + severity 5 (notice) = 133
    $message = $transport->buildSyslogMessage('test message', 5);
    expect($message)->toStartWith('<133>');
    expect($message)->toContain('test message');
});

test('it validates configuration', function () {
    expect(SyslogTransport::validateConfig('127.0.0.1', 514, 'udp'))->toBeTrue();
    expect(SyslogTransport::validateConfig('', 514, 'udp'))->toBeFalse();
    expect(SyslogTransport::validateConfig('127.0.0.1', 0, 'udp'))->toBeFalse();
    expect(SyslogTransport::validateConfig('127.0.0.1', 514, 'invalid'))->toBeFalse();
});
