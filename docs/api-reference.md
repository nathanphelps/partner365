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

### Activity Log

| Method | URI | Controller@Method | Name | Role |
|--------|-----|------------------|------|------|
| GET | `/activity` | `ActivityLogController@index` | `activity.index` | Any |

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
        mfa_trust_enabled: number;
        mfa_trust_disabled: number;
        total_guests: number;
        pending_invitations: number;
        inactive_guests: number;
        partners_by_category: Record<string, number>;
    };
    recentActivity: ActivityLog[];
}
```

### Partners Index

```typescript
{
    partners: Paginated<PartnerOrganization>;
    filters: { search?: string; category?: string; mfa_trust?: string };
}
```

### Partners Show

```typescript
{
    partner: PartnerOrganization & { owner: User; guest_users: GuestUser[] };
    activity: ActivityLog[];
}
```

### Guests Index

```typescript
{
    guests: Paginated<GuestUser>;
    filters: { search?: string; partner_id?: number; status?: string };
    partners: { id: number; display_name: string }[];
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
