<?php

namespace App\Enums;

enum ReviewType: string
{
    case GuestUsers = 'guest_users';
    case PartnerOrganizations = 'partner_organizations';
}
