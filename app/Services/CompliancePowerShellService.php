<?php

namespace App\Services;

use App\Enums\CloudEnvironment;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CompliancePowerShellService
{
    public function isAvailable(): bool
    {
        $certPath = Setting::get('graph', 'compliance_certificate_path', config('graph.compliance_certificate_path'));

        if (empty($certPath)) {
            return false;
        }

        $result = Process::run($this->findPwshCommand());

        return $result->successful();
    }

    public function getLabels(): array
    {
        $output = $this->runPowerShell('Get-Label -IncludeDetailedLabelActions | ConvertTo-Json -Depth 5 -Compress');

        return $this->parseLabelsOutput($output);
    }

    public function getCsvLabelsPath(): ?string
    {
        $path = Setting::get('graph', 'labels_csv_path', config('graph.labels_csv_path'));

        if (empty($path) || ! file_exists($path)) {
            return null;
        }

        return $path;
    }

    public function parseCsvLabels(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$csvPath}");
        }

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');

            if ($headers === false || empty($headers)) {
                throw new \RuntimeException("CSV file has no headers: {$csvPath}");
            }

            $labels = [];

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($row) !== count($headers)) {
                    continue;
                }

                $item = array_combine($headers, $row);
                $labels[] = $this->mapCsvRow($item);
            }

            return $labels;
        } finally {
            fclose($handle);
        }
    }

    private function mapCsvRow(array $item): array
    {
        $contentFormats = $this->parseContentType($item['ContentType'] ?? '');
        $protectionSettings = $this->parseLabelActions($item['LabelActions'] ?? '');
        $color = $this->extractSettingsValue($item['Settings'] ?? '', 'color');

        return [
            'id' => $item['ImmutableId'] ?? $item['Guid'] ?? '',
            'name' => $item['DisplayName'] ?? '',
            'description' => ! empty($item['Comment']) ? $item['Comment'] : null,
            'color' => $color,
            'tooltip' => ! empty($item['Tooltip']) ? $item['Tooltip'] : null,
            'priority' => (int) ($item['Priority'] ?? 0),
            'isActive' => strtolower($item['Disabled'] ?? 'False') !== 'true',
            'parent' => ! empty($item['ParentId']) ? ['id' => $item['ParentId']] : null,
            'contentFormats' => $contentFormats,
            'protectionSettings' => $protectionSettings,
        ];
    }

    private function extractSettingsValue(string $settings, string $key): ?string
    {
        if (preg_match('/\['.preg_quote($key, '/').',\s*([^\]]+)\]/', $settings, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function getPolicies(): array
    {
        $output = $this->runPowerShell('Get-LabelPolicy | ConvertTo-Json -Depth 5 -Compress');

        return $this->parsePoliciesOutput($output);
    }

    public function parseLabelsOutput(string $json): array
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed === 'null') {
            return [];
        }

        $data = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse PowerShell label output as JSON', [
                'json_error' => json_last_error_msg(),
                'output_preview' => substr($trimmed, 0, 500),
            ]);

            throw new \RuntimeException('PowerShell label output was not valid JSON: '.json_last_error_msg());
        }

        if (! is_array($data)) {
            return [];
        }

        // PowerShell returns a single object (not array) when there's only one label
        if (isset($data['ImmutableId'])) {
            $data = [$data];
        }

        return array_map(fn (array $item) => $this->mapLabel($item), $data);
    }

    public function parsePoliciesOutput(string $json): array
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed === 'null') {
            return [];
        }

        $data = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse PowerShell policy output as JSON', [
                'json_error' => json_last_error_msg(),
                'output_preview' => substr($trimmed, 0, 500),
            ]);

            throw new \RuntimeException('PowerShell policy output was not valid JSON: '.json_last_error_msg());
        }

        if (! is_array($data)) {
            return [];
        }

        if (isset($data['ImmutableId'])) {
            $data = [$data];
        }

        return array_map(fn (array $item) => $this->mapPolicy($item), $data);
    }

    private function mapLabel(array $item): array
    {
        $contentFormats = $this->parseContentType($item['ContentType'] ?? '');
        $protectionSettings = $this->parseLabelActions($item['LabelActions'] ?? '[]');

        return [
            'id' => $item['ImmutableId'],
            'name' => $item['DisplayName'],
            'description' => $item['Comment'] ?? null,
            'color' => ! empty($item['Color']) ? $item['Color'] : null,
            'tooltip' => $item['Tooltip'] ?? null,
            'priority' => $item['Priority'] ?? 0,
            'isActive' => ! ($item['Disabled'] ?? false),
            'parent' => ! empty($item['ParentId']) ? ['id' => $item['ParentId']] : null,
            'contentFormats' => $contentFormats,
            'protectionSettings' => $protectionSettings,
        ];
    }

    private function mapPolicy(array $item): array
    {
        $labelIds = [];
        foreach (($item['Labels'] ?? []) as $label) {
            if (! empty($label['ImmutableId'])) {
                $labelIds[] = $label['ImmutableId'];
            }
        }

        return [
            'id' => $item['ImmutableId'] ?? $item['Guid'] ?? '',
            'name' => $item['Name'],
            'settings' => ['labels' => array_map(fn ($id) => ['labelId' => $id], $labelIds)],
            'scopes' => $item['Scopes'] ?? [],
        ];
    }

    private function parseContentType(string $contentType): array
    {
        $formats = [];
        $lower = strtolower($contentType);

        if (str_contains($lower, 'file')) {
            $formats[] = 'file';
        }
        if (str_contains($lower, 'email')) {
            $formats[] = 'email';
        }
        if (str_contains($lower, 'site')) {
            $formats[] = 'site';
        }
        if (str_contains($lower, 'unifiedgroup') || str_contains($lower, 'group')) {
            $formats[] = 'group';
        }

        return $formats;
    }

    private function parseLabelActions(string $actionsJson): array
    {
        $actions = is_string($actionsJson) ? json_decode($actionsJson, true) : $actionsJson;

        if (! is_array($actions)) {
            return [];
        }

        $hasEncryption = false;
        $hasWatermark = false;
        $hasHeader = false;
        $hasFooter = false;

        foreach ($actions as $action) {
            $type = strtolower($action['Type'] ?? '');
            if (str_contains($type, 'encrypt')) {
                $hasEncryption = true;
            }
            if (str_contains($type, 'watermark')) {
                $hasWatermark = true;
            }
            if (str_contains($type, 'header')) {
                $hasHeader = true;
            }
            if (str_contains($type, 'footer')) {
                $hasFooter = true;
            }
        }

        return [
            'encryptionEnabled' => $hasEncryption,
            'watermarkEnabled' => $hasWatermark,
            'headerEnabled' => $hasHeader,
            'footerEnabled' => $hasFooter,
        ];
    }

    private function runPowerShell(string $command): string
    {
        $cloudEnv = CloudEnvironment::tryFrom(
            Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
        ) ?? CloudEnvironment::Commercial;

        $connectionUri = match ($cloudEnv) {
            CloudEnvironment::GccHigh => 'https://ps.compliance.protection.office365.us/powershell-liveid/',
            CloudEnvironment::Commercial => 'https://ps.compliance.protection.office365.com/powershell-liveid/',
        };

        $azureAdUri = match ($cloudEnv) {
            CloudEnvironment::GccHigh => 'https://login.microsoftonline.us/organizations',
            CloudEnvironment::Commercial => 'https://login.microsoftonline.com/organizations',
        };

        $tenantSlug = Setting::get('graph', 'sharepoint_tenant', config('graph.sharepoint_tenant'));
        $orgDomain = match ($cloudEnv) {
            CloudEnvironment::GccHigh => "{$tenantSlug}.onmicrosoft.us",
            CloudEnvironment::Commercial => "{$tenantSlug}.onmicrosoft.com",
        };

        $certPath = Setting::get('graph', 'compliance_certificate_path', config('graph.compliance_certificate_path'));
        $certPassword = Setting::get('graph', 'compliance_certificate_password', config('graph.compliance_certificate_password'));
        $clientId = Setting::get('graph', 'client_id', config('graph.client_id'));

        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
Import-Module ExchangeOnlineManagement
$certPassword = ConvertTo-SecureString -String $env:PS_CERT_PASSWORD -AsPlainText -Force
Connect-IPPSSession -AppId $env:PS_CLIENT_ID -CertificateFilePath $env:PS_CERT_PATH -CertificatePassword $certPassword -Organization $env:PS_ORGANIZATION -ConnectionUri $env:PS_CONNECTION_URI -AzureADAuthorizationEndpointUri $env:PS_AZURE_AD_URI
PS;

        // Append the actual command after the connection setup
        $fullScript = $script."\n".$command."\nDisconnect-ExchangeOnline -Confirm:\$false";

        $result = Process::env([
            'PS_CERT_PASSWORD' => $certPassword,
            'PS_CLIENT_ID' => $clientId,
            'PS_CERT_PATH' => $certPath,
            'PS_ORGANIZATION' => $orgDomain,
            'PS_CONNECTION_URI' => $connectionUri,
            'PS_AZURE_AD_URI' => $azureAdUri,
        ])->timeout(120)->run(['pwsh', '-NoProfile', '-NonInteractive', '-Command', $fullScript]);

        if ($result->failed()) {
            Log::error('PowerShell compliance command failed', [
                'exitCode' => $result->exitCode(),
                'error' => $result->errorOutput(),
            ]);

            throw new \RuntimeException('PowerShell compliance command failed: '.$result->errorOutput());
        }

        return $result->output();
    }

    private function findPwshCommand(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['where', 'pwsh'];
        }

        return ['which', 'pwsh'];
    }
}
