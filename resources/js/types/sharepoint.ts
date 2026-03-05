import type { GuestUser, PartnerOrganization } from './partner';
import type { SensitivityLabel } from './sensitivity-label';

export type SharePointSite = {
    id: number;
    site_id: string;
    display_name: string;
    url: string;
    description: string | null;
    external_sharing_capability: string;
    sensitivity_label?: SensitivityLabel | null;
    owner_display_name: string | null;
    owner_email: string | null;
    storage_used_bytes: number | null;
    last_activity_at: string | null;
    member_count: number | null;
    synced_at: string | null;
    permissions_count?: number;
    permissions?: SharePointSitePermission[];
};

export type SharePointSitePermission = {
    id: number;
    sharepoint_site_id: number;
    guest_user_id: number;
    role: string;
    granted_via: 'direct' | 'sharing_link' | 'group_membership';
    guest_user?: GuestUser & {
        partner_organization?: PartnerOrganization;
    };
};
