---
title: Viewing Policies
---

# Conditional Access Policies

The Conditional Access page shows policies from Microsoft Entra ID that affect external/guest users.

## Policy List

Each policy displays:
- **Name** — Policy display name
- **State** — Enabled, Disabled, or Report-only
- **Conditions** — What triggers the policy (user types, apps, locations)
- **Controls** — What the policy enforces (MFA, block, compliant device)

## Policy Details

Click on a policy to see its full configuration:
- **Included/Excluded Users and Groups** — Who the policy applies to
- **Target Applications** — Which apps are affected
- **Conditions** — Risk levels, device platforms, locations
- **Grant/Session Controls** — What's enforced when conditions match

## Important Notes

- Conditional access policies are **read-only** in Partner365 — they can only be modified in the Microsoft Entra admin center
- Partner365 syncs these policies to give you visibility into what controls affect your external users
