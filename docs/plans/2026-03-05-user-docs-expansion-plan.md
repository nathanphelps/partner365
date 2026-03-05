# User Documentation Expansion Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Expand user documentation from ~600 lines across 21 files to ~2,500-3,500 lines across ~33 files, adding depth, best practices, troubleshooting, a glossary, onboarding walkthrough, and conceptual guides.

**Architecture:** Markdown files in `docs/users/` organized by app section. Each file uses YAML frontmatter (`title`, optional `admin: true`). Content follows tone/structure guidelines from the design doc. New files for concepts, glossary, best practices, and troubleshooting supplement expanded existing files.

**Tech Stack:** Markdown with YAML frontmatter. No code changes — documentation only.

**Design doc:** `docs/plans/2026-03-05-user-docs-expansion-design.md`

**Content guidelines (apply to ALL tasks):**
- Tone: Professional but accessible. Explain *why* not just *how*. Assume M365 familiarity but not deep Entra ID expertise.
- Structure: Brief intro paragraph → task-oriented sections with numbered steps → `> **Good to know:**` blockquote callouts for tips → cross-references via relative links
- Target: 60-150 lines per file
- No screenshots, no emojis
- Link to glossary for key terms on first use: `[term](/docs/glossary/01-glossary)`
- Link to concepts pages for deep dives: `[Cross-Tenant Access Policies](/docs/concepts/01-cross-tenant-policies)`
- Admin-only pages need `admin: true` in frontmatter

---

### Task 1: Create First Steps Walkthrough

**Files:**
- Create: `docs/users/getting-started/00-first-steps.md`

**Step 1: Write the file**

Create `docs/users/getting-started/00-first-steps.md` (~80-120 lines) with:

```yaml
---
title: First Steps
---
```

Sections:
- **Before You Begin** — What IT/admin should have configured (Graph API connection, app registration, at least one admin user approved). Link to admin docs.
- **Logging In** — Navigate to app URL, sign in with credentials or SSO if enabled. First-time users may need admin approval (explain the pending approval state).
- **Exploring the Dashboard** — What you'll see on first login: summary cards (likely showing zeros), action items section, recent activity. Link to dashboard docs.
- **Your First Partner** — Step-by-step: navigate to Partners → Add Partner → enter domain → resolve tenant → select category → optionally apply template → create. Explain what happens (policy created in Entra ID, appears in list). Link to partners docs.
- **Your First Guest Invitation** — Step-by-step: navigate to Guests → Invite Guest → select partner → enter email/name → send. Explain invitation flow. Link to guests docs.
- **What's Next** — Brief pointers to: set up access reviews for compliance, explore conditional access visibility, configure entitlements for self-service access, check reports for posture overview. Each with a link.

**Step 2: Commit**

```bash
git add docs/users/getting-started/00-first-steps.md
git commit -m "docs: add first steps walkthrough for new users"
```

---

### Task 2: Create Glossary

**Files:**
- Create: `docs/users/glossary/01-glossary.md`

**Step 1: Write the file**

Create `docs/users/glossary/01-glossary.md` (~100-150 lines) with:

```yaml
---
title: Glossary
---
```

Alphabetical definitions (2-3 sentences each + link to relevant doc page):

- **Access Package** — A bundle of resources (groups, SharePoint sites) that can be requested by external users through entitlement management. Link to `/docs/entitlements/01-access-packages`.
- **Access Review** — A periodic process to verify that guest users still need access to your tenant. Link to `/docs/access-reviews/01-overview`.
- **B2B Collaboration** — Microsoft's model for inviting external users as guests into your tenant. Guests get an account in your directory and can access shared resources. Link to `/docs/concepts/02-b2b-collaboration`.
- **B2B Direct Connect** — A model where external users access shared channels in Microsoft Teams without being added as guests. No guest account is created. Link to `/docs/concepts/02-b2b-collaboration`.
- **Conditional Access** — Entra ID policies that enforce controls (MFA, device compliance, location restrictions) when users access resources. Link to `/docs/conditional-access/01-viewing-policies`.
- **Cross-Tenant Access Policy** — Settings in Entra ID that control how your organization collaborates with a specific external tenant. Includes inbound/outbound rules for B2B collaboration and direct connect. Link to `/docs/concepts/01-cross-tenant-policies`.
- **Device Compliance** — A status indicating whether a device meets your organization's security requirements (encryption, OS version, antivirus). Relevant to trust settings in cross-tenant policies.
- **Entitlement Management** — Entra ID's system for governing access requests, approvals, and lifecycle for internal and external users. Link to `/docs/entitlements/01-access-packages`.
- **Guest User** — An external user invited into your Microsoft 365 tenant via B2B collaboration. Has a directory object but limited default permissions. Link to `/docs/guests/01-guest-list`.
- **MFA Trust** — A cross-tenant trust setting where your organization accepts MFA claims from a partner's tenant, so their users don't need to re-authenticate. Link to `/docs/concepts/01-cross-tenant-policies`.
- **Partner Organization** — An external Microsoft 365 tenant that your organization collaborates with, tracked in Partner365 with cross-tenant access policies. Link to `/docs/partners/01-viewing-partners`.
- **Remediation** — The action taken after an access review decision — typically removing denied guests from the tenant via Graph API.
- **Sensitivity Label** — A Microsoft Purview label applied to documents and sites indicating classification level (e.g., Confidential, Internal). Link to sensitivity labels page.
- **SharePoint Site Permissions** — Access grants for guest users to specific SharePoint Online sites, synced by Partner365 for visibility.
- **Trust Score** — A 0-100 score calculated by Partner365 reflecting a partner's security posture based on policy configuration, guest activity, and trust settings. Link to `/docs/concepts/03-trust-score`.

**Step 2: Commit**

```bash
git add docs/users/glossary/01-glossary.md
git commit -m "docs: add glossary of key terms"
```

---

### Task 3: Create Concepts — Cross-Tenant Policies

**Files:**
- Create: `docs/users/concepts/01-cross-tenant-policies.md`

**Step 1: Write the file**

Create `docs/users/concepts/01-cross-tenant-policies.md` (~100-120 lines) with:

```yaml
---
title: Cross-Tenant Access Policies
---
```

