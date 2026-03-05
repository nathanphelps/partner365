<?php

namespace App\Enums;

enum RecurrenceType: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';
}
