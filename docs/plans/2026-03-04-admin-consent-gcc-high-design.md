# Admin Consent Button + GCC High Support

**Date:** 2026-03-04

## Overview

Two related features for the `/admin/graph` settings page:
1. A cloud environment selector that derives correct Microsoft endpoint URLs for Commercial vs GCC High
2. An "Admin Consent" button that opens a Microsoft popup for granting app permissions, with backend verification

## Cloud Environment

### CloudEnvironment Enum

New `app/Enums/CloudEnvironment.php` with cases `Commercial` and `GccHigh`.

Methods:
- `loginUrl()` — returns the login hostname
- `graphBaseUrl()` — returns the Graph API base URL
- `defaultScopes()` — returns the default scopes string

| Value | Login URL | Graph Base URL | Default Scopes |
|-------|-----------|----------------|----------------|
| `commercial` | `login.microsoftonline.com` | `https://graph.microsoft.com/v1.0` | `https://graph.microsoft.com/.default` |
| `gcc_high` | `login.microsoftonline.us` | `https://graph.microsoft.us/v1.0` | `https://graph.microsoft.us/.default` |

### Configuration

- Stored as `graph.cloud_environment` setting (default: `commercial`)
- Added to `config/graph.php` as `cloud_environment` key
- Dropdown on Graph settings page selects the environment
- When changed, scopes/base_url auto-populate with correct defaults (remain editable)

### Login URL Derivation

`MicrosoftGraphService::getAccessToken()` and `AdminGraphController::testConnection()` currently hardcode `login.microsoftonline.com`. Both will derive the login URL from the stored `CloudEnvironment` value.

## Admin Consent Flow

### Frontend (Graph.vue)

New "Admin Consent" section below "Test Connection":
- "Grant Admin Consent" button
- Fetches consent URL from `GET /admin/graph/consent`
- Opens popup via `window.open(url, '_blank', 'width=600,height=700')`
- Listens for `message` event from popup with consent result
- Shows success/error banner based on result

### Backend

**`GET /admin/graph/consent`** (auth required):
- Returns JSON with the admin consent URL
- URL format: `https://{login_url}/{tenant_id}/adminconsent?client_id={client_id}&redirect_uri={callback_url}`

**`GET /admin/graph/consent/callback`** (no auth required — Microsoft redirects here in popup):
- Receives `admin_consent=True` or `error`/`error_description` query params
- Renders a minimal Blade view (`admin/consent-callback.blade.php`)
- The Blade view uses `window.opener.postMessage()` to send result back to parent, then closes itself

## Files

### Create
- `app/Enums/CloudEnvironment.php`
- `resources/views/admin/consent-callback.blade.php`

### Modify
- `config/graph.php` — add `cloud_environment` default
- `app/Services/MicrosoftGraphService.php` — derive login URL from CloudEnvironment
- `app/Http/Controllers/Admin/AdminGraphController.php` — add `consentUrl()`, `consentCallback()`, update `testConnection()` login URL
- `app/Http/Requests/UpdateGraphSettingsRequest.php` — add `cloud_environment` validation
- `routes/admin.php` — add consent routes (consent URL behind auth, callback without auth)
- `resources/js/pages/admin/Graph.vue` — cloud environment dropdown, admin consent button with popup logic