Sections:
- **What Are Cross-Tenant Access Policies?** — Entra ID settings that govern how your organization collaborates with specific external tenants. They control who can be invited, who can access your resources, and what trust relationships exist. Partner365 creates and manages these policies through the Graph API.
- **Inbound vs Outbound** — Inbound controls what partner users can do in *your* tenant. Outbound controls what *your* users can do in the partner's tenant. Explain with concrete examples (e.g., "Inbound B2B collaboration controls whether Contoso users can be invited as guests in your org").
- **B2B Collaboration vs B2B Direct Connect** — Collaboration creates guest accounts; direct connect allows shared channel access without guest accounts. Explain when each is used. 4 combinations: inbound collab, outbound collab, inbound direct connect, outbound direct connect.
- **Policy Composition** — Tenant default policy acts as baseline. Partner-specific policies override the default for that partner. Explain allow all / block all / target specific users-groups-apps options.
- **Trust Settings** — MFA trust (accept partner's MFA claims), device compliance trust (accept partner's device status). When to enable: trusted subsidiaries yes, unknown vendors probably not.
- **How Partner365 Maps to Entra Admin Center** — What you see in Partner365 corresponds to External Identities → Cross-tenant access settings in Entra. Partner365 manages these via Graph API so you don't need to switch between tools.

> **Good to know:** callouts for tips like "If you're unsure, start with the default policy and only create partner-specific overrides when needed."

**Step 2: Commit**

```bash
git add docs/users/concepts/01-cross-tenant-policies.md
git commit -m "docs: add cross-tenant policies concept guide"
```

---

### Task 4: Create Concepts — B2B Collaboration, Trust Score, Security Model

**Files:**
- Create: `docs/users/concepts/02-b2b-collaboration.md`
- Create: `docs/users/concepts/03-trust-score.md`
- Create: `docs/users/concepts/04-security-model.md`

**Step 1: Write B2B collaboration file**

Create `docs/users/concepts/02-b2b-collaboration.md` (~80-100 lines) with:

```yaml
---
title: B2B Collaboration
---
```

