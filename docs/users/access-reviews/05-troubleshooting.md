---
title: Troubleshooting
---

# Access Review Troubleshooting

This page covers common issues you may encounter when creating, conducting, or remediating access reviews, along with steps to resolve them.

## No Instances Generated

After creating a review, you expect to see one instance per [guest user](/docs/glossary/01-glossary) in scope. If the review shows zero instances, the most likely causes are:

- **The scoped partner has no guests.** Navigate to the **Partners** page and check the guest count for the selected [partner organization](/docs/glossary/01-glossary). If the count is zero, there are no guests to review. This can happen if guests were removed between when you planned the review and when you created it.
- **Guests were removed externally.** If guests were deleted directly in Microsoft Entra ID (outside of Partner365), the local database may still show a guest count, but instance generation uses live data. Trigger a guest sync from **Admin > Sync** to reconcile the local database, then create a new review.
- **A sync or generation error occurred.** Check the [activity log](/docs/glossary/01-glossary) for error entries around the time the review was created. Errors during instance generation are logged with details about what failed. If you find an error, address the underlying cause and create a new review.

> **Good to know:** Always verify guest counts on the Partners page before creating a review. This avoids the confusion of empty reviews and ensures your time is spent on reviews that will produce actionable decisions.

## Remediation Failed for Some Guests

When you apply remediations, Partner365 sends a removal request to the Microsoft Graph API for each denied guest. Individual removals can fail while others succeed. Common causes include:

- **Insufficient API permissions.** The application's Graph API credentials may lack the permission required to delete guest accounts. Verify that the app registration has the `User.ReadWrite.All` or equivalent permission. Contact your Azure AD administrator if permissions need to be updated.
- **Guest account already removed.** If someone removed the guest directly in Entra ID after the review decision was made but before remediation was applied, the Graph API returns a "not found" error. This is harmless — the guest is already gone. No action is needed.
- **Transient Graph API error.** Microsoft Graph occasionally returns 429 (throttled) or 503 (service unavailable) errors during high-volume operations. These are temporary. Wait a few minutes and retry by removing the guest manually from their detail page in Partner365.
- **Conditional access or policy conflict.** In rare cases, tenant-level policies may prevent programmatic deletion of certain guest accounts. Check the Graph API error message in the activity log for specifics and consult your Entra ID administrator.

After addressing the underlying cause, remove failed guests individually from their guest detail page using the **Remove Guest** action.

## Review Shows Wrong Guest Count

If the number of instances in a review does not match what you expected based on the partner's guest count, the most likely explanation is stale data:

- **Guest data was not synced recently.** Partner365 syncs guest data from Microsoft Entra ID on a schedule (every 15 minutes by default), but changes made in Entra ID between syncs are not reflected until the next sync completes. Before creating a review, go to **Admin > Sync** and trigger a manual guest sync to ensure you are working with the latest data.
- **Guests were added or removed between sync and review creation.** If guests changed between your last sync and the moment you created the review, the instance count will reflect the state at creation time. This is expected behavior — reviews capture a point-in-time snapshot of the guest population.

> **Good to know:** For the most accurate reviews, trigger a manual sync immediately before creating the review. This minimizes the window for data drift between the sync and review creation.

## Can't Create a Review

If the **Create Review** button is disabled or you receive an error when attempting to create a review, check the following:

- **Role requirements.** Only users with the **Operator** or **Admin** [role](/docs/glossary/01-glossary) can create reviews. If you have the Viewer role, ask an administrator to upgrade your access.
- **No partners with guests.** At least one partner organization must exist in Partner365 with associated guest users. If your tenant has no partners or no guests, there is nothing to review. Add partners and invite guests first, or wait for the next sync cycle to import them.
- **Validation errors.** Ensure all required fields are filled in: name, review type, due date, and reviewer. If you selected a partner-scoped review, a scope partner must also be selected. Check for validation messages next to each field.

If none of these apply and you are still unable to create a review, check the [activity log](/docs/glossary/01-glossary) for error details and contact your system administrator.
