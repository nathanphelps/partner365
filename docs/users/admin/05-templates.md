---
title: Templates
admin: true
---

# Partner Templates

Templates let administrators define reusable [cross-tenant access policy](/docs/glossary/01-glossary) configurations that can be applied when onboarding new [partner](/docs/glossary/01-glossary) organizations.
Instead of manually configuring each policy toggle for every new partner, you select a template that represents the appropriate access pattern and the policy is configured automatically.

## Why Templates

External collaboration relationships tend to fall into a small number of patterns.
Templates encode those patterns so that onboarding is fast, consistent, and less prone to misconfiguration.
Consider creating templates for each type of relationship your organization maintains:

- **Zero Trust Vendor** — Block all access by default, then selectively enable only the specific applications the vendor needs.
  Suitable for third-party service providers who need tightly scoped access to particular systems.
- **Trusted Subsidiary** — Allow most inbound and outbound access, enable MFA trust and device compliance trust.
  Appropriate for closely affiliated organizations where you treat their users nearly like your own.
- **Limited Customer** — Inbound B2B collaboration only, restricted to specific applications, no direct connect.
  Use when customers need access to a portal or shared workspace but nothing else.
- **Conference Partner** — Minimal, short-term access for temporary collaboration during events or projects.
  No trust settings, limited application access, intended to be cleaned up after the engagement ends.

Without templates, each new partner requires an operator to remember which toggles to set and in what combination.
Templates eliminate that cognitive overhead and reduce the risk of accidentally leaving a policy too permissive or too restrictive.

> **Good to know:** Create a template for each type of external relationship your organization has.
> This makes onboarding new partners fast, consistent, and less error-prone.
> Even if you only have two or three partner types, having templates saves time and prevents mistakes.

## Creating Templates

Navigate to the Templates page and click **Create Template**.
The creation form includes:

- **Name** — A descriptive name that operators will see when selecting a template during partner creation.
  Choose something that clearly communicates the intent, such as "Zero Trust Vendor" rather than "Template 1."
- **Description** — An explanation of when to use this template and what type of partner relationship it represents.
  Operators rely on this to choose the right template, so be specific.
- **Policy Toggles** — Six toggles that mirror the settings available on the partner detail page:
  - **Inbound B2B collaboration** — Whether users from the partner organization can be invited as [guests](/docs/glossary/01-glossary) into your tenant.
  - **Outbound B2B collaboration** — Whether your users can be invited as guests into the partner's tenant.
  - **Inbound B2B direct connect** — Whether the partner's users can access your shared channels in Teams via direct connect (without becoming guests).
  - **Outbound B2B direct connect** — Whether your users can access the partner's shared channels via direct connect.
  - **MFA trust** — Whether to accept the partner's MFA claims, so their users do not need to complete MFA again when accessing your resources.
    Only enable this for partners whose MFA practices you trust.
  - **Device compliance trust** — Whether to accept the partner's device compliance status.
    Only enable this for partners whose device management standards align with yours.

Each toggle has a tooltip explaining what it controls.
When in doubt, leave toggles in their more restrictive state — you can always loosen a specific partner's policy later.

## Editing Templates

Click on any existing template to modify its name, description, or policy toggles.
Important: changes to a template only affect future partner creations.
Existing partners that were created using this template retain their current policy configuration unchanged.

If you revise a template and want existing partners to match the new configuration, you need to update their policies individually from each partner's detail page.
This is by design — it prevents a template edit from inadvertently changing policies for established partners who may have been customized since creation.

## Deleting Templates

Delete a template by clicking the delete button on its row.
Deletion is safe in the sense that it does not affect any existing partner organizations — even those that were originally created using the deleted template.
Their cross-tenant access policies remain exactly as configured.

Delete templates for collaboration patterns your organization no longer uses.
Keeping the template list focused on current patterns helps operators choose the right template without confusion.

## Using Templates

When adding a new partner organization, the creation form includes an optional template dropdown.
The workflow is:

1. Enter the partner's tenant domain or ID.
2. Optionally select a template from the dropdown.
   The template's description is shown to help you choose.
3. If a template is selected, the new partner's cross-tenant access policy is pre-configured with the template's settings.
   All six policy toggles are set according to the template.
4. Review the configuration on the partner detail page after creation.
   You can modify individual settings if this partner needs to deviate from the template in specific ways.

If no template is selected, the new partner inherits your tenant's default cross-tenant access policy — the baseline configuration that applies to all partners without explicit policies.

Templates are a starting point, not a permanent constraint.
After a partner is created, its policies are fully independent of the template and can be modified freely without affecting the template or other partners.