Sections:
- **How Guest Accounts Work** — When you invite an external user, Entra ID creates a guest object in your directory. The guest authenticates with their home tenant but accesses resources in yours. Their UPN typically ends with `#EXT#@yourdomain.onmicrosoft.com`.
- **The Invitation Flow** — Step-by-step: invitation sent via Graph API → guest receives email → clicks redemption link → consents to your org's permissions → account becomes active. Mention that redemption can also happen automatically for some scenarios.
- **Guest vs Member User Type** — Guests have restricted default permissions (can't enumerate directory, limited Teams access). Members have broader access. Partner365 tracks guests specifically because they represent external access.
- **What Guests Can Access by Default** — Limited directory visibility, only resources explicitly shared. Groups, Teams, SharePoint sites, and apps must be individually granted. This is why entitlement management and access reviews matter.
- **Lifecycle Considerations** — Guests can become stale (stop signing in). Stale guests are a security risk because they retain access. Access reviews and the stale guest indicator in Partner365 help manage this.

**Step 2: Write trust score file**

Create `docs/users/concepts/03-trust-score.md` (~60-80 lines) with:

```yaml
---
title: Trust Score
---
```

Sections:
- **What Is the Trust Score?** — A 0-100 score Partner365 calculates for each partner to give you a quick read on their security posture. Higher is better.
- **Score Factors** — Based on the actual implementation in the Partner Show page, the trust score breakdown includes factors like: policy restrictiveness (are policies configured or wide-open?), MFA trust configuration, device compliance trust, guest activity (ratio of active vs stale guests), B2B direct connect settings. Each factor shows pass/fail.
- **Score Ranges** — Low (0-40): significant security concerns, review immediately. Medium (41-70): some configuration gaps, consider tightening. High (71-100): well-configured, maintain through periodic reviews.
- **Improving a Partner's Score** — Configure restrictive policies (target specific users/groups/apps instead of allow-all), enable MFA trust only for partners you verify enforce MFA, remove stale guests, run access reviews regularly.

**Step 3: Write security model file**

Create `docs/users/concepts/04-security-model.md` (~80-100 lines) with:

```yaml
---
title: Security Model
---
```

Sections:
- **Role-Based Access Control** — Three roles (Viewer, Operator, Admin) following principle of least privilege. Viewers audit, Operators manage day-to-day, Admins configure the system. Link to getting-started overview for role table.
- **What Partner365 Can and Can't Modify** — Write operations: create/update/delete partners and their cross-tenant policies, invite/remove guests, manage access reviews and entitlements. Read-only: conditional access policies, collaboration settings, sensitivity labels, SharePoint site data. Clarify that read-only features sync data for visibility but changes must be made in Entra admin center.
- **Audit Trail** — Every write action is logged in the activity log with who, what, and when. Logs are retained for compliance. Link to activity log docs.
- **Authentication** — Supports local credentials (Fortify) and Entra ID SSO. SSO users can be auto-approved or require admin approval. Link to SSO admin docs.
- **Zero Trust Alignment** — Partner365 supports Zero Trust principles: verify explicitly (MFA trust settings), use least privilege (RBAC, targeted policies), assume breach (access reviews, stale guest monitoring, audit logging).

**Step 4: Commit**

```bash
git add docs/users/concepts/
git commit -m "docs: add concept guides for B2B, trust score, and security model"
```

---

### Task 5: Expand Getting Started Section

**Files:**
- Modify: `docs/users/getting-started/01-overview.md`
- Modify: `docs/users/getting-started/02-dashboard.md`

**Step 1: Expand overview**

Rewrite `docs/users/getting-started/01-overview.md` (~80-100 lines). Keep existing structure but expand:

- **Key Concepts** — Add 1-2 more sentences per concept explaining *why* it matters, not just what it is. Add links to concept pages and glossary. Add new concepts: Sensitivity Labels, SharePoint Site tracking.
- **How Partner365 Fits Into Your M365 Security** — New section. Partner365 centralizes external collaboration management that would otherwise require switching between Entra admin center, SharePoint admin, Teams admin, etc. It provides a single pane of glass for cross-tenant policies, guest lifecycle, and compliance reporting. Ties into Zero Trust (link to security model concept).
- **Roles** — Keep the table, add a sentence about who typically holds each role (e.g., "Viewers are typically security analysts or auditors").
- **Navigation** — Add SharePoint Sites and Sensitivity Labels to the list. Add note about the help icon in the header for accessing documentation.

**Step 2: Expand dashboard**

Rewrite `docs/users/getting-started/02-dashboard.md` (~60-80 lines). Expand:

- **Stats Overview** — For each card, explain what the number means and what a concerning value looks like. E.g., "A high number of stale guests may indicate that access reviews haven't been run recently."
- **Action Items** — Expand each subsection. Pending Approvals: explain what triggers them (entitlement requests), what to consider before approving. Partners Needing Attention: explain that low trust scores indicate policy gaps, high stale guest counts indicate lifecycle issues. Recent Activity: explain what action types appear and how to use this for quick monitoring.
- Add **Good to know** callout: "The dashboard is a good place to check daily. If you see overdue reviews or a spike in stale guests, investigate promptly."

**Step 3: Commit**

```bash
git add docs/users/getting-started/
git commit -m "docs: expand getting started overview and dashboard docs"
```

---

### Task 6: Expand Partners Section

**Files:**
- Modify: `docs/users/partners/01-viewing-partners.md`
- Modify: `docs/users/partners/02-adding-partners.md`
- Modify: `docs/users/partners/03-partner-details.md`
- Create: `docs/users/partners/04-best-practices.md`
- Create: `docs/users/partners/05-troubleshooting.md`

**Step 1: Expand viewing partners**

Rewrite `docs/users/partners/01-viewing-partners.md` (~60-80 lines):

- **Partner List** — Expand column descriptions. Add: Domain, Favicon (visual identifier), MFA Trust (whether enabled), B2B Direct Connect status, Last Sync time. Explain that the trust score is color-coded (green/yellow/red).
- **Filtering and Search** — Expand: search works on name, domain, and tenant ID. Category dropdown filters by partner classification. Explain use case: "Use category filters to quickly find all vendor partners when reviewing security posture."
- **Sorting** — Mention you can sort by columns like trust score to find lowest-scored partners first.

**Step 2: Expand adding partners**

Rewrite `docs/users/partners/02-adding-partners.md` (~70-90 lines):

- **Prerequisites** — New section. Graph API must be connected (link to admin Graph settings). User must be Operator or Admin role.
- **Steps** — Keep numbered steps but expand step 3 (Resolve Tenant): explain that Partner365 calls Microsoft Graph to look up the tenant, and this confirms the domain is a valid M365 tenant. Expand step 6 (Templates): explain what templates do and when to use one vs configuring manually (link to templates admin doc).
- **What Happens Behind the Scenes** — Expand: Partner365 creates a `crossTenantAccessPolicy/partners` entry in Entra ID via Graph API. The initial policy settings come from the template (if selected) or default to the tenant's default cross-tenant policy. The partner's trust score is calculated immediately.
- Add **Good to know** callout: "You can add a partner even if you don't have a template — the default policy from your tenant applies. You can always adjust policies later from the partner detail page."

**Step 3: Expand partner details**

Rewrite `docs/users/partners/03-partner-details.md` (~100-130 lines):

- **Cross-Tenant Access Policies** — Deep expansion. For each of the 6 policy toggles visible in the UI (MFA trust, device compliance trust, B2B direct connect in/out, B2B collaboration in/out): explain what it controls, what enabling/disabling means, and when you'd change it. Link to cross-tenant policies concept page.
- **Tenant Restrictions** — New subsection. Explain the allow/block list for applications visible in the partner detail page. When to use: restrict partner access to specific apps only.
- **Guest Users** — Expand: this shows guests specifically from this partner. You can click through to guest details or invite new guests directly. Mention the guest count and stale guest indicators.
- **Trust Score** — Expand the breakdown table description. Each factor shows pass/fail — explain what failing factors mean and how to fix them.
- **Additional Tabs** — Document the Conditional Access, Sensitivity Labels, and SharePoint Sites tabs visible on the partner detail page. Each shows data specific to this partner.
- **Actions** — Keep but expand: explain that deleting a partner removes the cross-tenant policy from Entra ID. This is irreversible. Notes are saved per-partner for internal tracking.

**Step 4: Create best practices**

Create `docs/users/partners/04-best-practices.md` (~70-90 lines):

```yaml
---
title: Best Practices
---
```

Sections:
- **When to Add a Partner** — Add partners when you have an ongoing collaboration relationship. One-off guest invitations don't necessarily need a partner entry, but having one gives you policy control and visibility.
- **Choosing the Right Category** — Explain categories (Vendor, Customer, Subsidiary, etc.) and why they matter for filtering and reporting.
- **Policy Configuration Recommendations** — Start restrictive, open up as needed. For vendors: limit to specific apps. For subsidiaries: consider MFA trust if they enforce MFA. For customers: moderate access, no direct connect unless needed.
- **Review Cadence** — Check partner trust scores monthly. Run access reviews quarterly at minimum. Investigate any partner whose score drops below 50.
- **Cleaning Up Stale Partners** — If collaboration ends, delete the partner to remove their cross-tenant policy. First run an access review to handle their guests.

**Step 5: Create troubleshooting**

Create `docs/users/partners/05-troubleshooting.md` (~60-80 lines):

```yaml
---
title: Troubleshooting
---
```

Sections:
- **"Tenant Not Found" When Resolving** — Domain may not be a valid M365 tenant, or it may be a consumer domain (outlook.com, gmail.com). Only Microsoft 365 business/enterprise tenants can be added as partners.
- **Partner Added but Policies Not Applying** — Check Graph API connection status on the Admin > Graph page. Verify the app registration has `Policy.ReadWrite.CrossTenantAccess` permission. Check activity log for error details.
- **Trust Score Seems Wrong** — Score updates on sync. If you just changed policies, trigger a manual sync from Admin > Sync. If guest data is stale, the guest sync may need to run first.
- **Sync Not Updating Partner Data** — Check Admin > Sync page for errors. Verify Graph API connection is healthy. Common cause: expired client secret.
- **Can't Delete a Partner** — Only Operators and Admins can delete partners. If the Graph API call fails, check permissions and connection status.

**Step 6: Commit**

```bash
git add docs/users/partners/
git commit -m "docs: expand partners section with depth, best practices, and troubleshooting"
```

---

### Task 7: Expand Guests Section

**Files:**
- Modify: `docs/users/guests/01-guest-list.md`
- Modify: `docs/users/guests/02-inviting-guests.md`
- Modify: `docs/users/guests/03-guest-details.md`
- Create: `docs/users/guests/04-best-practices.md`
- Create: `docs/users/guests/05-troubleshooting.md`

**Step 1: Expand guest list**

Rewrite `docs/users/guests/01-guest-list.md` (~60-80 lines):

- **Guest Information** — Expand each column with context. Display Name and Email: from the guest's home tenant profile. Partner: the associated partner organization (click to navigate). Invitation Status: Pending (hasn't redeemed), Accepted (active), Failed (error during invitation). Last Sign-In: most recent authentication — blank means never signed in. Created Date: when the invitation was sent.
- **Filtering** — Expand each filter with use cases. Stale filter: "Use this to identify guests who haven't signed in within the configured threshold — these are candidates for access review or removal."
- **Bulk Actions** — Expand: explain that selecting guests shows action buttons. Resend Invitations only applies to pending guests. Remove permanently deletes the guest from your Entra ID tenant — this is irreversible.
- Add **Good to know** callout: "The stale guest filter is one of the most useful tools for maintaining security hygiene. Check it regularly or set up access reviews to automate the process."

