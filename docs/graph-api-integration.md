# Graph API Integration

## Overview

Partner365 communicates with Microsoft Graph API v1.0 using a custom service layer built on Laravel's `Http` facade. No Microsoft SDK is used — all calls are direct HTTP requests.

## Service Architecture

```
MicrosoftGraphService               ← Core HTTP client + token management
├── CrossTenantPolicyService        ← Partner policy CRUD + tenant restrictions
├── CollaborationSettingsService    ← Authorization policy (invites + domains)
├── GuestUserService                ← Guest invitations + user management
├── TenantResolverService           ← Tenant info lookup
├── AccessReviewService             ← Access review definitions + remediation
├── ConditionalAccessPolicyService ← CA policy sync + partner mapping
├── SensitivityLabelService        ← Sensitivity label sync + partner mapping
└── TrustScoreService               ← Domain reputation scoring (uses DnsLookupService + RDAP)

FaviconService                       ← Partner favicon fetch + cache (independent, no Graph API)
```

## MicrosoftGraphService

The base service handles authentication and HTTP communication.

### Token Acquisition

Uses OAuth2 client credentials flow. The login URL is derived from the `CloudEnvironment` enum (`commercial` → `login.microsoftonline.com`, `gcc_high` → `login.microsoftonline.us`):

```
POST https://{loginUrl}/{tenantId}/oauth2/v2.0/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id={clientId}
&client_secret={clientSecret}
&scope={scopes}
```

The token is cached for 3500 seconds using `Cache::remember('msgraph_access_token', 3500, ...)`. This is just under the standard 3600-second token lifetime to avoid using expired tokens.

### Cloud Environment

The `CloudEnvironment` enum (`app/Enums/CloudEnvironment.php`) centralizes endpoint differences between Microsoft cloud environments:

| Environment | Login URL | Graph Base URL | Default Scopes |
|-------------|-----------|----------------|----------------|
| Commercial | `login.microsoftonline.com` | `graph.microsoft.com/v1.0` | `graph.microsoft.com/.default` |
| GCC High | `login.microsoftonline.us` | `graph.microsoft.us/v1.0` | `graph.microsoft.us/.default` |

The active environment is stored as the `graph.cloud_environment` setting (default: `commercial`). Both `MicrosoftGraphService::getAccessToken()` and `AdminGraphController::testConnection()` use it to derive the login URL.

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
| `getUserGroups(entraUserId)` | GET `/users/{id}/memberOf` | Group memberships (filtered to groups only) |
| `getUserApps(entraUserId)` | GET `/users/{id}/appRoleAssignments` | App role assignments |
| `getUserTeams(entraUserId)` | GET `/users/{id}/joinedTeams` | Teams memberships |
| `getUserSites(entraUserId)` | GET `/users/{id}/memberOf` → GET `/groups/{id}/sites/root` | SharePoint sites via M365 groups |

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

### Access Visibility (Live-Fetched)

The `getUserGroups`, `getUserApps`, `getUserTeams`, and `getUserSites` methods are live-fetched from Graph API (not synced to the local database). Results are cached for 5 minutes using Laravel's cache with keys like `guest_access:{entraId}:groups`.

**Group type resolution:** The `memberOf` endpoint returns all directory objects. The service filters to `#microsoft.graph.group` and classifies groups as:
- `microsoft365` — has `Unified` in `groupTypes`
- `security` — `securityEnabled` is true
- `distribution` — everything else

**Sites resolution:** SharePoint sites are derived from M365 group memberships. For each `Unified` group, the service calls `GET /groups/{id}/sites/root`. Groups whose site fails to resolve (e.g., no associated site) are silently skipped.

