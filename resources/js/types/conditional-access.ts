import type { PartnerOrganization } from './partner';

export type ConditionalAccessPolicy = {
    id: number;
    policy_id: string;
    display_name: string;
    state: string;
    guest_or_external_user_types: string | null;
    external_tenant_scope: string;
    external_tenant_ids: string[] | null;
    target_applications: string;
    grant_controls: string[] | null;
    session_controls: string[] | null;
    synced_at: string | null;
    partners_count?: number;
    partners?: (PartnerOrganization & {
        pivot: { matched_user_type: string };
    })[];
};
