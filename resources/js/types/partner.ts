export type PartnerOrganization = {
    id: number;
    tenant_id: string;
    display_name: string;
    domain: string | null;
    category:
        | 'vendor'
        | 'contractor'
        | 'strategic_partner'
        | 'customer'
        | 'other';
    owner_user_id: number | null;
    owner?: { id: number; name: string };
    notes: string | null;
    b2b_inbound_enabled: boolean;
    b2b_outbound_enabled: boolean;
    mfa_trust_enabled: boolean;
    device_trust_enabled: boolean;
    direct_connect_inbound_enabled: boolean;
    direct_connect_outbound_enabled: boolean;
    tenant_restrictions_enabled: boolean;
    tenant_restrictions_json: Record<string, unknown> | null;
    last_synced_at: string | null;
    trust_score: number | null;
    trust_score_breakdown: Record<
        string,
        {
            label: string;
            passed: boolean;
            points: number;
            max_points: number;
        }
    > | null;
    trust_score_calculated_at: string | null;
    created_at: string;
    guest_users_count?: number;
};

export type { GuestUser } from './guest';

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};
