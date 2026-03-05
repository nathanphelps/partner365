<?php

namespace App\Enums;

enum RemediationAction: string
{
    case FlagOnly = 'flag_only';
    case Disable = 'disable';
    case Remove = 'remove';
}
