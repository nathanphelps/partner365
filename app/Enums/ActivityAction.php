<?php

namespace App\Enums;

enum ActivityAction: string
{
    case PartnerCreated = 'partner_created';
    case PartnerUpdated = 'partner_updated';
    case PartnerDeleted = 'partner_deleted';
    case GuestInvited = 'guest_invited';
    case GuestRemoved = 'guest_removed';
    case PolicyChanged = 'policy_changed';
    case TemplateCreated = 'template_created';
    case SyncCompleted = 'sync_completed';
}
