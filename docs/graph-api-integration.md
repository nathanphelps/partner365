# Graph API Integration

## Overview

Partner365 communicates with Microsoft Graph API v1.0 using a custom service layer built on Laravel's `Http` facade. No Microsoft SDK is used — all calls are direct HTTP requests.

## Service Architecture

```
MicrosoftGraphService               ← Core HTTP client + token management
├── CrossTenantPolicyService        ← Partner policy CRUD + tenant restrictions
├── CollaborationSettingsService    ← Authorization policy (invites + domains)
├── GuestUserService                ← Guest invitations + user management
└── TenantResolverService           ← Tenant info lookup
```

## MicrosoftGraphService

The base service handles authentication and HTTP communication.

### Token Acquisition

Uses OAuth2 client credentials flow:

```
POST https://login.microsoftonline.com/{tenantId}/oauth2/v2.0/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id={clientId}
&client_secret={clientSecret}
&scope=https://graph.microsoft.com/.default
```

The token is cached for 3500 seconds using `Cache::remember('msgraph_access_token', 3500, ...)`. This is just under the standard 3600-second token lifetime to avoid using expired tokens.

### HTTP Methods

All methods acquire a token, build the full URL from `config('graph.base_url')`, and throw `GraphApiException` on failure:

```php
$service->get('/users');                          // GET
$service->post('/invitations', ['email' => ...]); // POST
$service->patch('/users/{id}', ['data' => ...]);  // PATCH
$service->delete('/users/{id}');                   // DELETE
```

### Error Handling

`GraphApiException` is thrown on any non-2xx response. It includes:
- HTTP status code
- Error message from the Graph API response body
- A static `fromResponse()` factory for consistent error creation

## CrossTenantPolicyService

Manages cross-tenant access policies for partner organizations.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `listPartners()` | GET `/policies/crossTenantAccessPolicy/partners` | List all partner configs |
| `getPartner(tenantId)` | GET `/policies/crossTenantAccessPolicy/partners/{tenantId}` | Get specific partner |
| `createPartner(tenantId, config)` | POST `/policies/crossTenantAccessPolicy/partners` | Create new partner policy |
| `updatePartner(tenantId, config)` | PATCH `/policies/crossTenantAccessPolicy/partners/{tenantId}` | Update partner policy |
| `deletePartner(tenantId)` | DELETE `/policies/crossTenantAccessPolicy/partners/{tenantId}` | Remove partner policy |
| `getDefaults()` | GET `/policies/crossTenantAccessPolicy/default` | Get default policy |
| `updateDefaults(config)` | PATCH `/policies/crossTenantAccessPolicy/default` | Update default policy |
| `getTenantRestrictions(tenantId)` | GET `/policies/crossTenantAccessPolicy/partners/{tenantId}` | Get tenant restrictions config |
| `updateTenantRestrictions(tenantId, config)` | PATCH `/policies/crossTenantAccessPolicy/partners/{tenantId}` | Update tenant restrictions |

### Graph API Policy Structure

The Graph API expects a nested JSON structure for cross-tenant policies. Partner365 maps simple boolean toggles to this structure in `PartnerOrganizationController::buildGraphConfig()`:

```php
// Input: simple booleans from the UI
$validated = [
    'mfa_trust_enabled' => true,
    'device_trust_enabled' => false,
    'b2b_inbound_enabled' => true,
    'b2b_outbound_enabled' => false,
    'direct_connect_inbound_enabled' => true,
    'direct_connect_outbound_enabled' => false,
    'tenant_restrictions_enabled' => true,
    'tenant_restrictions_json' => ['applications' => ['accessType' => 'blocked', 'targets' => [...]]],
];

// Output: Graph API structure
$config = [
    'inboundTrust' => [
        'isMfaAccepted' => true,
        'isCompliantDeviceAccepted' => false,
    ],
    'b2bCollaborationInbound' => [
        'usersAndGroups' => [
            'accessType' => 'allowed',   // or 'blocked'
        ],
    ],
    'b2bCollaborationOutbound' => [
        'usersAndGroups' => [
            'accessType' => 'blocked',
        ],
    ],
    'b2bDirectConnectInbound' => [
        'usersAndGroups' => [
            'accessType' => 'allowed',
        ],
    ],
    'b2bDirectConnectOutbound' => [
        'usersAndGroups' => [
            'accessType' => 'blocked',
        ],
    ],
    'tenantRestrictions' => [
        'applications' => ['accessType' => 'blocked', 'targets' => [...]],
    ],
];
```