**Step 2: Expand inviting guests**

Rewrite `docs/users/guests/02-inviting-guests.md` (~70-90 lines):

- **Prerequisites** — New section. A partner organization must exist for the guest's organization. The Graph API must be connected. User must be Operator or Admin.
- **Steps** — Expand each step. Partner Organization: the guest will be associated with this partner. Email: must be the guest's external email (not an internal address). Display Name: how the guest appears in your directory. Personal Message: optional, included in the invitation email. Redirect URL: where the guest lands after redemption.
- **What the Guest Experiences** — New section. The guest receives an email invitation from Microsoft. They click the redemption link, sign in with their home tenant credentials, and consent to your organization's access request. After redemption, they can access resources you've shared.
- **Invitation Lifecycle** — New section. Pending → guest hasn't clicked the link yet. Accepted → guest redeemed successfully. Failed → error occurred (see troubleshooting). Invitations can be resent from the guest detail page or via bulk actions.
- Add **Good to know** callout: "Guests authenticate with their own organization's credentials. They don't get a password in your tenant. If their home account is disabled, they lose access to your resources too."

**Step 3: Expand guest details**

Rewrite `docs/users/guests/03-guest-details.md` (~80-100 lines):

- **Overview** — Expand: profile info (name, email, UPN, user principal name format explanation), invitation status with dates, partner organization link, last sign-in with staleness indicator, creation date.
- **Access Information Tabs** — Expand each of the 5 tabs:
  - Groups: Microsoft 365 groups and security groups the guest belongs to. These determine resource access. If a guest is in too many groups, they may have excessive access.
  - Applications: Enterprise apps the guest can access. Loaded from Graph API.
  - Teams: Microsoft Teams the guest is a member of. Guests in Teams can access channels, files, and conversations.
  - SharePoint Sites: Sites the guest has been granted access to. Shows permission level.
  - Note that each tab loads data on-demand from Graph API and may take a moment.
- **Actions** — Expand: Resend Invitation (only for pending guests, sends a new email), Remove Guest (deletes the guest object from Entra ID — irreversible, the guest loses all access immediately).

**Step 4: Create best practices**

Create `docs/users/guests/04-best-practices.md` (~70-90 lines):

```yaml
---
title: Best Practices
---
```

Sections:
- **Guest Lifecycle Management** — Invite → grant access → review periodically → remove when no longer needed. Don't let guests accumulate without review.
- **Monitoring Stale Guests** — Use the stale filter on the guest list to identify guests who haven't signed in recently. A guest inactive for 90+ days likely doesn't need access anymore.
- **Using Access Reviews** — Set up quarterly access reviews targeting all guests or specific partner organizations. This automates the "do they still need access?" check. Link to access reviews docs.
- **Principle of Least Privilege** — Grant guests access to specific resources (groups, apps, sites) rather than broad permissions. Use entitlement packages to standardize access bundles.
- **Cleaning Up After Collaboration Ends** — When a project ends, review all guests from that partner. Remove guests who no longer need access. Consider deleting the partner if collaboration is over entirely.

**Step 5: Create troubleshooting**

Create `docs/users/guests/05-troubleshooting.md` (~60-80 lines):

```yaml
---
title: Troubleshooting
---
```

Sections:
- **Invitation Failed** — Common causes: invalid email address, partner's tenant blocks B2B invitations, your cross-tenant outbound policy blocks invitations to that tenant. Check the error details on the guest detail page. Verify the partner's cross-tenant policy allows inbound collaboration.
- **Guest Can't Access Resources** — The guest may not be in the right groups or app assignments. Check the Access Information tabs on their detail page. Ensure conditional access policies aren't blocking them (check CA page for affected policies).
- **Guest Shows as "Pending" for a Long Time** — The guest may not have received or seen the invitation email. Try resending the invitation. Check spam filters. The guest may also need to be in an allowed domain if your collaboration settings restrict which domains can be invited.
- **Guest Removed but Still Has Access** — Removal via Graph API is eventual — it may take a few minutes for all Microsoft services to revoke access. Cached tokens may grant short-term access after removal.
- **"Stale" Guest Still Active** — The stale indicator is based on last sign-in data from Graph API. If the guest signs in to resources that don't update the sign-in log immediately, there can be a delay. Trigger a guest sync to refresh.

**Step 6: Commit**

```bash
git add docs/users/guests/
git commit -m "docs: expand guests section with depth, best practices, and troubleshooting"
```

---

### Task 8: Expand Access Reviews Section

**Files:**
- Modify: `docs/users/access-reviews/01-overview.md`
- Modify: `docs/users/access-reviews/02-creating-reviews.md`
- Modify: `docs/users/access-reviews/03-reviewing-decisions.md`
- Create: `docs/users/access-reviews/04-best-practices.md`
- Create: `docs/users/access-reviews/05-troubleshooting.md`

**Step 1: Expand overview**

Rewrite `docs/users/access-reviews/01-overview.md` (~60-80 lines):

