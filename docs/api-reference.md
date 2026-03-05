# API Reference

All routes require authentication (`auth` + `verified` middleware) unless noted otherwise.

## Routes

### Dashboard

| Method | URI | Controller | Name | Auth |
|--------|-----|-----------|------|------|
| GET | `/` | Inertia | `home` | No |
| GET | `/dashboard` | `DashboardController` | `dashboard` | Yes |

### Partners

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/partners` | `PartnerOrganizationController@index` | `partners.index` | Any |
| GET | `/partners/create` | `PartnerOrganizationController@create` | `partners.create` | Operator+ |
| POST | `/partners` | `PartnerOrganizationController@store` | `partners.store` | Operator+ |
| GET | `/partners/{partner}` | `PartnerOrganizationController@show` | `partners.show` | Any |
| PATCH | `/partners/{partner}` | `PartnerOrganizationController@update` | `partners.update` | Operator+ |
| DELETE | `/partners/{partner}` | `PartnerOrganizationController@destroy` | `partners.destroy` | Admin |
| POST | `/partners/resolve-tenant` | `PartnerOrganizationController@resolveTenant` | `partners.resolve-tenant` | Any |

### Guests

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/guests` | `GuestUserController@index` | `guests.index` | Any |
| GET | `/guests/create` | `GuestUserController@create` | `guests.create` | Operator+ |
| POST | `/guests` | `GuestUserController@store` | `guests.store` | Operator+ |
| GET | `/guests/{guest}` | `GuestUserController@show` | `guests.show` | Any |
| DELETE | `/guests/{guest}` | `GuestUserController@destroy` | `guests.destroy` | Admin |
| PATCH | `/guests/{guest}` | `GuestUserController@update` | `guests.update` | Operator+ |
| POST | `/guests/{guest}/resend` | `GuestUserController@resendInvitation` | `guests.resend` | Operator+ |
| POST | `/guests/bulk` | `GuestUserController@bulkAction` | `guests.bulk` | Operator+ |

### Guest Access Visibility (JSON, read-only)

Live-fetched from Microsoft Graph API. Responses are cached server-side for 5 minutes.

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/guests/{guest}/groups` | `GuestUserController@groups` | `guests.groups` | Any |
| GET | `/guests/{guest}/apps` | `GuestUserController@apps` | `guests.apps` | Any |
| GET | `/guests/{guest}/teams` | `GuestUserController@teams` | `guests.teams` | Any |
| GET | `/guests/{guest}/sites` | `GuestUserController@sites` | `guests.sites` | Any |

**Response format (groups):**
```json
[
    { "id": "g1", "displayName": "Security Group A", "groupType": "security", "description": "..." },
    { "id": "g2", "displayName": "M365 Group B", "groupType": "microsoft365", "description": null }
]
```

**Response format (apps):**
```json
[
    { "id": "a1", "appDisplayName": "SharePoint Online", "roleName": "Default Access", "assignedAt": "2026-01-15T10:00:00Z" }
]
```

**Response format (teams):**
```json
[
    { "id": "t1", "displayName": "Engineering", "description": "Eng team" }
]
```

**Response format (sites):**
```json
[
    { "id": "s1", "displayName": "Project Team Site", "webUrl": "https://contoso.sharepoint.com/sites/project-team" }
]
```

**Error response (502):**
```json
{ "error": "Unable to load groups from Microsoft Graph API." }
```

### Templates (Admin only)

| Method | URI | Controller@Method | Name |
|--------|-----|------------------|------|
| GET | `/templates` | `PartnerTemplateController@index` | `templates.index` |
| GET | `/templates/create` | `PartnerTemplateController@create` | `templates.create` |
| POST | `/templates` | `PartnerTemplateController@store` | `templates.store` |
| GET | `/templates/{template}/edit` | `PartnerTemplateController@edit` | `templates.edit` |
| PUT | `/templates/{template}` | `PartnerTemplateController@update` | `templates.update` |
| DELETE | `/templates/{template}` | `PartnerTemplateController@destroy` | `templates.destroy` |

### Admin — Collaboration Settings

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/admin/collaboration` | `AdminCollaborationController@edit` | `admin.collaboration.edit` | Admin |
| PUT | `/admin/collaboration` | `AdminCollaborationController@update` | `admin.collaboration.update` | Admin |

