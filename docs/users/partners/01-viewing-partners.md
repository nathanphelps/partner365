---
title: Viewing Partners
---

# Viewing Partners

The Partners page is your primary dashboard for all external organizations configured in Partner365. It provides an at-a-glance view of each partner's security posture, policy status, and collaboration activity, so you can quickly identify partners that need attention.

## Partner List

Each row in the partner list displays the following columns:

- **Display Name** — The organization's name as registered in Microsoft Entra ID.
- **Domain** — The partner's primary domain (e.g., contoso.com), useful for quick identification.
- **Favicon** — A small visual identifier pulled from the partner's domain, making it easier to scan the list.
- **Tenant ID** — The partner's Microsoft 365 [tenant](/docs/glossary/01-glossary) identifier (a GUID). Useful when cross-referencing with Entra ID directly.
- **Category** — The classification you assigned when adding the partner (Vendor, Customer, Subsidiary, etc.). Categories help organize partners for filtering and reporting.
- **Trust Score** — A 0-100 score reflecting the partner's overall security posture. The score is color-coded: green (70-100) indicates a healthy configuration, yellow (40-69) suggests areas for improvement, and red (0-39) flags partners that need immediate review.
- **MFA Trust** — Whether your tenant trusts [multi-factor authentication](/docs/glossary/01-glossary) claims from this partner. Displays as enabled or disabled.
- **B2B Direct Connect** — Shows whether inbound or outbound [B2B Direct Connect](/docs/glossary/01-glossary) is active for this partner.
- **Guest Count** — The number of guest users from this partner currently in your tenant.
- **Policy Status** — Whether [cross-tenant access policies](/docs/concepts/01-cross-tenant-policies) are explicitly configured for this partner, or whether the tenant default applies.
- **Last Sync** — The timestamp of the most recent data synchronization from Microsoft Graph API. If this is stale, the displayed information may be outdated.

## Filtering and Search

The search bar at the top of the partner list filters across partner name, domain, and tenant ID simultaneously. Start typing any of these values and the list narrows in real time.

The **Category** dropdown lets you filter partners by their assigned classification. This is particularly useful when reviewing groups of partners together.

> **Good to know:** Use category filters to quickly find all vendor partners when reviewing security posture, or isolate subsidiaries when auditing MFA trust settings.

## Sorting

Click any column header to sort the partner list by that column. Click again to reverse the sort order. Sorting by trust score is especially useful — sort ascending to surface the lowest-scored partners first, so you can prioritize remediation.

## Viewing Partner Details

Click on any partner row to open their full [partner details](/docs/users/partners/03-partner-details) page, which includes cross-tenant access policy configuration, associated guest users, trust score breakdown, and activity history.