- **Why Access Reviews Matter** — New section. Regulatory requirements (SOC 2, ISO 27001, NIST) often mandate periodic access reviews. Even without compliance mandates, reviews reduce risk from permission creep and stale accounts.
- **How It Works** — Keep the 4-step flow but expand each step with more detail. Explain that reviews target guest users (not internal users) and can be scoped to specific partners.
- **Review Types** — Document the review types visible in the Create form: partner-scoped (all guests from a specific partner) and tenant-wide (all guests).
- **Review Status** — Expand: Active (in progress, decisions being made), Completed (all instances decided), Expired (past due date — decisions may be incomplete). Explain what happens to expired reviews.
- **Recurrence** — New subsection. Reviews can be one-time or recurring (weekly, monthly, quarterly). Recurring reviews automatically create new instances on schedule.

**Step 2: Expand creating reviews**

Rewrite `docs/users/access-reviews/02-creating-reviews.md` (~60-80 lines):

- **Prerequisites** — User must be Operator or Admin. At least one partner with guests must exist.
- **Steps** — Expand each field:
  - Name: descriptive, e.g., "Q1 2026 Vendor Guest Review"
  - Review Type: partner-scoped or all guests
  - Scope Partner: if partner-scoped, which partner to review
  - Recurrence: one-time, weekly, monthly, quarterly
  - Remediation Action: what happens to denied guests (remove from tenant)
  - Reviewer: who makes the decisions
- **After Creation** — Expand: instances are generated — one per guest in scope. The reviewer sees all instances on the review detail page. If the review is recurring, new instances are created on each cycle.
- Add **Good to know** callout: "Start with a partner-scoped review for your highest-risk partner (lowest trust score or most guests). This lets you learn the process before scaling to all guests."

**Step 3: Expand reviewing decisions**

Rewrite `docs/users/access-reviews/03-reviewing-decisions.md` (~70-90 lines):

- **The Review Interface** — Describe what the reviewer sees: a table of guest instances, each with guest name/email, partner, last sign-in, and decision dropdown. The compliance percentage updates as decisions are made.
- **Making Decisions** — Expand: for each guest, consider their last sign-in (recent = likely still active), their partner organization (still collaborating?), and their access scope (check guest detail page if unsure). Approve means access continues. Deny flags for remediation.
- **Adding Justifications** — Expand: justification notes are stored with the decision for audit purposes. Good practice for denials: explain why (e.g., "Project ended December 2025, access no longer needed").
- **Bulk Decisions** — If available, mention that you can make the same decision for multiple guests at once.
- **Applying Remediations** — Expand: after all decisions are made, the "Apply Remediations" button becomes available. This removes denied guests from the tenant via Graph API. This action is **irreversible** — denied guests will need to be re-invited if they need access again. Only apply when you're confident in all denial decisions.
- **Review History** — Completed reviews are retained for audit. You can view past reviews, see who made each decision, and when remediations were applied.

**Step 4: Create best practices**

Create `docs/users/access-reviews/04-best-practices.md` (~60-80 lines):

```yaml
---
title: Best Practices
---
```

Sections:
- **Review Frequency** — Quarterly is a good default for most organizations. High-risk partners (low trust score, many guests) may warrant monthly reviews. Use recurring reviews to automate scheduling.
- **Scope Strategy** — Start partner-by-partner for manageable batches. Once comfortable, consider tenant-wide reviews. Partner-scoped reviews let different people review different partners.
- **Decision Criteria** — Approve if: guest signed in recently AND collaboration is ongoing. Deny if: guest hasn't signed in for 90+ days, project has ended, or partner relationship has changed. When in doubt, check the guest's access tabs.
- **Handling Expired Reviews** — If a review expires without all decisions, create a new review for the same scope. Investigate why decisions weren't completed — consider assigning a different reviewer or adjusting the due date.
- **Remediation Timing** — Apply remediations promptly after review completion. Delayed remediation means denied guests retain access longer than intended.

**Step 5: Create troubleshooting**

Create `docs/users/access-reviews/05-troubleshooting.md` (~50-60 lines):

```yaml
---
title: Troubleshooting
---
```

Sections:
- **No Instances Generated** — The scoped partner may have no guests, or guests may have been removed since review creation. Check the partner's guest count.
- **Remediation Failed for Some Guests** — Graph API errors can cause individual removal failures. Check the activity log for error details. Common causes: insufficient permissions, guest already removed externally. Retry by removing the guest manually from the guest detail page.
- **Review Shows Wrong Guest Count** — Guest data may be stale. Trigger a guest sync from Admin > Sync before creating reviews to ensure the latest data.
- **Can't Create a Review** — Ensure you have Operator or Admin role. At least one partner must exist with associated guests.

**Step 6: Commit**

```bash
git add docs/users/access-reviews/
git commit -m "docs: expand access reviews section with depth, best practices, and troubleshooting"
```

---

### Task 9: Expand Conditional Access Section

**Files:**
- Modify: `docs/users/conditional-access/01-viewing-policies.md`
- Create: `docs/users/conditional-access/02-understanding-policies.md`
- Create: `docs/users/conditional-access/03-troubleshooting.md`

**Step 1: Expand viewing policies**

Rewrite `docs/users/conditional-access/01-viewing-policies.md` (~60-80 lines):

