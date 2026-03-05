---
title: Cross-Tenant Access Policies
---

# Cross-Tenant Access Policies

Cross-tenant access policies are the foundation of secure external collaboration in Microsoft 365. They determine the boundaries of what partner organizations can and cannot do when interacting with your tenant, and vice versa. This guide explains the core concepts so you can make informed decisions when configuring partners in Partner365.

## What Are Cross-Tenant Access Policies?

In Microsoft Entra ID, cross-tenant access policies are a set of rules that govern how your organization collaborates with specific external tenants. They control three key questions:

- **Who** from an external organization can be invited into your tenant?
- **What** resources can external users access once they arrive?
- **Which** trust relationships exist between your organization and the partner?

Rather than managing these settings manually in the Entra admin center for each partner, Partner365 creates and manages these policies through the Microsoft Graph API. This gives you a single interface for partner lifecycle management, from onboarding through ongoing policy maintenance.

> **Good to know:** Every Entra ID tenant has these policies available, but they only take effect when you actively configure them. Until you do, the tenant-wide defaults apply to all external collaboration.

## Inbound vs Outbound

Cross-tenant policies are split into two directions, and understanding the distinction is essential.

**Inbound** settings control what partner users can do in *your* tenant. For example, inbound B2B collaboration settings determine whether users from Contoso can be invited as guests in your organization, and which of your applications they can access once invited.

**Outbound** settings control what *your* users can do in the *partner's* tenant. For example, outbound B2B collaboration settings determine whether your employees can accept guest invitations from Contoso and which of their applications your users may access.

Think of it as a two-way door: inbound is the door into your house, outbound is the door into theirs. You control your side; the partner controls theirs.

> **Good to know:** Outbound settings only govern what your tenant *allows*. The partner organization must also permit your users on their inbound side. Both sides must agree for collaboration to work.

## B2B Collaboration vs B2B Direct Connect

Entra ID offers two distinct mechanisms for external collaboration, and each can be configured independently for inbound and outbound access.

**B2B Collaboration** creates guest accounts in the target tenant. When you invite an external user via B2B collaboration, a guest user object appears in your directory. That guest can then access resources you have shared. This is the traditional model and works well for granting access to applications, SharePoint sites, and Teams.

**B2B Direct Connect** allows users to participate in shared resources, most notably Teams shared channels, without creating a guest account in either tenant. The user remains in their home tenant and authenticates there, but gains access to the specific shared channel or resource.

This creates four distinct policy areas to configure for each partner:

1. **Inbound B2B Collaboration** -- Which partner users can be invited as guests in your tenant
2. **Outbound B2B Collaboration** -- Which of your users can be guests in the partner's tenant
3. **Inbound B2B Direct Connect** -- Which partner users can access your shared channels
4. **Outbound B2B Direct Connect** -- Which of your users can access the partner's shared channels

> **Good to know:** Most organizations rely primarily on B2B collaboration. B2B direct connect is newer and mainly relevant if you use Teams shared channels extensively. If you are unsure which to configure, start with B2B collaboration settings. See [B2B Collaboration](/docs/concepts/b2b-collaboration) for a deeper discussion.

## Policy Composition

Policies in Entra ID follow a layered model:

**Tenant default policy** acts as the baseline. It applies to every external organization that does not have a partner-specific policy. Out of the box, the default policy typically blocks most external access, but you can adjust it to be more or less permissive depending on your organization's risk tolerance.

**Partner-specific policies** override the default for a particular partner. When you add a partner in Partner365 and configure their access settings, you are creating or updating a partner-specific policy that takes precedence over the default.

For each policy area (inbound collaboration, outbound collaboration, etc.), you have three options:

- **Allow all** -- All users, groups, and applications are permitted
- **Block all** -- No access is granted
- **Target specific** -- Only specified users, groups, or applications are permitted

> **Good to know:** If you are unsure, start with the default policy and only create partner-specific overrides when needed. This keeps your configuration manageable and avoids policy sprawl. You can review partner-specific settings on the [Partner Details](/docs/partners/partner-details) page.

## Trust Settings

Trust settings determine whether your tenant accepts security claims from the partner organization. There are two primary trust categories:

**MFA trust** lets your tenant accept multi-factor authentication claims from the partner's Entra ID. When enabled, if a partner user has already completed MFA in their home tenant, your tenant will honor that claim rather than prompting them again. This improves the user experience without reducing security, provided you trust the partner's MFA policies.

**Device compliance trust** lets your tenant accept device compliance and hybrid Entra join status from the partner. When enabled, conditional access policies in your tenant can evaluate whether the partner user's device meets their home organization's compliance requirements.

When to enable these settings depends on your relationship with the partner:

- **Trusted subsidiaries or close partners** -- Enable both. These organizations likely have security standards comparable to yours.
- **Unknown vendors or new partners** -- Leave trust settings disabled until you have evaluated their security posture. See [Trust Score](/docs/concepts/trust-score) for how Partner365 helps you assess partner trustworthiness.

> **Good to know:** Trust settings only affect how conditional access evaluates the partner's claims. They do not grant any additional access on their own. You still need the appropriate inbound collaboration or direct connect settings in place.

## How Partner365 Maps to the Entra Admin Center

Everything you configure in Partner365 corresponds to settings found under **External Identities > Cross-tenant access settings** in the Microsoft Entra admin center. Specifically:

| Partner365 | Entra Admin Center |
|---|---|
| Partner list | Cross-tenant access settings > Organizational settings |
| Partner access configuration | Partner-specific inbound/outbound policies |
| Default policy settings | Default settings tab |
| Trust configuration | Trust settings within each partner policy |

Partner365 manages these settings via the Microsoft Graph API, so you do not need to switch between tools or navigate the admin center for routine partner management. Changes made in Partner365 are reflected in Entra ID and vice versa, since they operate on the same underlying policies.

> **Good to know:** If you make changes directly in the Entra admin center, Partner365 will detect them during its next sync cycle and update its local records accordingly.

## Further Reading

- [B2B Collaboration](/docs/concepts/b2b-collaboration) -- Guest account lifecycle and invitation flow
- [Trust Score](/docs/concepts/trust-score) -- How Partner365 evaluates partner security posture
- [Partner Details](/docs/partners/partner-details) -- Configuring policies for a specific partner
- [Glossary](/docs/glossary/glossary) -- Definitions of key terms used throughout this documentation
