<?php

namespace App\Enums;

enum ReviewDecision: string
{
    case Approve = 'approve';
    case Deny = 'deny';
    case Pending = 'pending';
}