- **Policy List** — Expand each column. State badges: Enabled (actively enforcing), Disabled (not enforcing), Report-only (logging but not blocking — useful for testing). Grant Controls: explain common ones (Require MFA, Block access, Require compliant device). Affected Partners: the count of partners whose guests are affected by this policy.
- **Uncovered Partners Alert** — New subsection. The index page shows an alert if any partners have guests not covered by conditional access policies. Explain what this means: those guests can access resources without additional security controls.
- **Policy Details** — Expand: clicking a policy shows the full configuration. Included/excluded user types (all guests, specific external tenants). Target applications (all cloud apps or specific apps). Conditions (risk levels, device platforms, locations, client apps). Grant controls (what's enforced) and session controls (token lifetime, app-enforced restrictions).
- **Affected Partners** — New subsection. The detail page shows which partner organizations have guests affected by this policy. Useful for understanding the blast radius of policy changes.

**Step 2: Create understanding policies**

Create `docs/users/conditional-access/02-understanding-policies.md` (~80-100 lines):

```yaml
---
title: Understanding Policies
---
```

Sections:
- **How Conditional Access Affects Guests** — Conditional access evaluates every sign-in attempt. For guests, policies can require additional authentication (MFA), restrict which devices can be used, limit access by location, or block access entirely. Guests may face different CA requirements than internal users.
- **Common Policy Patterns** — Describe patterns IT teams commonly use:
  - Require MFA for all external users: most common, ensures guests verify identity
  - Block access from untrusted locations: geo-fencing for sensitive resources
  - Require compliant devices: ensures guests use managed/secure devices
  - Block legacy authentication: prevents less secure sign-in protocols
  - Report-only mode: test a policy's impact before enforcing
- **Policy Evaluation Order** — Policies don't have explicit priority. All matching policies apply, and the most restrictive controls win. If one policy requires MFA and another blocks access, the block wins.
- **Interaction with Cross-Tenant Trust** — If you trust a partner's MFA claims (in cross-tenant policy), their guests satisfy MFA requirements using their home tenant's MFA. Without trust, guests must complete MFA in your tenant. Link to cross-tenant policies concept.
- **Why This Is Read-Only** — Partner365 syncs conditional access data for visibility but doesn't modify policies because CA policies affect all users (not just guests). Changes should be made carefully in the Entra admin center with full impact analysis.

**Step 3: Create troubleshooting**

Create `docs/users/conditional-access/03-troubleshooting.md` (~50-60 lines):

```yaml
---
title: Troubleshooting
---
```

Sections:
- **Guest Blocked by Conditional Access** — Check which policies affect the guest's partner (use the partner detail page's CA tab or the policy detail page). Common causes: MFA not completed, device not compliant, blocked location. If the partner's MFA should be trusted, enable MFA trust in the partner's cross-tenant policy.
- **Understanding Report-Only Mode** — Policies in report-only mode log what would happen but don't enforce. Check Entra sign-in logs for report-only results. Useful for testing before enabling.
- **"Uncovered Partners" Alert** — This means some partners' guests aren't matched by any CA policy. Consider creating a baseline CA policy that applies to all guest users, or verify that coverage gaps are intentional.
- **Policy Data Out of Date** — CA policies sync on the regular schedule. Trigger a manual sync from Admin > Sync if you've just created or modified policies in the Entra admin center.

**Step 4: Commit**

```bash
git add docs/users/conditional-access/
git commit -m "docs: expand conditional access section with understanding guide and troubleshooting"
```

---

### Task 10: Expand Entitlements Section

**Files:**
- Modify: `docs/users/entitlements/01-access-packages.md`
- Modify: `docs/users/entitlements/02-assignments.md`
- Create: `docs/users/entitlements/03-best-practices.md`
- Create: `docs/users/entitlements/04-troubleshooting.md`

**Step 1: Expand access packages**

Rewrite `docs/users/entitlements/01-access-packages.md` (~70-90 lines):

- **What Are Access Packages?** — Expand: instead of manually adding guests to individual groups and SharePoint sites, bundle related resources into a package. When a guest is assigned a package, they get access to everything in it. When access is revoked, all resources are removed at once. This standardizes and simplifies access management.
- **When to Use Access Packages** — New section. Use when: multiple guests need the same set of resources, you want approval workflows for access requests, you need time-limited access (packages can have durations), you want to manage access lifecycle centrally. Don't use when: a guest needs one-off access to a single resource.
- **Viewing Packages** — Expand column explanations. Resources count: number of groups and sites bundled. Active Assignments: current holders. Duration: how long access lasts before it must be renewed. Status: active or inactive.
- **Creating Packages** — Expand the 4-step wizard: select partner organization, add resources (search and select groups and SharePoint sites), configure policy (duration in days, whether approval is required), review and create. Link to best practices for design guidance.

**Step 2: Expand assignments**

Rewrite `docs/users/entitlements/02-assignments.md` (~60-80 lines):

- **Assignment Lifecycle** — Expand each stage: Requested (user or admin initiates), Pending Approval (if approval required, waiting for Operator/Admin), Approved (access granted, guest added to groups/sites), Active (ongoing access), Expired (duration elapsed, access removed), Denied (request rejected), Revoked (admin manually removed access).
- **Managing Assignments** — Expand: on the access package detail page, the assignments table shows all current and past assignments. Pending assignments have Approve/Deny buttons. Active assignments have a Revoke button. Each action is logged in the activity log.
- **Approval Workflow** — Expand: when a new request comes in, it appears on the Dashboard under Pending Approvals and on the entitlement detail page. Before approving, review: who is requesting, which partner they belong to, what resources they'll get, and whether the request makes sense. Add a justification note when approving or denying.
- **Requesting Access** — New subsection. On the entitlement detail page, admins/operators can request an assignment on behalf of a guest using the request form. Select the guest user, optionally add a justification, and submit.

**Step 3: Create best practices**

Create `docs/users/entitlements/03-best-practices.md` (~60-70 lines):

```yaml
---
title: Best Practices
---
```

Sections:
- **Package Design** — Create packages per project or collaboration scenario, not per individual resource. Name packages clearly (e.g., "Contoso Project Alpha Access"). Include only the resources needed for that specific collaboration.
- **Duration Strategy** — Set durations that match the expected collaboration timeline. Short projects: 30-90 days. Ongoing partnerships: 180-365 days. Avoid indefinite access — use recurring access reviews as a backstop if durations are long.
- **Approval Policies** — Require approval for packages with sensitive resources. Auto-approval may be acceptable for low-risk packages. Document your approval criteria so reviewers are consistent.
- **Regular Cleanup** — Review active assignments periodically. Revoke assignments for guests who no longer need access. Deactivate packages for completed projects.

**Step 4: Create troubleshooting**

Create `docs/users/entitlements/04-troubleshooting.md` (~50-60 lines):

```yaml
---
title: Troubleshooting
---
```

Sections:
- **Assignment Stuck in Pending** — No one has approved it yet. Check the Dashboard for pending approvals. Ensure an Operator or Admin is available to review requests.
- **Guest Didn't Get Access After Approval** — The Graph API call to add the guest to groups/sites may have failed. Check the activity log for errors. Verify the guest account is active (invitation accepted). Verify the groups/sites still exist.
- **Approval Not Received** — Approvals appear on the Dashboard and the entitlement detail page. Ensure the approver has Operator or Admin role. There are no email notifications — approvers must check the app.
- **Expired Assignment Didn't Remove Access** — Access removal on expiry depends on the background sync/queue. Check that the queue worker is running (Admin > Sync). Manually revoke if needed.

