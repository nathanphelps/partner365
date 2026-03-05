# Policy Setting Tooltips Design

## Scope

Add detailed tooltips with an info icon next to policy toggles on:

- `partners/Create.vue` (step 2)
- `templates/Create.vue`
- `templates/Edit.vue`

## Changes

1. **Partner Create (step 2)** — add inline descriptions + tooltips to policy toggles (currently label-only)
2. **Templates Create & Edit** — add info icon tooltips next to existing labels
3. **Shared policy data** — extract policy metadata into `resources/js/lib/policy-config.ts`

## Tooltip Content

| Policy | Short Description | Tooltip |
|--------|-------------------|---------|
| MFA Trust | Trust MFA claims from partner tenants. | When enabled, your Conditional Access policies will accept MFA claims from this partner's tenant. Users won't need to complete MFA again in your tenant if they've already done so in theirs. Only enable for partners with MFA policies you trust. |
| Device Trust | Trust device compliance from partner tenants. | When enabled, your Conditional Access policies will accept device compliance and hybrid Azure AD joined claims from this partner. Their users can satisfy your device-based policies without re-enrolling. Only enable for partners whose device management you trust. |
| Direct Connect | Allow Teams direct connect. | When enabled, users from this partner can join your Teams shared channels directly without being added as guests. They appear as external members and access is scoped to specific channels. |
| B2B Inbound | Allow inbound B2B collaboration. | Controls whether users from this partner's tenant can be invited as guests into your tenant. When disabled, invitations to users from this partner will be blocked by your cross-tenant access policy. |
| B2B Outbound | Allow outbound B2B collaboration. | Controls whether your users can be invited as guests into this partner's tenant. When disabled, your users will be blocked from accepting invitations from this partner. |

## UI Pattern

Info icon (CircleHelp from lucide-vue-next) sits inline next to the policy label. Tooltip appears on hover using existing shadcn-vue Tooltip components with max-w-xs for readable line length.

## Files Changed

- **New:** `resources/js/lib/policy-config.ts`
- **Edit:** `resources/js/pages/partners/Create.vue`
- **Edit:** `resources/js/pages/templates/Create.vue`
- **Edit:** `resources/js/pages/templates/Edit.vue`