## CollaborationSettingsService

Manages the tenant-wide authorization policy that controls guest invitation permissions and domain restrictions.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `getSettings()` | GET `/policies/authorizationPolicy` | Get invitation and domain settings |
| `updateSettings(config)` | PATCH `/policies/authorizationPolicy` | Update invitation and domain settings |

### Graph API Structure

```php
// GET response
[
    'allowInvitesFrom' => 'adminsAndGuestInviters',
    'allowedToInvite' => [['allowedDomainName' => 'contoso.com']],
    'blockedFromInvite' => [],
]

// PATCH payload
[
    'allowInvitesFrom' => 'everyone',
    'allowedToInvite' => [['allowedDomainName' => 'contoso.com']],
    'blockedFromInvite' => [],
]
```

> **Required Permission:** `Policy.ReadWrite.Authorization` (application permission)

## GuestUserService

Manages B2B guest user lifecycle.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `listGuests()` | GET `/users?$filter=userType eq 'Guest'&$top=999` | List all guest users |
| `getUser(userId)` | GET `/users/{userId}` | Get specific user |
| `invite(email, redirectUrl, message, sendEmail)` | POST `/invitations` | Send B2B invitation |
| `deleteUser(userId)` | DELETE `/users/{userId}` | Remove guest user |
| `updateUser(userId, data)` | PATCH `/users/{userId}` | Update guest profile |

### Selected Fields

The service requests only the fields it needs via `$select`:

```
id, displayName, mail, userPrincipalName, userType,
accountEnabled, createdDateTime, externalUserState, signInActivity
```

### Invitation Payload

```json
{
    "invitedUserEmailAddress": "guest@external.com",
    "inviteRedirectUrl": "https://myapp.com",
    "sendInvitationMessage": true,
    "invitedUserMessageInfo": {
        "customizedMessageBody": "Optional custom message"
    }
}
```

The `invitedUserMessageInfo` key is only included when a custom message is provided.

## TenantResolverService

Resolves a tenant UUID to human-readable information.

### Endpoint

```
GET /tenantRelationships/findTenantInformationByTenantId(tenantId='{uuid}')
```

### Response

```json
{
    "tenantId": "550e8400-e29b-41d4-a716-446655440000",
    "displayName": "Contoso Ltd",
    "defaultDomainName": "contoso.com"
}
```

### Validation

The service validates UUID format before making the API call using a regex pattern. Invalid UUIDs throw an `InvalidArgumentException` without hitting the API.

## Background Sync

Two Artisan commands sync data from Graph API to the local database every 15 minutes:

### sync:partners

1. Fetches all partner configurations via `CrossTenantPolicyService::listPartners()`
2. For each partner, resolves tenant display name and domain via `TenantResolverService`
3. Maps Graph API policy structure back to local boolean flags
4. Upserts `partner_organizations` table keyed by `tenant_id`
5. Stores the raw JSON response in `raw_policy_json`

### sync:guests

1. Fetches all guest users via `GuestUserService::listGuests()`
2. For each guest, extracts email from `mail` or `otherMails[0]`
3. Matches email domain to partner organizations for auto-linking
4. Upserts `guest_users` table keyed by `entra_user_id`
5. Sets `invitation_status` to `accepted` (any user returned by Graph exists in Entra)

### Schedule Registration

```php
// routes/console.php
Schedule::command('sync:partners')->everyFifteenMinutes();
Schedule::command('sync:guests')->everyFifteenMinutes();
```

### Running the Scheduler

In production, add this cron entry:

```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Or use `php artisan schedule:work` for local development.

## Rate Limiting

Microsoft Graph API has rate limits (typically 10,000 requests per 10 minutes per app). Partner365's sync cycle and normal usage should stay well within these limits. If you encounter 429 responses, consider:

- Increasing the sync interval via `MICROSOFT_GRAPH_SYNC_INTERVAL`
- Implementing pagination for `listGuests()` if you have thousands of guests
- Adding exponential backoff retry logic to `MicrosoftGraphService`
