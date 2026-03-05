---
title: Adding Partners
---

# Adding Partners

Adding a partner organization to Partner365 creates a formal relationship between your [tenant](/docs/glossary/01-glossary) and the external organization. This gives you policy control, visibility into guest users, and trust score tracking for that partner.

## Prerequisites

Before you can add a partner, ensure the following:

- **Graph API connection** — Your tenant's Microsoft Graph API integration must be configured and active. An admin can verify this under [Admin > Graph Settings](/docs/admin/02-graph-api-settings).
- **Role** — You must have the **Operator** or **Admin** role. Viewers cannot add partners.
- **Valid tenant** — The partner must have a Microsoft 365 business or enterprise tenant. Consumer domains (outlook.com, gmail.com, hotmail.com) are not supported.

## Steps

1. Click the **Add Partner** button on the Partners page.
2. Enter the partner's **domain name** (e.g., contoso.com) or **tenant ID** (a GUID).
3. Click **Resolve Tenant** — Partner365 calls the Microsoft Graph API to look up the tenant associated with the domain or ID. If the domain maps to a valid M365 business tenant, Partner365 retrieves the organization's display name, verified domains, and tenant ID. If the lookup fails, see [Troubleshooting](/docs/users/partners/05-troubleshooting).
4. Review the resolved tenant details — confirm the display name and tenant ID match the organization you intend to partner with.
5. Select a **category** for the partner (Vendor, Customer, Subsidiary, etc.). Categories are used for filtering and reporting, so choose one that reflects the nature of the relationship.
6. Optionally select a **template** to apply a pre-configured [cross-tenant access policy](/docs/concepts/01-cross-tenant-policies). Templates define a baseline set of inbound/outbound collaboration and direct connect settings. They are ideal when you have standardized policies for a given partner type — for example, a "Vendor - Restricted" template that limits B2B collaboration to specific applications. If you are unsure which template to use, or if none fit, you can skip this step. See [Templates](/docs/admin/05-templates) for details on creating and managing templates.
7. Click **Create Partner**.

> **Good to know:** You can add a partner even if you do not have a template — the default cross-tenant access policy from your tenant applies automatically. You can always adjust policies later from the [partner detail page](/docs/users/partners/03-partner-details).

## What Happens Behind the Scenes

When you click Create Partner, Partner365 performs the following:

1. **Policy creation** — A `crossTenantAccessPolicy/partners` entry is created in Microsoft Entra ID via the Graph API. If you selected a template, its policy settings are applied. Otherwise, the partner inherits your tenant's default cross-tenant access policy.
2. **Local record** — Partner365 stores the partner locally with the resolved tenant information, category, and any notes you provided.
3. **Trust score calculation** — An initial [trust score](/docs/concepts/03-trust-score) is calculated immediately based on the applied policy configuration.
4. **Background sync** — The partner will be included in the regular 15-minute sync cycle, which reconciles local data with the live state in Entra ID.

The partner appears in your partner list right away. You can navigate to their detail page to review or adjust policies, invite guest users, or check the trust score breakdown.
