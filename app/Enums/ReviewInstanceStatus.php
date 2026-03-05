<?php

namespace App\Enums;

enum ReviewInstanceStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Expired = 'expired';
}
