<?php

namespace App\Services;

class CrossTenantPolicyService
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function listPartners(): array
    {
        $response = $this->graph->get('/policies/crossTenantAccessPolicy/partners');
        return $response['value'] ?? [];
    }

    public function getPartner(string $tenantId): array
    {
        return $this->graph->get("/policies/crossTenantAccessPolicy/partners/{$tenantId}");
    }

    public function createPartner(string $tenantId, array $config = []): array
    {
        return $this->graph->post('/policies/crossTenantAccessPolicy/partners', [
            'tenantId' => $tenantId,
            ...$config,
        ]);
    }

    public function updatePartner(string $tenantId, array $config): array
    {
        return $this->graph->patch("/policies/crossTenantAccessPolicy/partners/{$tenantId}", $config);
    }

    public function deletePartner(string $tenantId): array
    {
        return $this->graph->delete("/policies/crossTenantAccessPolicy/partners/{$tenantId}");
    }

    public function getDefaults(): array
    {
        return $this->graph->get('/policies/crossTenantAccessPolicy/default');
    }

    public function updateDefaults(array $config): array
    {
        return $this->graph->patch('/policies/crossTenantAccessPolicy/default', $config);
    }
}
