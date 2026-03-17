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

test('parseLabelsOutput converts PowerShell JSON to label array', function () {
    $psOutput = json_encode([
        [
            'ImmutableId' => '12345678-1234-1234-1234-123456789012',
            'DisplayName' => 'Confidential',
            'Name' => 'Confidential',
            'Comment' => 'Apply to confidential data',
            'Tooltip' => 'This content is confidential',
            'Priority' => 2,
            'Color' => '#FF0000',
            'ParentId' => null,
            'ContentType' => 'File, Email, Site, UnifiedGroup',
            'Disabled' => false,
            'LabelActions' => json_encode([
                ['Type' => 'encrypt', 'Settings' => []],
            ]),
        ],
        [
            'ImmutableId' => '87654321-1234-1234-1234-123456789012',
            'DisplayName' => 'Confidential - Internal',
            'Name' => 'Confidential/Internal',
            'Comment' => null,
            'Tooltip' => 'Internal only',
            'Priority' => 3,
            'Color' => '',
            'ParentId' => '12345678-1234-1234-1234-123456789012',
            'ContentType' => 'File, Email',
            'Disabled' => false,
            'LabelActions' => '[]',
        ],
    ]);

    $service = app(CompliancePowerShellService::class);
    $labels = $service->parseLabelsOutput($psOutput);

    expect($labels)->toHaveCount(2);

    expect($labels[0]['id'])->toBe('12345678-1234-1234-1234-123456789012');
    expect($labels[0]['name'])->toBe('Confidential');
    expect($labels[0]['description'])->toBe('Apply to confidential data');
    expect($labels[0]['color'])->toBe('#FF0000');
    expect($labels[0]['tooltip'])->toBe('This content is confidential');
    expect($labels[0]['priority'])->toBe(2);
    expect($labels[0]['isActive'])->toBeTrue();
    expect($labels[0]['parent'])->toBeNull();
    expect($labels[0]['contentFormats'])->toContain('file');
    expect($labels[0]['contentFormats'])->toContain('email');
    expect($labels[0]['contentFormats'])->toContain('site');
    expect($labels[0]['protectionSettings']['encryptionEnabled'])->toBeTrue();

    expect($labels[1]['parent']['id'])->toBe('12345678-1234-1234-1234-123456789012');
    expect($labels[1]['contentFormats'])->not->toContain('site');
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

test('parseCsvLabels parses Get-Label CSV export', function () {
    $csvContent = implode("\n", [
        '"Settings","LabelActions","DisplayName","ParentId","Tooltip","ContentType","Disabled","ImmutableId","Priority","Guid","Comment"',
        '"[color, #FF0000] [isparent, False]","","Confidential","","Apply to confidential content","File, Email, Site, UnifiedGroup","False","12345678-1234-1234-1234-123456789012","2","12345678-1234-1234-1234-123456789012","Confidential data"',
        '"[color, #0000FF] [isparent, False]","","Confidential - Internal","12345678-1234-1234-1234-123456789012","Internal only","File, Email","False","87654321-1234-1234-1234-123456789012","3","87654321-1234-1234-1234-123456789012",""',
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'labels_csv_test_');
    file_put_contents($tmpFile, $csvContent);

    try {
        $service = app(CompliancePowerShellService::class);
        $labels = $service->parseCsvLabels($tmpFile);

        expect($labels)->toHaveCount(2);

        expect($labels[0]['id'])->toBe('12345678-1234-1234-1234-123456789012');
        expect($labels[0]['name'])->toBe('Confidential');
        expect($labels[0]['description'])->toBe('Confidential data');
        expect($labels[0]['color'])->toBe('#FF0000');
        expect($labels[0]['tooltip'])->toBe('Apply to confidential content');
        expect($labels[0]['priority'])->toBe(2);
        expect($labels[0]['isActive'])->toBeTrue();
        expect($labels[0]['parent'])->toBeNull();
        expect($labels[0]['contentFormats'])->toContain('file');
        expect($labels[0]['contentFormats'])->toContain('email');
        expect($labels[0]['contentFormats'])->toContain('site');

        expect($labels[1]['id'])->toBe('87654321-1234-1234-1234-123456789012');
        expect($labels[1]['name'])->toBe('Confidential - Internal');
        expect($labels[1]['color'])->toBe('#0000FF');
        expect($labels[1]['parent']['id'])->toBe('12345678-1234-1234-1234-123456789012');
        expect($labels[1]['contentFormats'])->not->toContain('site');
    } finally {
        @unlink($tmpFile);
    }
});

test('parseCsvLabels handles disabled labels', function () {
    $csvContent = implode("\n", [
        '"Settings","LabelActions","DisplayName","ParentId","Tooltip","ContentType","Disabled","ImmutableId","Priority","Guid","Comment"',
        '"[color, #999999]","","Archived Label","","Old label","File","True","aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee","99","aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",""',
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'labels_csv_test_');
    file_put_contents($tmpFile, $csvContent);

    try {
        $service = app(CompliancePowerShellService::class);
        $labels = $service->parseCsvLabels($tmpFile);

        expect($labels)->toHaveCount(1);
        expect($labels[0]['isActive'])->toBeFalse();
    } finally {
        @unlink($tmpFile);
    }
});

test('parseCsvLabels throws on empty CSV', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'labels_csv_test_');
    file_put_contents($tmpFile, '');

    try {
        $service = app(CompliancePowerShellService::class);
        $service->parseCsvLabels($tmpFile);
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('no headers');
    } finally {
        @unlink($tmpFile);
    }
});

test('getCsvLabelsPath returns null when not configured', function () {
    config(['graph.labels_csv_path' => null]);

    $service = app(CompliancePowerShellService::class);

    expect($service->getCsvLabelsPath())->toBeNull();
});

test('getCsvLabelsPath returns null when file does not exist', function () {
    config(['graph.labels_csv_path' => '/nonexistent/path/labels.csv']);

    $service = app(CompliancePowerShellService::class);

    expect($service->getCsvLabelsPath())->toBeNull();
});

test('getCsvLabelsPath returns path when file exists', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'labels_csv_test_');
    config(['graph.labels_csv_path' => $tmpFile]);

    try {
        $service = app(CompliancePowerShellService::class);

        expect($service->getCsvLabelsPath())->toBe($tmpFile);
    } finally {
        @unlink($tmpFile);
    }
});

test('parseLabelsOutput handles single label (non-array) PowerShell output', function () {
    $psOutput = json_encode([
        'ImmutableId' => '12345678-1234-1234-1234-123456789012',
        'DisplayName' => 'Public',
        'Name' => 'Public',
        'Comment' => null,
        'Tooltip' => 'Public data',
        'Priority' => 0,
        'Color' => '',
        'ParentId' => null,
        'ContentType' => 'File, Email',
        'Disabled' => false,
        'LabelActions' => '[]',
    ]);

    $service = app(CompliancePowerShellService::class);
    $labels = $service->parseLabelsOutput($psOutput);

    expect($labels)->toHaveCount(1);
    expect($labels[0]['name'])->toBe('Public');
});
