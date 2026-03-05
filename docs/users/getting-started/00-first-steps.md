---
title: First Steps
---

# First Steps

Partner365 brings your organization's external collaboration posture into a single interface — [partner organizations](/docs/partners/viewing-partners), [guest users](/docs/guests/guest-list), [cross-tenant access policies](/docs/glossary/glossary), and compliance tooling all managed from one place. This guide walks you through your first session so you can get productive quickly.

## Before You Begin

Before you can sign in, your IT administrator needs to have completed a few prerequisites:

1. **App registration in Microsoft Entra ID** — Partner365 communicates with your tenant through the [Microsoft Graph API](/docs/glossary/glossary). An admin must register the application and grant the required API permissions.
2. **Graph API connection verified** — The connection settings (tenant ID, client ID, and client secret) must be configured in the application. See the [Graph Settings](/docs/admin/graph-settings) admin guide for details.
3. **At least one admin user approved** — Someone with the admin [role](/docs/getting-started/overview) must exist so they can approve additional users and configure the system.

> **Good to know:** If you are the person responsible for setting up Partner365, start with the [Azure Setup](/docs/azure-setup) documentation before continuing here.

## Logging In

1. Navigate to your organization's Partner365 URL in a web browser.
2. Enter your credentials on the sign-in page. If your administrator has enabled [SSO](/docs/admin/sso-settings) via Microsoft Entra ID, click the SSO button to authenticate with your organizational account instead.
3. After successful authentication, you will be redirected to the dashboard.

If this is your first time signing in, your account may be in a **pending approval** state. This means an administrator needs to approve your account and assign you a role before you can access any data. You will see a message indicating this status. Reach out to your Partner365 administrator to request approval.

> **Good to know:** Partner365 supports three roles — Viewer, Operator, and Admin — each with increasing levels of access. Your administrator assigns your role during approval. See the [Overview](/docs/getting-started/overview) page for a full breakdown.

## Exploring the Dashboard

Once approved and signed in, the [Dashboard](/docs/getting-started/dashboard) is your landing page. On a fresh installation, expect to see:

- **Summary cards** at the top showing partner, guest, and invitation counts. These will all read zero until you start adding data.
- **Action items** in the middle highlighting anything that needs your attention, such as pending entitlement approvals or partners with low trust scores.
- **Recent activity** at the bottom showing the latest audit log entries for your organization.

The sidebar on the left provides navigation to every section of the application. Take a moment to familiarize yourself with the layout before moving on.

> **Good to know:** The dashboard refreshes its data each time you navigate to it. Background sync runs every 15 minutes, so counts will update even if you have not made manual changes.

## Your First Partner

A [partner organization](/docs/glossary/glossary) represents an external Microsoft 365 tenant that your organization collaborates with. Adding a partner creates a [cross-tenant access policy](/docs/glossary/glossary) in Entra ID that governs how users in both tenants can interact.

1. Navigate to **Partners** in the sidebar.
2. Click **Add Partner** in the top-right corner.
3. Enter the partner's **domain name** (for example, `contoso.com`) or their tenant ID.
4. Click **Resolve Tenant**. Partner365 queries the Graph API to look up the tenant's display name and unique identifier. Review the resolved details to confirm you have the right organization.
5. Select a **category** that describes the relationship (Vendor, Customer, Subsidiary, or another classification your organization uses).
6. Optionally, select a **template** to apply a pre-configured set of cross-tenant access policy settings. Templates are created by admins and help ensure consistency across similar partner types.
7. Click **Create Partner**.

Behind the scenes, Partner365 creates the cross-tenant access policy in your Entra ID tenant via the Graph API and stores a local record. The new partner appears immediately in your partner list with its initial [trust score](/docs/glossary/glossary).

For a deeper look at partner management, see the [Partners](/docs/partners/viewing-partners) documentation.

> **Good to know:** If you do not see the Add Partner button, your account may have the Viewer role. Only Operators and Admins can create partners.

## Your First Guest Invitation

[Guest users](/docs/glossary/glossary) are external people invited into your tenant through B2B collaboration. They are always associated with a partner organization.

1. Navigate to **Guests** in the sidebar.
2. Click **Invite Guest**.
3. Select the **partner organization** the guest belongs to. You must have added the partner first.
4. Enter the guest's **email address** and **display name**.
5. Click **Send Invitation**.

Partner365 sends a B2B invitation through the Graph API. The guest receives an email with a link to accept. Until they accept, the invitation appears with a **Pending** status on the guest list. Once accepted, their status updates to **Accepted** and background sync keeps their profile information current.

For full details on guest lifecycle management, see the [Guests](/docs/guests/guest-list) documentation.

> **Good to know:** Guest invitations can only be sent to domains that belong to an existing partner organization. If the domain is not recognized, add the partner first.

## What's Next

With your first partner and guest in place, consider exploring these areas to strengthen your external collaboration posture:

- **Access Reviews** — Set up periodic reviews to verify that guest access is still appropriate and revoke stale permissions. See the [Access Reviews](/docs/access-reviews/overview) guide.
- **Conditional Access** — Review which conditional access policies apply to your external users and identify any gaps. See the [Conditional Access](/docs/conditional-access/viewing-policies) guide.
- **Entitlements** — Configure access packages so external users can request access to resources through a governed, self-service workflow. See the [Entitlements](/docs/entitlements/access-packages) guide.
- **Reports** — Generate compliance reports to get a high-level view of your organization's external collaboration security posture. See the [Reports](/docs/reports/compliance-reports) guide.
