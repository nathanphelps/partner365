---
title: Best Practices
---

# Entitlement Best Practices

These guidelines will help you design [access packages](/docs/users/entitlements/01-access-packages) that are easy to manage, secure by default, and straightforward to audit. They are drawn from common patterns in external collaboration scenarios and Microsoft's own recommendations for [entitlement management](/docs/glossary/01-glossary).

## Package Design

Create packages around a specific project or collaboration scenario, not around individual resources. A package named "Contoso Project Alpha Access" that bundles the relevant Teams group, SharePoint site, and security group is far more useful than three separate packages for each resource.

**Naming conventions matter.** Use a consistent format so that packages are easy to find and understand at a glance. A good pattern is: `[Partner Name] — [Project or Purpose]`. For example: "Contoso — Project Alpha Access" or "Fabrikam — Vendor Portal Access". Avoid generic names like "External Access" that give no indication of scope.

**Include only what is needed.** Each package should contain the minimum set of resources required for that collaboration. Avoid creating catch-all packages that grant access to every resource a partner might ever need. If a partner is involved in multiple projects, create separate packages for each one. This follows the principle of least privilege and makes it easier to revoke access to one project without affecting another.

**Keep packages focused but complete.** If a guest needs access to a SharePoint site and the Teams group that discusses that site's content, include both in the same package. Splitting tightly related resources across packages creates confusion and increases the chance that a guest has partial access that does not work properly.

## Duration Strategy

Every access package has a duration that controls how long each [assignment](/docs/users/entitlements/02-assignments) lasts. Choosing the right duration is a balance between convenience and security.

- **Short-term projects (30-90 days)** — Use for time-boxed engagements like consulting projects, audits, or seasonal work. The short window ensures access is automatically cleaned up when the engagement ends.
- **Medium-term partnerships (90-180 days)** — Appropriate for ongoing projects with a defined timeline, such as a product development cycle or a multi-month integration effort.
- **Long-term partnerships (180-365 days)** — For strategic partners with ongoing collaboration. Even here, avoid indefinite access. A 365-day duration with periodic access reviews is safer than unlimited access that no one remembers to revoke.

When a package duration expires, the guest loses access to all resources in the package but remains in your [directory](/docs/glossary/01-glossary) as a guest user. You can reassign the same package to extend their access. This deliberate re-assignment acts as a natural review point — someone must actively decide to continue the access rather than letting it persist by default.

> **Good to know:** Pair duration-based expiry with regular access reviews for defense in depth. Durations handle the common case automatically, while access reviews catch situations where access should have been revoked before the duration elapsed.

## Approval Policies

Access packages can be configured to require approval before assignments take effect, or to auto-approve and grant access immediately. The right choice depends on the sensitivity of the resources involved.

**Require approval when:**
- The package grants access to sensitive data (financial reports, HR systems, confidential project materials)
- The resources are subject to regulatory or compliance requirements
- You want a human review step to verify that each guest's access is justified

**Auto-approval may be acceptable when:**
- The package contains low-risk resources (general documentation sites, public-facing content libraries, broad communication channels)
- The package is scoped to a trusted, long-term partner with an established relationship
- Speed of onboarding is critical and the resources do not contain sensitive data

**Document your criteria.** Write down what qualifies a package for approval versus auto-approval and share this with all Operators and Admins. Consistent decision-making across reviewers prevents situations where one reviewer approves a request that another would have denied. You can include this guidance in the package description field.

## Regular Cleanup

Access packages and their assignments accumulate over time. Without periodic maintenance, you end up with stale packages and forgotten assignments that make audits difficult.

- **Review active assignments periodically.** At least monthly, check packages with high assignment counts and verify that each guest still needs access. The Entitlements page shows active assignment counts at a glance.
- **Revoke assignments proactively.** When you learn that a guest's involvement in a project has ended, revoke their assignment immediately rather than waiting for the duration to expire. The activity log will record the revocation for audit purposes.
- **Deactivate completed packages.** When a project ends, set the package status to Inactive. This prevents new assignments while keeping existing ones (and their audit history) intact. Existing active assignments will continue until they expire or are revoked.
- **Do not delete packages with historical assignments.** Even after all assignments have expired or been revoked, the package record provides audit context. Deleting it removes the ability to understand what access was granted in the past.

> **Good to know:** Use [access reviews](/docs/glossary/01-glossary) alongside manual cleanup for automated, recurring checks on who still needs access. Reviews can flag stale assignments that you might otherwise miss.

## Related Pages

- [Access Packages](/docs/users/entitlements/01-access-packages) — Creating and managing packages
- [Assignments](/docs/users/entitlements/02-assignments) — Assignment lifecycle and approvals
- [Troubleshooting](/docs/users/entitlements/04-troubleshooting) — Common issues and solutions
