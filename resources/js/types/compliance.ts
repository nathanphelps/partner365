import type { GuestUser } from './guest';
import type { PartnerOrganization } from './partner';

export type ComplianceSummary = {
    compliance_score: number;
    partners_with_issues: number;
    stale_guests_90: number;
    total_partners: number;
    total_guests: number;
    avg_trust_score: number | null;
};

export type NonCompliantPartner = Pick<
    PartnerOrganization,
    | 'id'
    | 'display_name'
    | 'domain'
    | 'mfa_trust_enabled'
    | 'device_trust_enabled'
    | 'b2b_inbound_enabled'
    | 'b2b_outbound_enabled'
    | 'trust_score'
> & {
    conditional_access_policies_count: number;
};

export type PartnerCompliance = {
    no_mfa_count: number;
    no_device_trust_count: number;
    overly_permissive_count: number;
    no_ca_policies_count: number;
    partners: NonCompliantPartner[];
};

export type StaleGuest = Pick<
    GuestUser,
    | 'id'
    | 'email'
    | 'display_name'
    | 'last_sign_in_at'
    | 'invitation_status'
    | 'account_enabled'
> & {
    partner_organization?: { id: number; display_name: string } | null;
};

export type GuestHealth = {
    stale_30_plus: number;
    stale_60_plus: number;
    stale_90_plus: number;
    never_signed_in: number;
    pending_invitations: number;
    disabled_accounts: number;
    guests: StaleGuest[];
};
