export type GuestUser = {
    id: number;
    entra_user_id: string;
    email: string;
    display_name: string;
    user_principal_name: string | null;
    partner_organization_id: number | null;
    partner_organization?: { id: number; display_name: string };
    invited_by_user_id: number | null;
    invited_by?: { id: number; name: string };
    invitation_status: 'pending_acceptance' | 'accepted' | 'failed';
    account_enabled: boolean;
    last_sign_in_at: string | null;
    last_synced_at: string | null;
    created_at: string;
};