### Admin — Graph Settings

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/admin/graph` | `AdminGraphController@edit` | `admin.graph.edit` | Admin |
| PUT | `/admin/graph` | `AdminGraphController@update` | `admin.graph.update` | Admin |
| POST | `/admin/graph/test` | `AdminGraphController@testConnection` | `admin.graph.test` | Admin |
| GET | `/admin/graph/consent` | `AdminGraphController@consentUrl` | `admin.graph.consent` | Admin |
| GET | `/admin/graph/consent/callback` | `AdminGraphController@consentCallback` | `admin.graph.consent.callback` | None* |

\* The consent callback is unauthenticated because Microsoft redirects to it in the popup context.

### Access Reviews

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/access-reviews` | `AccessReviewController@index` | `access-reviews.index` | Any |
| GET | `/access-reviews/create` | `AccessReviewController@create` | `access-reviews.create` | Admin |
| POST | `/access-reviews` | `AccessReviewController@store` | `access-reviews.store` | Admin |
| GET | `/access-reviews/{access_review}` | `AccessReviewController@show` | `access-reviews.show` | Any |
| DELETE | `/access-reviews/{access_review}` | `AccessReviewController@destroy` | `access-reviews.destroy` | Admin |
| GET | `/access-reviews/{access_review}/instances/{instance}` | `AccessReviewController@showInstance` | `access-reviews.instances.show` | Any |
| POST | `/access-reviews/decisions/{decision}` | `AccessReviewController@submitDecision` | `access-reviews.decisions.submit` | Operator+ |
| POST | `/access-reviews/instances/{instance}/apply` | `AccessReviewController@applyRemediations` | `access-reviews.instances.apply` | Admin |

### Conditional Access (Read-only)

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/conditional-access` | `ConditionalAccessPolicyController@index` | `conditional-access.index` | Any |
| GET | `/conditional-access/{conditionalAccessPolicy}` | `ConditionalAccessPolicyController@show` | `conditional-access.show` | Any |

### Sensitivity Labels (Read-only)

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/sensitivity-labels` | `SensitivityLabelController@index` | `sensitivity-labels.index` | Any |
| GET | `/sensitivity-labels/{sensitivityLabel}` | `SensitivityLabelController@show` | `sensitivity-labels.show` | Any |

### SharePoint Sites (Read-only)

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/sharepoint-sites` | `SharePointSiteController@index` | `sharepoint-sites.index` | Any |
| GET | `/sharepoint-sites/{sharePointSite}` | `SharePointSiteController@show` | `sharepoint-sites.show` | Any |

### Compliance Reports

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/reports` | `ComplianceReportController@index` | `reports.index` | Any |
| GET | `/reports/export` | `ComplianceReportController@export` | `reports.export` | Any |

### Admin — SIEM / Syslog Settings

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/admin/syslog` | `AdminSyslogController@edit` | `admin.syslog.edit` | Admin |
| PUT | `/admin/syslog` | `AdminSyslogController@update` | `admin.syslog.update` | Admin |
| POST | `/admin/syslog/test` | `AdminSyslogController@test` | `admin.syslog.test` | Admin |

### Activity Log

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/activity` | `ActivityLogController@index` | `activity.index` | Any |

**Query Parameters (all optional):**

| Parameter | Type | Description |
|-----------|------|-------------|
| `actions[]` | string[] | Filter by action type(s) (e.g. `user_logged_in`, `partner_created`) |
| `user_id` | integer | Filter by user who performed the action |
| `date_from` | date (Y-m-d) | Show entries from this date |
| `date_to` | date (Y-m-d) | Show entries up to this date |
| `search` | string | Search within details JSON |

## Request Validation

### StorePartnerRequest