**Step 5: Commit**

```bash
git add docs/users/entitlements/
git commit -m "docs: expand entitlements section with depth, best practices, and troubleshooting"
```

---

### Task 11: Expand Reports, Activity, and Admin Sections

**Files:**
- Modify: `docs/users/reports/01-compliance-reports.md`
- Create: `docs/users/reports/02-using-reports.md`
- Modify: `docs/users/activity/01-activity-log.md`
- Modify: `docs/users/admin/01-user-management.md`
- Modify: `docs/users/admin/02-sync-configuration.md`
- Modify: `docs/users/admin/03-graph-settings.md`
- Modify: `docs/users/admin/04-collaboration-settings.md`
- Modify: `docs/users/admin/05-templates.md`
- Create: `docs/users/admin/07-troubleshooting.md`

Note: `06-sso-settings.md` already exists and has good depth (52 lines). Leave it as-is.

**Step 1: Expand compliance reports**

Rewrite `docs/users/reports/01-compliance-reports.md` (~70-90 lines):

- **What Is Compliance in This Context?** — New section. Compliance here means your organization's adherence to its own external collaboration policies and security standards. The report measures: are partners properly configured? Are guests actively managed? Are access reviews being completed?
- **Report Sections** — Expand. Two main tabs:
  - Partner Compliance: shows each partner with trust score, flags for issues (no MFA, overly permissive policies, no CA coverage). Filterable by issue type.
  - Guest Health: shows guest activity metrics. Buckets: active (signed in recently), inactive 30-59 days, inactive 60-89 days, inactive 90+ days, never signed in. Each bucket indicates increasing risk.
- **Summary Cards** — New subsection. Overall compliance score, count of partners with issues, count of stale guests. Explain what the compliance score represents.
- **Exporting** — Expand: CSV export includes all filtered data. Useful for sharing with auditors, importing into GRC tools, or creating executive summaries.
- **Filters** — Expand: filter by date range, specific partners, issue types. Use filters to focus on specific areas of concern.

**Step 2: Create using reports**

Create `docs/users/reports/02-using-reports.md` (~60-70 lines):

```yaml
---
title: Using Reports
---
```

Sections:
- **Regular Monitoring** — Run the compliance report monthly to track trends. Watch for declining compliance scores or increasing stale guest counts.
- **Preparing for Audits** — Export the compliance report before audit periods. The report provides evidence of: partner policy configuration, guest lifecycle management, access review completion rates. Pair with activity log exports for a complete audit trail.
- **Identifying Issues** — Use the partner compliance tab to find partners needing attention. Filter by "no MFA trust" to find partners where guests sign in without MFA. Filter by "overly permissive" to find partners with wide-open policies.
- **Sharing with Stakeholders** — The CSV export can be shared with security teams, compliance officers, or management. Consider creating a monthly summary email highlighting key metrics and action items.

**Step 3: Expand activity log**

Rewrite `docs/users/activity/01-activity-log.md` (~70-90 lines):

- **What Gets Logged** — Expand each action type with description: Partner created/updated/deleted, Guest invited/updated/removed, Policy modified, Access review created/decided/remediated, Entitlement assigned/approved/denied/revoked, Sync completed/failed, User management changes (role change, approval, removal). Each type corresponds to an `ActivityAction` enum value.
- **Reading Log Entries** — New subsection. Each entry shows: action type (with badge), human-readable description, who performed it, timestamp, and before/after values for modifications. Click to expand for full details.
- **Filtering Strategies** — Expand with use cases: filter by Action Type to see all policy changes. Filter by User to audit a specific person's actions. Combine Date Range with Action Type for investigation ("show me all guest removals this week"). Full-text search works on descriptions and details.
- **Using Activity Data for Investigations** — New section. When investigating a security concern: search for the affected resource (partner name, guest email). Review the timeline of actions. Check who made changes and when. Export filtered results for documentation.
- Add **Good to know** callout: "The activity log is your primary audit trail. All write operations in Partner365 are logged here, providing accountability and traceability for compliance purposes."

**Step 4: Expand admin — user management**

Rewrite `docs/users/admin/01-user-management.md` (~60-70 lines):

- **User List** — Expand: the table shows all registered Partner365 users. Status indicates whether they've been approved. Role dropdown is inline-editable for admins.
- **Changing Roles** — Expand: role changes take effect immediately. The user's current session reflects the new permissions. Consider the principle of least privilege — grant Viewer by default, Operator for daily management, Admin only for those who need system configuration access.
- **Approving Users** — Expand: new users who register (or sign in via SSO without auto-approve) appear with Pending status. Review their identity before approving. Once approved, they can access the application with their assigned role.
- **Removing Users** — Expand: removes Partner365 access only. Does not affect their Entra ID account, email, or other Microsoft 365 services. Use when someone leaves the team or no longer needs Partner365 access.
- Add **Good to know** callout: "If using SSO with auto-approve disabled, check the Users page regularly for pending approvals. New team members won't be able to access the app until approved."

**Step 5: Expand admin — sync configuration**

Rewrite `docs/users/admin/02-sync-configuration.md` (~60-70 lines):

- **How Sync Works** — Expand: Partner365 uses a write-through + background reconciliation pattern. When you make changes (add partner, invite guest), the app writes to Graph API immediately and updates the local database. Background sync jobs run on a configurable interval to reconcile any changes made outside Partner365 (e.g., in the Entra admin center).
- **Sync Types** — Expand: Partner sync updates organization details, cross-tenant policies, trust scores, conditional access data. Guest sync updates user profiles, sign-in activity, invitation status, group/app memberships.
- **Configuring Intervals** — New subsection. The sync page lets you adjust the interval for each sync type. Default is 15 minutes. Increase for larger tenants to reduce API calls. Decrease for faster reconciliation.
- **Sync Status and History** — Expand: the page shows last sync time, success/failure, records updated, and duration. A history table shows recent sync runs.
- **Manual Sync** — Expand: click Sync Now to trigger an immediate run. Use after making changes in the Entra admin center, before creating access reviews (to ensure fresh data), or after resolving Graph API issues.

