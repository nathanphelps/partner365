---
title: Best Practices
---

# Best Practices

Managing external partners effectively requires thoughtful policies and regular review. This page covers recommendations for when to add partners, how to configure them, and how to keep your partner roster clean over time.

## When to Add a Partner

Add a partner organization to Partner365 when you have ongoing collaboration that benefits from policy control and visibility. Common scenarios include:

- A vendor providing services that require access to your Microsoft 365 environment.
- A customer organization whose users need guest access to shared resources.
- A subsidiary or affiliate that shares Teams channels or SharePoint sites.

One-off guest invitations do not necessarily require a partner entry. However, even for smaller relationships, adding a partner gives you [cross-tenant access policy](/docs/concepts/01-cross-tenant-policies) control and [trust score](/docs/concepts/03-trust-score) tracking — both of which are valuable if the relationship grows.

> **Good to know:** When in doubt, add the partner. The overhead is minimal, and the visibility it provides is worth it. You can always delete the partner later if the collaboration ends.

## Choosing the Right Category

Categories help you organize and filter your partner list. Choose the category that best reflects the nature of the relationship:

- **Vendor** — External service providers, consultants, or contractors.
- **Customer** — Organizations you provide services to.
- **Subsidiary** — Organizations under the same parent company or corporate group.
- **Partner** — Joint ventures, co-development relationships, or strategic alliances.
- **Other** — Anything that does not fit the above.

Categories are used for filtering on the [partner list](/docs/users/partners/01-viewing-partners) and in reporting. Consistent categorization across your team makes it easier to apply policies uniformly and identify patterns.

## Policy Configuration Recommendations

A general principle: start restrictive and open access as the relationship requires it. It is easier to grant additional access than to revoke it after the fact.

- **Vendors** — Limit [B2B collaboration](/docs/glossary/01-glossary) to specific applications relevant to the engagement. Avoid enabling B2B Direct Connect unless there is a clear need for shared channels. Do not trust MFA unless you have verified the vendor's MFA enforcement.
- **Subsidiaries** — These are typically your most trusted partners. Consider enabling MFA trust and device compliance trust if the subsidiary uses the same identity and device management standards. B2B Direct Connect is often appropriate here.
- **Customers** — Grant moderate inbound collaboration access. Avoid enabling B2B Direct Connect unless the customer relationship requires shared Teams channels. MFA trust depends on the customer's security maturity.
- **Other / Unknown** — Apply the most restrictive policies. Use the tenant default policy until you understand the relationship better.

> **Good to know:** Use [templates](/docs/admin/05-templates) to codify these recommendations. A well-designed set of templates (e.g., "Vendor - Restricted", "Subsidiary - Trusted") ensures consistency and reduces the chance of misconfiguration.

## Review Cadence

Regular reviews keep your partner configurations aligned with actual business needs and security requirements.

- **Monthly** — Check trust scores for all partners. Investigate any partner scoring below 50 — this typically indicates overly permissive policies, stale guests, or missing MFA trust alignment.
- **Quarterly** — Run [access reviews](/docs/users/guests/04-access-reviews) for guest users from each partner. Confirm that active guests still need access and remove those who do not.
- **Annually** — Review the full partner list with stakeholders. Confirm each partner relationship is still active and that categories are accurate.

Sorting the partner list by trust score (ascending) is the fastest way to identify partners that need attention. See [Viewing Partners](/docs/users/partners/01-viewing-partners) for sorting instructions.

## Cleaning Up Stale Partners

When collaboration with a partner ends, remove the partner from Partner365 to keep your environment clean and reduce your external attack surface.

Before deleting a partner:

1. **Review guest users** — Navigate to the partner's [detail page](/docs/users/partners/03-partner-details) and review the guest user list. Determine which guests should be removed.
2. **Run an access review** — If the partner has many guests, run an access review to systematically identify and remove unnecessary accounts.
3. **Remove or disable guests** — Delete or disable guest accounts that are no longer needed.
4. **Delete the partner** — Once guest cleanup is complete, delete the partner from Partner365. This removes the cross-tenant access policy from Entra ID, so the tenant default policy will apply to any remaining interactions.

> **Good to know:** Deleting a partner does not automatically remove its guest users from your tenant. Always handle guest cleanup first to avoid orphaned accounts with no policy oversight.
