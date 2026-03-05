export type AccessPackageCatalog = {
    id: number;
    graph_id: string | null;
    display_name: string;
    description: string | null;
    is_default: boolean;
    last_synced_at: string | null;
    created_at: string;
};

export type AccessPackage = {
    id: number;
    graph_id: string | null;
    catalog_id: number;
    catalog?: AccessPackageCatalog;
    partner_organization_id: number;
    partner_organization?: {
        id: number;
        display_name: string;
        tenant_id: string;
    };
    display_name: string;
    description: string | null;
    duration_days: number;
    approval_required: boolean;
    approver_user_id: number | null;
    approver?: { id: number; name: string };
    is_active: boolean;
    created_by_user_id: number;
    created_by?: { id: number; name: string };
    resources_count?: number;
    assignments_count?: number;
    resources?: AccessPackageResource[];
    assignments?: AccessPackageAssignment[];
    last_synced_at: string | null;
    created_at: string;
};

export type AccessPackageResource = {
    id: number;
    access_package_id: number;
    resource_type: 'group' | 'sharepoint_site';
    resource_id: string;
    resource_display_name: string;
    graph_id: string | null;
};

export type AccessPackageAssignment = {
    id: number;
    graph_id: string | null;
    access_package_id: number;
    target_user_email: string;
    target_user_id: string | null;
    status:
        | 'pending_approval'
        | 'approved'
        | 'denied'
        | 'delivering'
        | 'delivered'
        | 'expired'
        | 'revoked';
    approved_by_user_id: number | null;
    approved_by?: { id: number; name: string };
    expires_at: string | null;
    requested_at: string;
    approved_at: string | null;
    delivered_at: string | null;
    justification: string | null;
    last_synced_at: string | null;
};

export type GraphGroup = {
    id: string;
    displayName: string;
    description: string | null;
};

export type GraphSharePointSite = {
    id: string;
    displayName: string;
    webUrl: string;
};
