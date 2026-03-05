<?php

namespace App\Services;

class CollaborationSettingsService
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function getSettings(): array
    {
        return $this->graph->get('/policies/authorizationPolicy');
    }

    public function updateSettings(array $config): array
    {
        return $this->graph->patch('/policies/authorizationPolicy', $config);
    }
}
