<?php

namespace App\Services\DTOs;

class SetLabelResult
{
    public function __construct(
        public readonly string $siteUrl,
        public readonly string $labelId,
        public readonly bool $fastPath,
    ) {}
}
