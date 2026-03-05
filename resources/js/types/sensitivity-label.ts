import type { PartnerOrganization } from './partner';

export type SensitivityLabel = {
    id: number;
    label_id: string;
    name: string;
    description: string | null;
    color: string | null;
    tooltip: string | null;
    scope: ('files_emails' | 'sites_groups')[] | null;
    priority: number;
    is_active: boolean;
    parent_label_id: number | null;
    protection_type: 'encryption' | 'watermark' | 'header_footer' | 'none';
    synced_at: string | null;
    partners_count?: number;
    partners?: (PartnerOrganization & {
        pivot: {
            matched_via: 'label_policy' | 'site_assignment';
            policy_name: string | null;
            site_name: string | null;
        };
    })[];
    children?: SensitivityLabel[];
};
