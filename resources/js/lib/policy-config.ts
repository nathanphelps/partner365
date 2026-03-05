export interface PolicyDefinition {
    key: string;
    label: string;
    description: string;
    tooltip: string;
}

export const policyDefinitions: PolicyDefinition[] = [
    {
        key: 'mfa_trust_enabled',
        label: 'MFA Trust',
        description: 'Trust MFA claims from partner tenants.',
        tooltip:
            "When enabled, your Conditional Access policies will accept MFA claims from this partner's tenant. Users won't need to complete MFA again in your tenant if they've already done so in theirs. Only enable for partners with MFA policies you trust.",
    },
    {
        key: 'device_trust_enabled',
        label: 'Device Trust',
        description: 'Trust device compliance from partner tenants.',
        tooltip:
            "When enabled, your Conditional Access policies will accept device compliance and hybrid Azure AD joined claims from this partner. Their users can satisfy your device-based policies without re-enrolling. Only enable for partners whose device management you trust.",
    },
    {
        key: 'direct_connect_enabled',
        label: 'Direct Connect',
        description: 'Allow Teams direct connect.',
        tooltip:
            "When enabled, users from this partner can join your Teams shared channels directly without being added as guests. They appear as external members and access is scoped to specific channels.",
    },
    {
        key: 'b2b_inbound_enabled',
        label: 'B2B Inbound',
        description: 'Allow inbound B2B collaboration.',
        tooltip:
            "Controls whether users from this partner's tenant can be invited as guests into your tenant. When disabled, invitations to users from this partner will be blocked by your cross-tenant access policy.",
    },
    {
        key: 'b2b_outbound_enabled',
        label: 'B2B Outbound',
        description: 'Allow outbound B2B collaboration.',
        tooltip:
            "Controls whether your users can be invited as guests into this partner's tenant. When disabled, your users will be blocked from accepting invitations from this partner.",
    },
];