```json
{
    "tenant_id": "required|uuid",
    "category": "required|in:vendor,contractor,strategic_partner,customer,other",
    "notes": "nullable|string|max:1000",
    "template_id": "nullable|exists:partner_templates,id",
    "mfa_trust_enabled": "boolean",
    "b2b_inbound_enabled": "boolean",
    "b2b_outbound_enabled": "boolean",
    "device_trust_enabled": "boolean",
    "direct_connect_inbound_enabled": "boolean",
    "direct_connect_outbound_enabled": "boolean"
}
```

### UpdatePartnerRequest

```json
{
    "category": "sometimes|in:vendor,contractor,strategic_partner,customer,other",
    "notes": "nullable|string|max:5000",
    "owner_user_id": "nullable|exists:users,id",
    "mfa_trust_enabled": "boolean",
    "b2b_inbound_enabled": "boolean",
    "b2b_outbound_enabled": "boolean",
    "device_trust_enabled": "boolean",
    "direct_connect_inbound_enabled": "boolean",
    "direct_connect_outbound_enabled": "boolean",
    "tenant_restrictions_enabled": "boolean",
    "tenant_restrictions_json": "nullable|array",
    "tenant_restrictions_json.applications": "nullable|array",
    "tenant_restrictions_json.applications.accessType": "nullable|string|in:allowed,blocked",
    "tenant_restrictions_json.applications.targets": "nullable|array",
    "tenant_restrictions_json.usersAndGroups": "nullable|array",
    "tenant_restrictions_json.usersAndGroups.accessType": "nullable|string|in:allowed,blocked",
    "tenant_restrictions_json.usersAndGroups.targets": "nullable|array"
}
```

### InviteGuestRequest

```json
{
    "email": "required|email",
    "redirect_url": "nullable|url",
    "custom_message": "nullable|string|max:500",
    "send_email": "boolean"
}
```

### StoreTemplateRequest

```json
{
    "name": "required|string|max:255",
    "description": "nullable|string|max:1000",
    "policy_config": "required|array",
    "policy_config.mfa_trust_enabled": "boolean",
    "policy_config.b2b_inbound_enabled": "boolean",
    "policy_config.b2b_outbound_enabled": "boolean",
    "policy_config.device_trust_enabled": "boolean",
    "policy_config.direct_connect_inbound_enabled": "boolean",
    "policy_config.direct_connect_outbound_enabled": "boolean"
}
```

## Inertia Page Props

### Dashboard

```typescript
{
    stats: {
        total_partners: number;
        total_guests: number;
        pending_invitations: number;
        stale_guests: number;          // 90+ days inactive or never signed in
        overdue_reviews: number;       // pending/in-progress instances past due date
    };
    pendingApprovals: {                // top 5, ordered by requested_at asc
        id: number;
        access_package_id: number;
        access_package_name: string | null;
        target_user_email: string;
        requested_at: string | null;
    }[];
    attentionPartners: {               // top 5, trust_score < 70, ordered asc
        id: number;
        display_name: string;
        trust_score: number;
        stale_guests_count: number;
    }[];
    recentActivity: ActivityLog[];     // last 10 entries
}
```

### Partners Index

```typescript
{
    partners: Paginated<PartnerOrganization>;
    filters: { search?: string; category?: string; mfa_trust?: string };
}
```

### Conditional Access Index

```typescript
{
    policies: Paginated<ConditionalAccessPolicy>;
    uncoveredPartnerCount: number;
}
```

### Conditional Access Show

```typescript
{
    policy: ConditionalAccessPolicy & { partners: PartnerOrganization[] };
}
```

### Sensitivity Labels Index

```typescript
{
    labels: Paginated<SensitivityLabel>;
    uncoveredPartnerCount: number;
}
```

### Sensitivity Labels Show

```typescript
{
    label: SensitivityLabel & { partners: PartnerOrganization[]; children: SensitivityLabel[] };
}
```

### SharePoint Sites Index

```typescript
{
    sites: Paginated<SharePointSite>;
    uncoveredPartnerCount: number;
}
```