**Step 6: Expand admin — graph settings**

Rewrite `docs/users/admin/03-graph-settings.md` (~70-80 lines):

- **Connection Configuration** — New section. The Graph page shows and lets admins configure: cloud environment (Commercial, GCC, GCC High), Tenant ID, Client ID, Client Secret, API scopes, and base URL. These map to the app registration in Azure.
- **Connection Status** — Expand: shows whether Partner365 can authenticate and call Graph API. A healthy connection shows a green status. Test Connection button verifies credentials without making changes.
- **Admin Consent** — New subsection. Some Graph API permissions require admin consent. The Grant Admin Consent button initiates the consent flow. Required when first setting up or when adding new API permissions.
- **Permissions** — Expand: lists the Graph API permissions granted to the app. Key permissions: `Policy.ReadWrite.CrossTenantAccess` (manage partner policies), `User.Invite.All` (invite guests), `User.ReadWrite.All` (manage guest accounts), `Directory.Read.All` (read tenant data), `Policy.Read.ConditionalAccess` (read CA policies).
- **Troubleshooting** — Keep existing guidance and expand: add "Client secret expired" (regenerate in Azure portal, update in Partner365), "Insufficient permissions" (grant additional API permissions and admin consent), "Tenant ID mismatch" (verify the Tenant ID matches your Azure AD tenant).

**Step 7: Expand admin — collaboration settings**

Rewrite `docs/users/admin/04-collaboration-settings.md` (~60-70 lines):

- **What These Settings Control** — Expand: tenant-wide external collaboration settings that apply to all partner organizations. These are the "global defaults" — partner-specific cross-tenant policies override some of these settings.
- **Settings Breakdown** — Expand each setting:
  - Guest invite restrictions: who in your org can invite guests (everyone, admins only, specific roles). Controls the invitation pipeline.
  - Collaboration restrictions: allowed or blocked domain list. If a domain is blocked, guests from that domain can't be invited.
  - External user leave settings: whether guests can remove themselves from your tenant.
  - Guest user access restrictions: the baseline permission level for all guest accounts.
- **Domain Restriction Modes** — New subsection. None (allow all domains), Allow (only listed domains), Block (everything except listed domains). Explain the interface for adding/removing domains.
- **Read-Only Notice** — Expand: these settings reflect your Entra ID configuration. Changes must be made in the Entra admin center. Partner365 syncs them for visibility so you can see the full picture alongside partner-specific policies.

**Step 8: Expand admin — templates**

Rewrite `docs/users/admin/05-templates.md` (~70-80 lines):

- **Why Templates** — Expand with more scenario examples. Common templates: "Zero Trust Vendor" (block all, selectively enable), "Trusted Subsidiary" (allow most, enable MFA/device trust), "Limited Customer" (inbound B2B collab only, specific apps). Templates ensure consistency and reduce configuration errors.
- **Creating Templates** — Expand each policy toggle with context. The 6 toggles mirror the partner detail page: inbound/outbound B2B collaboration, inbound/outbound B2B direct connect, MFA trust, device compliance trust. Explain that tooltips on each toggle describe what it controls.
- **Editing Templates** — Expand: changes only affect future partner creations. Existing partners keep their current policies. If you need to update existing partners, modify their policies individually from the partner detail page.
- **Deleting Templates** — Expand: safe to delete — doesn't affect existing partners. Delete templates for collaboration patterns you no longer use.
- **Using Templates** — Expand: when adding a partner, the template dropdown is optional. If selected, the template's policy configuration is applied. You can still modify individual settings on the partner detail page after creation.
- Add **Good to know** callout: "Create a template for each type of external relationship your organization has. This makes onboarding new partners fast and consistent."

**Step 9: Create admin troubleshooting**

Create `docs/users/admin/07-troubleshooting.md` (~70-90 lines):

```yaml
---
title: Troubleshooting
admin: true
---
```

Sections:
- **Sync Failures** — Check the sync history table for error messages. Common causes: Graph API connection lost (check Graph page), client secret expired, rate limiting (too many API calls — increase sync interval). The activity log may have additional details.
- **Graph API Permission Issues** — If operations fail with "Insufficient privileges": go to Admin > Graph, verify all required permissions are listed, click Grant Admin Consent if permissions were recently added. Required permissions are listed on the Graph settings page.
- **Users Can't Access the App** — Check: is the user approved? (Users page, check status). Do they have the right role? SSO users may be in the approval queue if auto-approve is disabled. Check SSO settings if SSO login fails.
- **Data Out of Sync with Entra Admin Center** — Trigger a manual sync from the Sync page. If sync succeeds but data is still wrong, check that the app registration has read permissions for the relevant data type.
- **High API Error Rates** — If you see frequent errors in sync history or activity log: check Azure service health for Graph API outages, verify client secret hasn't expired, check if API throttling is occurring (increase sync interval).
- **SSO Login Failures** — Verify the redirect URI matches exactly (including trailing slash). Check that delegated permissions (openid, profile, email, User.Read) are granted. Ensure admin consent is granted. See SSO settings page for configuration details.

**Step 10: Commit**

```bash
git add docs/users/reports/ docs/users/activity/ docs/users/admin/
git commit -m "docs: expand reports, activity, and admin sections with depth and troubleshooting"
```

---

### Task 12: Final Review and Verification

**Step 1: Verify all files exist**

Run: `find docs/users/ -name "*.md" | sort`

Expected: ~33 markdown files across getting-started, partners, guests, access-reviews, conditional-access, entitlements, reports, activity, admin, concepts, glossary directories.

**Step 2: Verify line counts**

Run: `find docs/users/ -name "*.md" -exec wc -l {} + | sort -n`

Expected: total between 2,500-3,500 lines. Individual files between 50-150 lines.

**Step 3: Check for broken internal links**

Run: `grep -rn '/docs/' docs/users/ | head -30`

Verify that linked paths correspond to actual files.

**Step 4: Verify frontmatter consistency**

Run: `grep -rn "^---" docs/users/ | head -60`

Every file should have opening and closing `---` frontmatter delimiters with at least a `title` field. Admin files should have `admin: true`.

**Step 5: Commit any fixes**

If any issues found, fix and commit:

```bash
git add docs/users/
git commit -m "docs: fix documentation issues found during review"
```
