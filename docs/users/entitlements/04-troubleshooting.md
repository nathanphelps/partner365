---
title: Troubleshooting
---

# Entitlement Troubleshooting

This page covers the most common issues you may encounter when working with [access packages](/docs/entitlements/access-packages) and [assignments](/docs/entitlements/assignments), along with steps to diagnose and resolve them.

## Assignment Stuck in Pending

**Symptom:** An assignment request has been in the "Pending Approval" state for an extended period and the guest is waiting for access.

**Cause:** No Operator or Admin has approved (or denied) the request yet. Partner365 does not currently send email notifications for pending approvals, so reviewers must actively check for new requests.

**Resolution:**

1. Open the **Dashboard** and look under "Pending Approvals" for the request.
2. Alternatively, navigate to the specific access package's detail page and check the assignments table for pending entries.
3. Ensure that an Operator or Admin is available and aware that requests need review. If your team is small, consider establishing a daily routine to check the Dashboard for pending items.
4. If the request is no longer needed, the requester or an Admin can cancel it from the assignment detail view.

> **Good to know:** If pending requests are frequently missed, consider designating a specific team member as the daily approvals reviewer, or add a Dashboard check to your team's daily standup routine.

## Guest Didn't Get Access After Approval

**Symptom:** An assignment shows as "Active" but the guest reports they cannot access one or more of the resources in the package.

**Cause:** The Microsoft Graph API call to add the guest to a group or SharePoint site may have failed silently, or there may be a propagation delay.

**Resolution:**

1. Check the [activity log](/docs/glossary/glossary) for the assignment's approval event. Look for any error messages associated with the provisioning step.
2. Verify that the guest's [invitation](/docs/glossary/glossary) has been accepted. A guest in "Pending Acceptance" status cannot be added to most resources. Navigate to the guest's profile to check their invitation status.
3. Confirm that the groups and SharePoint sites included in the package still exist in your tenant. If a resource was deleted or renamed after the package was created, provisioning will fail for that resource.
4. If the activity log shows errors, try revoking the assignment and creating a new one. This re-triggers the provisioning process.
5. If the issue persists, check the Microsoft Entra admin center directly to verify whether the guest was added to the group. The problem may be on the resource side (e.g., a conditional access policy blocking the guest).

## Approval Not Showing Up

**Symptom:** A reviewer expected to see a pending approval on the Dashboard or entitlement detail page, but nothing is there.

**Cause:** This can happen for several reasons, depending on the package configuration and the reviewer's role.

**Resolution:**

1. **Check the package's approval setting.** If the package does not require approval, assignments are auto-approved and skip the review queue entirely. Verify the package policy on its detail page.
2. **Verify the reviewer's role.** Only users with the Operator or Admin [role](/docs/glossary/glossary) can see and act on pending approvals. Users with the Viewer role cannot approve requests and will not see the approval controls.
3. **Check whether the request was already handled.** Another Operator or Admin may have approved or denied the request. Look in the assignments table for recently approved or denied entries.
4. **Confirm the request was submitted.** Ask the person who initiated the request to verify it was actually submitted. If the form submission failed (e.g., a network error), no request would have been created.

## Expired Assignment Didn't Remove Access

**Symptom:** A package assignment has passed its expiry date, but the guest still has access to the resources.

**Cause:** Access removal on expiry is handled by the background queue worker. If the queue is not running or encountered an error, the removal may not have been processed.

**Resolution:**

1. Check that the **queue worker is running**. Navigate to **Admin > Sync** to see queue status. If the queue is stopped or backed up, expired assignments will not be processed until it catches up.
2. If the queue is healthy, check the [activity log](/docs/glossary/glossary) for the expiry event. Look for error messages that indicate a failed Graph API call (e.g., the group no longer exists, or a transient API error occurred).
3. As an immediate fix, **manually revoke** the assignment from the entitlement detail page. This triggers a fresh removal attempt through the queue.
4. If manual revocation also fails, you may need to remove the guest from the specific groups or SharePoint sites directly through the Microsoft Entra admin center or SharePoint admin center.

> **Good to know:** The background queue processes expired assignments on a regular schedule. A short delay (up to 15 minutes) between the expiry time and actual access removal is normal. If the delay extends beyond that, investigate the queue health.

## Related Pages

- [Access Packages](/docs/entitlements/access-packages) — Package configuration and creation
- [Assignments](/docs/entitlements/assignments) — Assignment lifecycle and management
- [Best Practices](/docs/entitlements/best-practices) — Design guidance to avoid common issues
