---
title: Inviting Guests
---

# Inviting Guests

Operators and admins can invite external users as B2B guests.

## Steps

1. Navigate to the Guests page and click **Invite Guest**
2. Select the **Partner Organization** the guest belongs to
3. Enter the guest's **email address** and **display name**
4. Optionally add a **personal message** for the invitation email
5. Click **Send Invitation**

## What Happens

- An invitation is sent via Microsoft Graph API
- The guest receives an email with a redemption link
- The guest appears in your list with "Pending" status
- Once they accept, their status changes to "Accepted"

## Failed Invitations

If an invitation fails (invalid email, policy block, etc.), the status shows "Failed". You can view the error details on the guest's detail page and retry the invitation.
