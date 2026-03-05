---
title: Trust Score
---

# Trust Score

Managing dozens or hundreds of partner organizations means you need a way to quickly assess which relationships need attention. The trust score gives you that at-a-glance capability.

## What Is the Trust Score?

The trust score is a **0-100 score** that Partner365 calculates for each partner organization based on their security configuration and guest activity. A higher score indicates a better-configured, lower-risk partnership. A lower score signals gaps that deserve your review.

The score is not a judgment of the partner organization itself. It reflects how well the **relationship** between your tenant and theirs is configured from a security standpoint. A trusted, long-standing partner can still have a low score if their cross-tenant policies are misconfigured or their guest accounts have gone stale.

> **Good to know:** The trust score updates automatically as Partner365 syncs data from Microsoft Graph. You do not need to trigger a recalculation manually. When you tighten a policy or clean up stale guests, the score will reflect the change on the next sync cycle.

## Score Factors

The trust score is composed of several weighted factors, each evaluating a different aspect of the partner relationship:

- **Policy restrictiveness** — Are cross-tenant access policies configured, or is the partner relying on wide-open defaults? Policies that target specific users, groups, or applications score higher than blanket allow-all rules.
- **MFA trust configuration** — Have you enabled MFA trust for this partner, and if so, does the partner actually enforce MFA on their end? Trusting MFA from a partner that does not enforce it weakens your security posture.
- **Device compliance trust** — Similar to MFA trust: are you trusting device compliance claims from the partner, and is that trust warranted?
- **Guest activity** — What is the ratio of active guests to stale guests for this partner? A high proportion of stale guests indicates poor lifecycle management.
- **B2B direct connect settings** — If B2B direct connect is configured, are the settings appropriately scoped or overly permissive?

Each factor is displayed as a pass/fail indicator in the partner detail breakdown, so you can see exactly which areas are contributing to or dragging down the overall score. See [Partner Details](../partners/03-partner-details.md) for the full breakdown view.

## Score Ranges

The trust score falls into three ranges:

| Range | Label | Guidance |
|-------|-------|----------|
| 0-40 | **Low** | Significant security concerns. Review this partner's configuration immediately. Wide-open policies, missing MFA trust, or a large number of stale guests are likely present. |
| 41-70 | **Medium** | Some configuration gaps exist. The relationship is functional but could be tightened. Consider whether policies are scoped appropriately and whether stale guests need cleanup. |
| 71-100 | **High** | Well-configured relationship. Policies are targeted, trust settings are appropriate, and guest lifecycle is managed. Maintain this through periodic reviews. |

> **Good to know:** A score of 100 does not mean zero risk. It means the configuration aligns with best practices as Partner365 can measure them. External factors like the partner's internal security practices are outside the score's scope.

## Improving a Partner's Score

If a partner's trust score is lower than you would like, here are the most impactful actions you can take:

1. **Configure restrictive policies** — Replace allow-all inbound or outbound rules with targeted policies that specify which users, groups, and applications are in scope. See [Cross-Tenant Policies](01-cross-tenant-policies.md) for guidance on policy configuration.
2. **Review MFA trust settings** — Only enable MFA trust for partners you have verified enforce MFA for their users. Trusting MFA claims from a partner with weak authentication policies gives you a false sense of security.
3. **Clean up stale guests** — Use the [Guest List](../guests/01-guest-list.md) to identify guests who have not signed in recently and remove those who no longer need access.
4. **Run access reviews regularly** — Periodic [access reviews](../access-reviews/01-overview.md) ensure that guest access stays current and justified. Reviews also demonstrate compliance with governance requirements.
5. **Scope B2B direct connect** — If you use B2B direct connect with a partner, ensure it is limited to the specific Teams channels or resources that require it rather than broadly enabled.

Each of these actions addresses a specific scoring factor, and the improvement will be visible after the next data sync. For definitions of terms used in this page, see the [Glossary](../glossary/01-glossary.md).
