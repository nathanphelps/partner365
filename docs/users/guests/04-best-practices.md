---
title: Best Practices
---

# Best Practices

Managing [B2B guest users](/docs/glossary/01-glossary) is not a one-time task — it is an ongoing responsibility. Every guest account in your tenant represents an external identity with access to your organization's resources. The practices below help you maintain a secure, well-governed guest population throughout the entire collaboration lifecycle.

## Guest Lifecycle Management

The guest lifecycle follows a predictable pattern: **Invite, Grant Access, Review, Remove**. Treating each phase deliberately prevents access sprawl and reduces security risk.

- **Invite** — Only invite guests when there is a clear business justification. Every guest should be tied to a specific collaboration need, whether that is a joint project, a vendor engagement, or an ongoing partnership. Avoid inviting guests speculatively or "just in case."
- **Grant Access** — After the guest accepts, assign them to the specific groups, Teams, SharePoint sites, and applications they need. Do not grant broad access with the intention of narrowing it later — start narrow and expand only if necessary.
- **Review** — Periodically verify that each guest still needs the access they have. Projects end, people change roles, and collaboration needs evolve. What was appropriate six months ago may no longer be justified today.
- **Remove** — When a guest no longer needs access, remove them promptly. A guest account that lingers after the collaboration ends is an unnecessary risk. Removal is straightforward from the [guest detail page](/docs/users/guests/03-guest-details) or via [bulk actions](/docs/users/guests/01-guest-list).

> **Good to know:** Document the business justification for each guest invitation. When access review time comes, having a clear record of why someone was invited makes the review significantly faster and more defensible for compliance purposes.

## Monitoring Stale Guests

The stale guest filter on the [Guest List](/docs/users/guests/01-guest-list) page identifies guests who have not signed in within the configured staleness threshold. Make checking this filter a regular part of your tenant hygiene routine.

A guest who has been inactive for 90 or more days likely does not need continued access to your tenant. When you find a stale guest, ask yourself:

- Did the project or engagement this guest was part of end?
- Did the person change roles or leave their organization?
- Are they using a different account to access resources?
- Was the invitation accepted but the guest never actually used any resources?

If none of these questions produce a clear reason to keep the guest, they are a strong candidate for removal. At minimum, flag them for the next access review cycle.

## Using Access Reviews

Rather than relying solely on manual checks, set up recurring [access reviews](/docs/access-reviews/01-overview) to automate the "does this guest still need access?" question. Quarterly reviews are a good starting cadence for most organizations.

Access reviews can target all guests in your tenant or be scoped to a specific partner organization, which is useful when you know a particular engagement has a defined end date. Each review cycle generates a documented decision trail — who was reviewed, who approved continued access, and who was removed — providing the compliance evidence that auditors expect.

When configuring reviews, consider assigning the review responsibility to the team owners or project leads who work directly with the guests. They are in the best position to know whether a guest is still actively collaborating.

## Principle of Least Privilege

Grant guests access to the specific resources they need and nothing more. The Microsoft 365 access model provides granular controls that make this practical:

- **Groups** — Add guests to specific Microsoft 365 or security groups rather than granting tenant-wide permissions. Each group controls access to a defined set of resources.
- **Applications** — Assign guests to only the enterprise applications they need. Do not add them to broad application groups unless every application in that group is relevant to their work.
- **SharePoint sites** — Grant the minimum permission level required. If a guest only needs to read documents, give them Read access rather than Contribute or Edit.
- **Entitlement access packages** — Use [access packages](/docs/entitlements/01-access-packages) to bundle the right combination of groups, apps, and sites for common collaboration scenarios. This standardizes what guests receive and makes it easier to revoke everything at once when the engagement ends.

> **Good to know:** Resist the temptation to add a guest to a broad "All External Partners" group for convenience. Fine-grained access takes slightly more effort to set up but dramatically reduces your risk exposure if a guest account is compromised.

## Cleaning Up After Collaboration Ends

When a project wraps up or a partnership concludes, take deliberate steps to clean up the associated guest accounts:

1. **Identify all guests from the partner** — Use the Partner filter on the [Guest List](/docs/users/guests/01-guest-list) to see every guest associated with that organization.
2. **Run an access review** — Before removing anyone, run a targeted [access review](/docs/access-reviews/01-overview) scoped to that partner. This documents the cleanup for compliance and gives team owners a final chance to flag guests who should be retained for ongoing work.
3. **Remove guests who no longer need access** — Use bulk actions to remove guests efficiently. Remember that removal is irreversible — guests will need to be re-invited if access is needed again later.
4. **Evaluate the partner relationship** — If the collaboration is truly finished and no guests remain, consider whether the partner organization record itself should be archived or removed. Removing the partner also cleans up any associated [cross-tenant access policies](/docs/glossary/01-glossary).

> **Good to know:** It is better to remove a guest and re-invite them later than to leave an unused account in your tenant indefinitely. The re-invitation process is quick, while an unmonitored guest account is an ongoing risk.

## Related Pages

- [Guest List](/docs/users/guests/01-guest-list) — View and manage all guest users
- [Inviting Guests](/docs/users/guests/02-inviting-guests) — How to add new guest users
- [Guest Details](/docs/users/guests/03-guest-details) — Detailed view of individual guests
- [Troubleshooting](/docs/users/guests/05-troubleshooting) — Common issues and solutions
