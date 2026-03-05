---
title: Templates
admin: true
---

# Partner Templates

Templates let admins create reusable cross-tenant access policy configurations.

## Why Templates?

Instead of manually configuring policies for each partner, create templates for common scenarios:
- **Restrictive Vendor** — Minimal inbound access, no outbound
- **Trusted Subsidiary** — Full inbound/outbound with MFA trust
- **Standard Customer** — Moderate access with specific app targeting

## Managing Templates

### Creating Templates
1. Navigate to the Templates page
2. Click **Create Template**
3. Configure the cross-tenant access policy settings:
   - Inbound B2B collaboration (users, groups, applications)
   - Outbound B2B collaboration
   - Inbound/Outbound B2B direct connect
   - Trust settings (MFA, device compliance)
4. Give the template a name and description
5. Click **Save**

### Editing Templates
Click on any template to modify its settings. Changes only affect future partner creations — existing partners keep their current policies.

### Deleting Templates
Remove templates that are no longer needed. This does not affect partners that were created using the template.

## Using Templates

When adding a new partner organization, you can select a template in the creation form. The template's policy settings are applied to the new partner's cross-tenant access policy.
