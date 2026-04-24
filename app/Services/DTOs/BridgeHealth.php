<?php

namespace App\Services\DTOs;

class BridgeHealth
{
    public function __construct(
        public readonly string $status,
        public readonly string $cloudEnvironment,
        public readonly string $certThumbprint,
    ) {}
}
