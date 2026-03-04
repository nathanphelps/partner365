<?php

namespace App\Enums;

enum PartnerCategory: string
{
    case Vendor = 'vendor';
    case Contractor = 'contractor';
    case StrategicPartner = 'strategic_partner';
    case Customer = 'customer';
    case Other = 'other';
}