### SharePoint Sites Show

```typescript
{
    site: SharePointSite & { sensitivity_label: SensitivityLabel; permissions: SharePointSitePermission[] };
}
```

### Partners Show

The `PartnerOrganization` type includes trust score fields, conditional access policies, sensitivity labels, and SharePoint site exposure: `trust_score` (0-100 or null), `trust_score_breakdown` (JSON with per-signal pass/fail and points), and `trust_score_calculated_at`.

```typescript
{
    partner: PartnerOrganization & { owner: User; guest_users: GuestUser[] };
    sharePointSites: SharePointSite[];
    activity: ActivityLog[];
}
```

### Compliance Reports Index

```typescript
{
    summary: {
        compliance_score: number;       // 0-100, % of partners meeting baseline
        partners_with_issues: number;
        stale_guests_90: number;        // guests with 90+ days inactive or never signed in
        total_partners: number;
        total_guests: number;
        avg_trust_score: number | null;
    };
    partnerCompliance: {
        no_mfa_count: number;
        no_device_trust_count: number;
        overly_permissive_count: number;  // both B2B inbound + outbound enabled
        no_ca_policies_count: number;
        no_sensitivity_labels_count: number;
        partners: NonCompliantPartner[];
    };
    guestHealth: {
        stale_30_plus: number;
        stale_60_plus: number;
        stale_90_plus: number;
        never_signed_in: number;
        pending_invitations: number;
        disabled_accounts: number;
        guests: StaleGuest[];
    };
}
```

### Compliance Reports Export

`GET /reports/export` returns a streamed CSV file with two sections:
- **Partner Policy Compliance**: Partner Name, Domain, MFA Trust, Device Trust, B2B Inbound, B2B Outbound, Trust Score, CA Policy Count
- **Guest Account Health**: Guest Email, Display Name, Partner, Last Sign-In, Days Inactive, Invitation Status, Account Enabled

### Guests Index

```typescript
{
    guests: Paginated<GuestUser>;
    filters: { search?: string; partner_id?: number; status?: string; sort?: string; direction?: string };
    partners: { id: number; display_name: string }[];
}
```

### StoreAccessReviewRequest

```json
{
    "title": "required|string|max:255",
    "description": "nullable|string",
    "review_type": "required|in:guest_users,partner_organizations",
    "scope_partner_id": "nullable|exists:partner_organizations,id",
    "recurrence_type": "required|in:one_time,recurring",
    "recurrence_interval_days": "required_if:recurrence_type,recurring|integer|min:1|max:365",
    "remediation_action": "required|in:flag_only,disable,remove",
    "reviewer_user_id": "required|exists:users,id"
}
```

### UpdateSyslogSettingsRequest

```json
{
    "enabled": "required|boolean",
    "host": "required_if:enabled,true|nullable|string|max:255",
    "port": "integer|between:1,65535",
    "transport": "in:udp,tcp,tls",
    "facility": "integer|between:0,23"
}
```

### UpdateCollaborationSettingsRequest

```json
{
    "allow_invites_from": "required|in:none,adminsAndGuestInviters,adminsGuestInvitersAndAllMembers,everyone",
    "domain_restriction_mode": "required|in:none,allowList,blockList",
    "allowed_domains": "required_if:domain_restriction_mode,allowList|array",
    "allowed_domains.*": "string",
    "blocked_domains": "required_if:domain_restriction_mode,blockList|array",
    "blocked_domains.*": "string"
}
```

## Resolve Tenant Endpoint

The `/partners/resolve-tenant` endpoint is used by the partner creation wizard to look up tenant information before creating a partner.

**Request:**
```json
POST /partners/resolve-tenant
{ "tenant_id": "550e8400-e29b-41d4-a716-446655440000" }
```

**Success Response (200):**
```json
{
    "tenantId": "550e8400-e29b-41d4-a716-446655440000",
    "displayName": "Contoso Ltd",
    "defaultDomainName": "contoso.com"
}
```

**Error Response (422):**
```json
{ "error": "Invalid tenant ID format" }
```
