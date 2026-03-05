<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Denied = 'denied';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
