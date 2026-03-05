<?php

namespace App\Enums;

enum ActivityAction: string
{
    case PartnerCreated = 'partner_created';
    case PartnerUpdated = 'partner_updated';
    case PartnerDeleted = 'partner_deleted';
    case GuestInvited = 'guest_invited';
    case GuestRemoved = 'guest_removed';
    case GuestEnabled = 'guest_enabled';
    case GuestDisabled = 'guest_disabled';
    case GuestUpdated = 'guest_updated';
    case PolicyChanged = 'policy_changed';
    case TemplateCreated = 'template_created';
    case SyncCompleted = 'sync_completed';
    case SettingsUpdated = 'settings_updated';
    case UserApproved = 'user_approved';
    case UserRoleChanged = 'user_role_changed';
    case UserDeleted = 'user_deleted';
    case SyncTriggered = 'sync_triggered';
    case AccessReviewCreated = 'access_review_created';
    case AccessReviewCompleted = 'access_review_completed';
    case AccessReviewDecisionMade = 'access_review_decision_made';
    case AccessReviewRemediationApplied = 'access_review_remediation_applied';
    case AccessPackageCreated = 'access_package_created';
    case AccessPackageUpdated = 'access_package_updated';
    case AccessPackageDeleted = 'access_package_deleted';
    case AssignmentRequested = 'assignment_requested';
    case AssignmentApproved = 'assignment_approved';
    case AssignmentDenied = 'assignment_denied';
    case AssignmentRevoked = 'assignment_revoked';
}
