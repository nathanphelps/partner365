<?php

use App\Services\CompliancePowerShellService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.cloud_environment' => 'commercial',
        'graph.compliance_certificate_path' => '/path/to/cert.pfx',
        'graph.compliance_certificate_password' => 'test-password',
    ]);
});

test('isAvailable returns false when certificate path is not configured', function () {
    config(['graph.compliance_certificate_path' => null]);

    $service = app(CompliancePowerShellService::class);

    expect($service->isAvailable())->toBeFalse();
});

test('isAvailable returns false when pwsh is not found', function () {
    Process::fake([
        '*pwsh*' => Process::result(output: '', exitCode: 1),
    ]);

    $service = app(CompliancePowerShellService::class);

    expect($service->isAvailable())->toBeFalse();
});

test('isAvailable returns true when pwsh exists and certificate configured', function () {
    Process::fake([
        '*pwsh*' => Process::result(output: '/usr/bin/pwsh', exitCode: 0),
    ]);

    $service = app(CompliancePowerShellService::class);

    expect($service->isAvailable())->toBeTrue();
});

test('parsePoliciesOutput converts PowerShell JSON to policy array', function () {
    $psOutput = json_encode([
        [
            'ImmutableId' => 'policy-1',
            'Name' => 'Global Policy',
            'Labels' => [
                ['ImmutableId' => 'label-1', 'DisplayName' => 'Confidential'],
            ],
            'Settings' => json_encode([
                ['Key' => 'requiredowngradejustification', 'Value' => 'true'],
            ]),
        ],
    ]);

    $service = app(CompliancePowerShellService::class);
    $policies = $service->parsePoliciesOutput($psOutput);

    expect($policies)->toHaveCount(1);
    expect($policies[0]['id'])->toBe('policy-1');
    expect($policies[0]['name'])->toBe('Global Policy');
});
