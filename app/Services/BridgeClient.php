<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\DTOs\BridgeHealth;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnavailableException;
use App\Services\Exceptions\BridgeUnknownException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BridgeClient
{
    private const DEFAULT_TIMEOUT = 60;

    public function setLabel(string $siteUrl, string $labelId, bool $overwrite = false): SetLabelResult
    {
        $query = $overwrite ? '?overwrite=true' : '?overwrite=false';
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->post(
                $this->baseUrl().'/v1/sites/label'.$query,
                ['siteUrl' => $siteUrl, 'labelId' => $labelId],
            )
        );

        $this->throwOnError($response);
        $body = $response->json();

        return new SetLabelResult(
            siteUrl: $body['siteUrl'],
            labelId: $body['labelId'],
            fastPath: (bool) ($body['fastPath'] ?? false),
        );
    }

    public function readLabel(string $siteUrl): ?string
    {
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->post(
                $this->baseUrl().'/v1/sites/label:read',
                ['siteUrl' => $siteUrl],
            )
        );

        $this->throwOnError($response);

        return $response->json('labelId');
    }

    public function getLabels(): array
    {
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->get($this->baseUrl().'/v1/labels')
        );

        $this->throwOnError($response);

        return $response->json('labels') ?? [];
    }

    public function health(): BridgeHealth
    {
        $response = $this->tryRequest(
            fn (PendingRequest $http) => $http->get($this->baseUrl().'/health')
        );

        $this->throwOnError($response);
        $body = $response->json();

        return new BridgeHealth(
            status: $body['status'],
            cloudEnvironment: $body['cloudEnvironment'] ?? 'unknown',
            certThumbprint: $body['certThumbprint'] ?? '',
        );
    }

    private function baseUrl(): string
    {
        return rtrim((string) Setting::get('sensitivity_sweep', 'bridge_url', 'http://bridge:8080'), '/');
    }

    private function http(): PendingRequest
    {
        $secret = (string) Setting::get('sensitivity_sweep', 'bridge_shared_secret', '');

        return Http::withHeaders(['X-Bridge-Secret' => $secret])
            ->acceptJson()
            ->asJson()
            ->timeout(self::DEFAULT_TIMEOUT);
    }

    private function tryRequest(callable $fn): Response
    {
        try {
            return $fn($this->http());
        } catch (ConnectionException $e) {
            throw new BridgeUnavailableException($e->getMessage());
        }
    }

    private function throwOnError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $code = $response->json('error.code');
        $message = $response->json('error.message') ?? 'Bridge returned HTTP '.$response->status();
        $requestId = $response->json('error.requestId');

        $status = $response->status();

        if ($status === 409) {
            throw new BridgeLabelConflictException($message, $code, $requestId);
        }

        if ($status === 404) {
            throw new BridgeSiteNotFoundException($message, $code, $requestId);
        }

        if ($status === 401) {
            throw new BridgeConfigException('Bridge rejected shared secret: '.$message, $code, $requestId);
        }

        if ($status >= 500) {
            throw match ($code) {
                'auth' => new BridgeAuthException($message, $code, $requestId),
                'throttle' => new BridgeThrottleException($message, $code, $requestId),
                'network' => new BridgeNetworkException($message, $code, $requestId),
                'certificate' => new BridgeConfigException($message, $code, $requestId),
                default => new BridgeUnknownException($message, $code, $requestId),
            };
        }

        throw new BridgeUnknownException($message, $code, $requestId);
    }
}
