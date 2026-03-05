export type AccessReview = {
    id: number;
    title: string;
    description: string | null;
    review_type: 'guest_users' | 'partner_organizations';
    scope_partner_id: number | null;
    scope_partner?: { id: number; display_name: string };
    recurrence_type: 'one_time' | 'recurring';
    recurrence_interval_days: number | null;
    remediation_action: 'flag_only' | 'disable' | 'remove';
    reviewer_user_id: number;
    reviewer?: { id: number; name: string };
    created_by_user_id: number;
    created_by?: { id: number; name: string };
    graph_definition_id: string | null;
    next_review_at: string | null;
    instances_count?: number;
    latest_instance?: AccessReviewInstance;
    instances?: AccessReviewInstance[];
    created_at: string;
};

export type AccessReviewInstance = {
    id: number;
    access_review_id: number;
    status: 'pending' | 'in_progress' | 'completed' | 'expired';
    started_at: string;
    due_at: string;
    completed_at: string | null;
    decisions?: AccessReviewDecision[];
    decisions_count?: number;
    approved_count?: number;
    denied_count?: number;
    pending_count?: number;
};

export type AccessReviewDecision = {
    id: number;
    access_review_instance_id: number;
    subject_type: 'guest_user' | 'partner_organization';
    subject_id: number;
    decision: 'approve' | 'deny' | 'pending';
    justification: string | null;
    decided_by_user_id: number | null;
    decided_by?: { id: number; name: string };
    decided_at: string | null;
    remediation_applied: boolean;
    remediation_applied_at: string | null;
};
