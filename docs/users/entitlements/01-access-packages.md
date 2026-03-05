---
title: Access Packages
---

# Entitlements — Access Packages

Access packages are the core building block of [entitlement management](/docs/glossary/01-glossary) in Partner365. They let you define a standard set of resources that external guests need for a specific collaboration, then grant or revoke that entire set in a single action.

## What Are Access Packages?

When you collaborate with an external [partner organization](/docs/glossary/01-glossary), guest users typically need access to several resources at once — a Teams group for communication, a SharePoint site for shared documents, and perhaps a security group that controls access to a line-of-business application. Without access packages, you would need to add each guest to each resource individually and remember to remove them from every one when the collaboration ends.

An access package bundles all of those related resources into a single, reusable unit. When a guest is assigned a package, they automatically receive access to every group and SharePoint site it contains. When the assignment is revoked or expires, all of that access is removed at once. This eliminates the risk of orphaned permissions where a guest keeps access to one resource after being removed from another.

> **Good to know:** Think of access packages as pre-built access bundles. Instead of manually adding a guest to 5 different groups and 3 SharePoint sites, create a package once and assign it with a single action. This standardizes what access looks like for a given collaboration and makes cleanup predictable.

## When to Use Access Packages

Access packages are the right tool when:

- **Multiple guests need the same set of resources** — rather than configuring each guest individually, define the package once and assign it repeatedly.
- **You want approval workflows** — packages can require Operator or Admin approval before access is granted, adding a review step for sensitive resources.
- **You need time-limited access** — every package has a duration. When it expires, access is automatically removed without manual intervention.
- **You want centralized lifecycle management** — all assignments for a package are visible in one place, making audits and access reviews straightforward.

Access packages may not be necessary when a guest needs one-off access to a single resource. In that case, direct group membership through the [guest management](/docs/users/guests/01-overview) page may be simpler. However, even for simple cases, packages provide better auditability and automatic expiry, so consider using them as a default.

## Viewing Packages

The **Entitlements** page lists all access packages in a sortable table. Each column provides key information at a glance:

| Column | Description |
|---|---|
| **Name** | The display name of the package. Choose something descriptive — see [Best Practices](/docs/users/entitlements/03-best-practices) for naming guidance. |
| **Description** | A short summary of what the package provides and when to use it. |
| **Partner** | The [partner organization](/docs/glossary/01-glossary) this package is associated with. Each package is scoped to a single partner. |
| **Resources** | The total count of groups and SharePoint sites bundled into the package. Click through to the detail page to see the full list. |
| **Active Assignments** | The number of guest users who currently hold this package. A high number may warrant an [access review](/docs/glossary/01-glossary). |
| **Duration** | How long access lasts (in days) from the date of assignment. When this period elapses, the assignment expires and access is automatically removed. |
| **Status** | Whether the package is **Active** (available for new assignments) or **Inactive** (no new assignments can be created, but existing ones remain until they expire or are revoked). |

## Creating Packages

Operators and Admins can create new access packages through a four-step wizard. Click **Create Package** on the Entitlements page to begin.

1. **Select Partner Organization** — Choose which partner this package is for. Only [guest users](/docs/glossary/01-glossary) from that partner organization will be eligible for assignment. This scoping prevents accidentally granting access to guests from the wrong organization.

2. **Add Resources** — Search for and select the groups and SharePoint sites that should be included. You can add multiple resources of both types. Each resource appears with its display name and type so you can verify your selections before proceeding.

3. **Configure Policy** — Set the access duration in days (how long each assignment lasts before automatic expiry) and choose whether approval is required. When approval is required, new assignment requests enter a review queue before access is granted. When approval is not required, assignments take effect immediately.

4. **Review and Create** — A summary screen shows the partner, all selected resources, and the policy settings. Confirm everything looks correct and click **Create**. The package is immediately available for assignments.

For guidance on structuring packages effectively, see [Best Practices](/docs/users/entitlements/03-best-practices).

> **Good to know:** You can edit a package after creation to add or remove resources, change the duration, or toggle the approval requirement. Changes to resources apply to new assignments only — existing assignments retain access to whatever resources were in the package at the time of assignment.

## Related Pages

- [Assignments](/docs/users/entitlements/02-assignments) — Managing who has access to a package
- [Best Practices](/docs/users/entitlements/03-best-practices) — Design guidance for effective packages
- [Troubleshooting](/docs/users/entitlements/04-troubleshooting) — Common issues and solutions
