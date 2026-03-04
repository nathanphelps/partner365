<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case PendingAcceptance = 'pending_acceptance';
    case Accepted = 'accepted';
    case Failed = 'failed';
}
