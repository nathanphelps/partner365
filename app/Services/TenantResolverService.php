<?php

namespace App\Services;

class TenantResolverService
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function resolve(string $tenantId): array
    {
        return $this->graph->get("/tenantRelationships/findTenantInformationByTenantId(tenantId='{$tenantId}')");
    }

    public function isValidTenantId(string $tenantId): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId);
    }
}