**App role resolution:** The `appRoleId` of `00000000-0000-0000-0000-000000000000` indicates "Default Access" (the zero GUID is Microsoft's convention for the default app role).

> **Required Permissions:** `Group.Read.All`, `Sites.Read.All` (already included in the app registration script)

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

## AccessReviewService

Manages access review definitions via Graph API and handles remediation actions locally.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `createDefinition(config)` | POST `/identityGovernance/accessReviews/definitions` | Create review definition in Graph |
| `deleteDefinition(review)` | DELETE `/identityGovernance/accessReviews/definitions/{id}` | Delete review definition from Graph |

### Local Operations

The service also handles operations that don't involve Graph API:

- `submitDecision(decision, verdict, justification, user)` — Records approve/deny decision locally
- `applyRemediations(instance)` — Processes denied decisions: disables or removes guest users via `GuestUserService`, flags partner reviews
- `createInstanceWithDecisions(review)` — Creates a new review instance and populates decisions for all in-scope subjects

### Remediation Actions

| Action | Guest Users | Partner Organizations |
|--------|-------------|----------------------|
| `flag_only` | Mark as denied | Mark as denied |
| `disable` | Disable via Graph API (`accountEnabled: false`) | N/A (forced to flag_only) |
| `remove` | Delete via Graph API | N/A (forced to flag_only) |

> **Required Permission:** `AccessReview.ReadWrite.All` (application permission)

## ConditionalAccessPolicyService

Syncs Conditional Access policies that target guest/external users and maps them to partner organizations.

### Endpoint

| Method | Path | Description |
|--------|------|-------------|
| `fetchPoliciesFromGraph()` | GET `/identity/conditionalAccess/policies` | List all CA policies |

### Sync Logic

1. Fetches all CA policies from Graph API
2. Filters to policies with `conditions.users.includeGuestsOrExternalUsers`
3. Parses grant controls, session controls, target applications, and external tenant scope
4. Upserts to `conditional_access_policies` table
5. Builds partner mappings via pivot table based on:
   - **All tenants** scope → maps to all partners
   - **Enumerated tenants** → maps only to partners whose `tenant_id` is in the `members` list
6. Records `matched_user_type` on the pivot (e.g., `b2bCollaborationGuest`, `b2bDirectConnectUser`)
7. Removes stale policies no longer returned by Graph

### Gap Detection

`getUncoveredPartners()` returns partners with zero rows in the pivot table — i.e., no CA policies target their guests.

> **Required Permission:** `Policy.Read.All` (application permission, already granted)

## SensitivityLabelService

Syncs Microsoft Information Protection sensitivity labels, label policies, and site-level label assignments. Maps labels to partner organizations based on policy scope and site sharing.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `fetchLabelsFromGraph()` | GET `/security/informationProtection/sensitivityLabels` | List all sensitivity labels |
| `fetchPoliciesFromGraph()` | GET `/security/informationProtection/sensitivityLabels/policies` | List label policies |
| `fetchSiteLabelsFromGraph()` | GET `/sites?$filter=...&$select=id,displayName,webUrl` + GET `/sites/{id}/sensitivityLabel` | Site label assignments |

### Sync Logic

1. **Labels** — Two-pass sync: creates/updates all labels first, then links parent-child relationships (avoids FK constraint issues with self-referencing)
2. **Policies** — Parses target type from `scopes.users.included` (all_users, specific_groups, all_users_and_guests) and stores assigned label IDs
3. **Site labels** — Fetches sites with sensitivity labels and checks external sharing status
4. **Partner mappings** — Clears and rebuilds via two strategies:
   - **Policy-based**: `all_users_and_guests`/`all_users` → maps to all partners; `specific_groups` → maps to partners with guest users in those groups
   - **Site-based**: Externally-shared labeled sites → maps to all partners

### Data Extraction

- **Scope** — Derived from `contentFormats`: `['file', 'email']` → `files_emails`, `['site', 'unifiedGroup']` → `sites_groups`
- **Protection type** — Derived from `protectionSettings`: encryption settings → `encryption`, watermark → `watermark`, header/footer → `header_footer`, none → `none`

### Gap Detection

`getUncoveredPartners()` returns partners with zero rows in the pivot table — i.e., no sensitivity labels are mapped to them.

> **Required Permissions:** `InformationProtection.Read.All`, `Sites.Read.All` (application permissions)

## Background Sync

Five Artisan commands sync data from Graph API to the local database, plus one daily scoring command:

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

### sync:access-reviews

1. Checks all active review instances for overdue status (past `due_at`) and marks them as `expired`
2. For recurring reviews past `next_review_at`, creates a new instance with decisions for all in-scope subjects
3. Updates `next_review_at` based on `recurrence_interval_days`
4. Logs sync results to `SyncLog`

### sync:conditional-access-policies

1. Fetches all CA policies via `ConditionalAccessPolicyService::syncPolicies()`
2. Filters to policies targeting guest/external users
3. Upserts `conditional_access_policies` table keyed by `policy_id`
4. Rebuilds partner pivot mappings with granular tenant/user-type matching
5. Removes stale policies no longer in Graph API
6. Logs sync results to `SyncLog`

### sync:sensitivity-labels

1. Fetches all sensitivity labels via `SensitivityLabelService::syncLabels()`
2. Two-pass: creates/updates all labels, then links parent-child relationships
3. Syncs label policies via `syncPolicies()` — parses target type and assigned labels
4. Syncs site label assignments via `syncSiteLabels()` — checks external sharing
5. Rebuilds partner mappings via `buildPartnerMappings()` — policy-based + site-based
6. Removes stale labels/policies no longer returned by Graph
7. Logs sync results to `SyncLog`

### score:partners

Runs daily to calculate trust scores for all partners with a domain set:

1. For each partner with a non-null `domain`, runs `TrustScoreService::calculateScore()`
2. Performs DNS lookups (DMARC, SPF, DKIM, DNSSEC) via `DnsLookupService`
3. Queries RDAP (`rdap.org`) for domain registration date
4. Combines DNS hygiene signals (60 points) with Entra ID metadata (40 points) into a 0-100 score
5. Stores `trust_score`, `trust_score_breakdown` (JSON), and `trust_score_calculated_at` on the partner record

Partners without a domain are skipped. Failures for individual partners are logged and do not block scoring of other partners.

### Schedule Registration

```php
// routes/console.php
Schedule::command('sync:partners')->everyFifteenMinutes();
Schedule::command('sync:guests')->everyFifteenMinutes();
Schedule::command('sync:access-reviews')->everyFifteenMinutes();
Schedule::command('sync:conditional-access-policies')->everyFifteenMinutes();
Schedule::command('sync:sensitivity-labels')->everyFifteenMinutes();
Schedule::command('score:partners')->daily();
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
