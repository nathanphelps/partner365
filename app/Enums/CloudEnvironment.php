<?php

namespace App\Enums;

enum CloudEnvironment: string
{
    case Commercial = 'commercial';
    case GccHigh = 'gcc_high';

    public function loginUrl(): string
    {
        return match ($this) {
            self::Commercial => 'login.microsoftonline.com',
            self::GccHigh => 'login.microsoftonline.us',
        };
    }

    public function graphBaseUrl(): string
    {
        return match ($this) {
            self::Commercial => 'https://graph.microsoft.com/v1.0',
            self::GccHigh => 'https://graph.microsoft.us/v1.0',
        };
    }

    public function defaultScopes(): string
    {
        return match ($this) {
            self::Commercial => 'https://graph.microsoft.com/.default',
            self::GccHigh => 'https://graph.microsoft.us/.default',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Commercial',
            self::GccHigh => 'GCC High',
        };
    }
}
