---
title: Adding Partners
---

# Adding Partners

Operators and admins can add new partner organizations.

## Steps

1. Click the **Add Partner** button on the Partners page
2. Enter the partner's **domain name** (e.g., contoso.com) or **tenant ID**
3. Click **Resolve Tenant** — Partner365 will look up the tenant information via Microsoft Graph API
4. Review the resolved tenant details (display name, tenant ID)
5. Select a **category** for the partner (Vendor, Customer, Subsidiary, etc.)
6. Optionally select a **template** to apply a pre-configured cross-tenant access policy
7. Click **Create Partner**

## Templates

If an admin has configured templates, you can apply one during partner creation to automatically set up cross-tenant access policies. This ensures consistent security settings across similar partner types.

## What Happens Next

After creating a partner:
- The cross-tenant access policy is created in Microsoft Entra ID via Graph API
- The partner appears in your partner list with its initial trust score
- Background sync will keep the local data in sync with Entra ID
