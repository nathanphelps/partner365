<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function canManage(): bool
    {
        return match ($this) {
            self::Admin, self::Operator => true,
            self::Viewer => false,
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
