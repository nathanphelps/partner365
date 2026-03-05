# In-App Documentation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add end-user and admin documentation rendered from markdown files at `/docs` inside the authenticated app.

**Architecture:** Laravel controller reads markdown files from `docs/users/`, parses YAML frontmatter with `symfony/yaml`, and passes raw markdown + sidebar tree as Inertia props. Vue page renders markdown client-side with `markdown-it` and Tailwind typography prose classes.

**Tech Stack:** Laravel 12, Vue 3, Inertia.js, markdown-it, @tailwindcss/typography, symfony/yaml (existing)

---

### Task 1: Install npm dependencies

**Files:**
- Modify: `package.json`

**Step 1: Install markdown-it and typography plugin**

Run:
```bash
npm install markdown-it @tailwindcss/typography && npm install -D @types/markdown-it
```

**Step 2: Verify installation**

Run: `cat package.json | grep -E "markdown-it|typography"`
Expected: Both packages listed in dependencies/devDependencies.

**Step 3: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore: add markdown-it and @tailwindcss/typography dependencies"
```

---

### Task 2: Enable Tailwind typography plugin

**Files:**
- Modify: `resources/css/app.css`

**Step 1: Add the typography import**

Add this line after `@import 'tw-animate-css';` in `resources/css/app.css`:

```css
@plugin '@tailwindcss/typography';
```

Note: Tailwind v4 uses `@plugin` instead of the v3 `plugins` array in tailwind.config.js.

**Step 2: Verify it works**

Run: `npm run build`
Expected: Build succeeds without errors.

**Step 3: Commit**

```bash
git add resources/css/app.css
git commit -m "chore: enable tailwindcss typography plugin"
```

---

### Task 3: Create documentation markdown files

**Files:**
- Create: All files under `docs/users/` per the structure below

Each file uses YAML frontmatter with `title`, `section` (optional override), and `admin` (boolean, default false).

**Step 1: Create getting-started docs**

Create `docs/users/getting-started/01-overview.md`:
```markdown
---
title: Overview
---

# Welcome to Partner365

Partner365 helps you manage Microsoft 365 external partner organizations, cross-tenant access policies, and B2B guest user lifecycle. This guide covers everything you need to know to use the application effectively.

## Key Concepts

- **Partner Organizations** — External Microsoft 365 tenants that your organization collaborates with. Each partner has cross-tenant access policies that control what resources they can access.
- **Guest Users** — External users invited into your tenant via B2B collaboration. Guests are associated with partner organizations and can be granted access to specific resources.
- **Cross-Tenant Access Policies** — Settings that control inbound and outbound access between your tenant and partner organizations, including B2B collaboration and B2B direct connect.
- **Templates** — Reusable policy configurations that admins can apply when adding new partner organizations.
- **Access Reviews** — Periodic reviews of guest user access to ensure compliance and remove stale permissions.
- **Trust Score** — A computed score reflecting the security posture of each partner based on policy configuration and guest activity.

## Roles

Partner365 has three user roles:

| Role | Permissions |
|------|-------------|
| **Viewer** | Read-only access to all data — partners, guests, reviews, reports |
| **Operator** | Everything viewers can do, plus create/edit partners, invite guests, manage access reviews and entitlements |
| **Admin** | Everything operators can do, plus manage users, templates, sync settings, and Graph API configuration |

## Navigation

Use the sidebar on the left to navigate between sections. The sidebar collapses on smaller screens — click the menu icon to expand it.

Your main sections are:
- **Dashboard** — Overview of key metrics and action items
- **Partners** — View and manage partner organizations
- **Guests** — View and manage guest users
- **Access Reviews** — Create and manage periodic access reviews
- **Conditional Access** — View conditional access policies affecting external users
- **Entitlements** — Manage access packages and assignments
- **Reports** — Generate compliance reports
- **Activity** — View the audit log of all actions
```

Create `docs/users/getting-started/02-dashboard.md`:
```markdown
---
title: Dashboard
---

# Dashboard

The dashboard is your central hub showing key metrics and items that need attention.

## Stats Overview

At the top of the dashboard you'll see summary cards showing:
- **Total Partners** — Number of partner organizations configured
- **Total Guests** — Number of guest users in your tenant
- **Pending Invitations** — Guest invitations not yet accepted
- **Stale Guests** — Guests who haven't signed in recently
- **Overdue Reviews** — Access reviews past their due date

## Action Items

### Pending Approvals
If you're an operator or admin, you'll see entitlement access requests waiting for your approval. Click on any request to review and approve or deny it.

### Partners Needing Attention
Partners with low trust scores or a high number of stale guests appear here. Click on a partner to review their details and take action.

### Recent Activity
A feed of the most recent actions taken in the system — partner additions, guest invitations, policy changes, and more.
```

**Step 2: Create partner docs**

Create `docs/users/partners/01-viewing-partners.md`:
```markdown
---
title: Viewing Partners
---

# Viewing Partners

The Partners page shows all external organizations configured in Partner365.

## Partner List

Each partner entry displays:
- **Display Name** — The organization's name
- **Tenant ID** — Their Microsoft 365 tenant identifier
- **Category** — Classification (e.g., Vendor, Customer, Subsidiary)
- **Trust Score** — A 0-100 score reflecting security posture
- **Guest Count** — Number of guest users from this partner
- **Policy Status** — Whether cross-tenant access policies are configured

## Filtering and Search

Use the search bar to filter partners by name or tenant ID. You can also filter by category using the dropdown.

## Viewing Partner Details

Click on any partner to see their full details including:
- Cross-tenant access policy configuration (inbound/outbound B2B collaboration and direct connect settings)
- Associated guest users
- Trust score breakdown
- Activity history
```

Create `docs/users/partners/02-adding-partners.md`:
```markdown
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
```

Create `docs/users/partners/03-partner-details.md`:
```markdown
---
title: Partner Details
---

# Partner Details

The partner detail page shows comprehensive information about a specific partner organization.

## Cross-Tenant Access Policies

The policy section shows the current configuration for:
- **Inbound B2B Collaboration** — Controls which users from the partner can be invited as guests
- **Outbound B2B Collaboration** — Controls which of your users can be guests in the partner's tenant
- **Inbound B2B Direct Connect** — Controls partner user access to shared channels
- **Outbound B2B Direct Connect** — Controls your user access to the partner's shared channels
- **Trust Settings** — Whether you trust the partner's MFA and device compliance claims

Each policy section shows whether it's configured to allow all, block all, or target specific users/groups/applications.

## Guest Users

A list of all guest users associated with this partner. You can click through to individual guest details or invite new guests directly from this page.

## Trust Score

The trust score (0-100) is calculated based on:
- Policy restrictiveness (more restrictive = higher score)
- Guest activity (inactive guests lower the score)
- MFA trust configuration
- Device compliance trust settings

## Actions

Depending on your role, you can:
- **Edit** the partner's category and notes (Operator+)
- **Update** cross-tenant access policies (Operator+)
- **Delete** the partner organization (Operator+)
```

**Step 3: Create guest docs**

Create `docs/users/guests/01-guest-list.md`:
```markdown
---
title: Guest List
---

# Guest List

The Guests page shows all B2B guest users in your Microsoft 365 tenant.

## Guest Information

Each guest entry displays:
- **Display Name** — The guest's name
- **Email** — Their external email address
- **Partner** — The associated partner organization
- **Invitation Status** — Pending, Accepted, or Failed
- **Last Sign-In** — When the guest last authenticated
- **Created Date** — When the invitation was sent

## Filtering

You can filter guests by:
- **Search** — Name or email
- **Partner** — Filter by partner organization
- **Status** — Filter by invitation status
- **Stale** — Show only guests who haven't signed in recently

## Bulk Actions

Operators and admins can select multiple guests and perform bulk actions:
- **Resend Invitations** — Re-send invitation emails for pending guests
- **Remove** — Remove selected guest users from the tenant
```

Create `docs/users/guests/02-inviting-guests.md`:
```markdown
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
```

Create `docs/users/guests/03-guest-details.md`:
```markdown
---
title: Guest Details
---

# Guest Details

The guest detail page shows comprehensive information about a specific guest user.

## Overview

- Basic profile information (name, email, UPN)
- Invitation status and dates
- Associated partner organization
- Last sign-in activity

## Access Information

The detail page shows what the guest has access to:
- **Groups** — Microsoft 365 and security group memberships
- **Applications** — Enterprise applications the guest can access
- **Teams** — Microsoft Teams the guest is a member of
- **SharePoint Sites** — SharePoint sites the guest can access

Each tab loads data from Microsoft Graph API and may take a moment to populate.

## Actions

- **Resend Invitation** — Re-send the invitation email (for pending guests)
- **Remove Guest** — Remove the guest from your tenant (Operator+)
```

**Step 4: Create access review docs**

Create `docs/users/access-reviews/01-overview.md`:
```markdown
---
title: Overview
---

# Access Reviews

Access reviews help you periodically verify that guest users still need access to your tenant. This supports compliance requirements and reduces security risk from stale accounts.

## How It Works

1. An operator or admin **creates a review** targeting specific partners or all guests
2. The review generates **instances** with individual decisions for each guest
3. Reviewers evaluate each guest and **approve or deny** continued access
4. Denied guests can be **automatically remediated** (removed from the tenant)

## Review Status

- **Active** — Review is in progress, awaiting decisions
- **Completed** — All decisions have been submitted
- **Expired** — Review passed its due date without completion
```

Create `docs/users/access-reviews/02-creating-reviews.md`:
```markdown
---
title: Creating Reviews
---

# Creating Access Reviews

Operators and admins can create new access reviews.

## Steps

1. Navigate to Access Reviews and click **Create Review**
2. Configure the review:
   - **Name** — A descriptive name for the review
   - **Scope** — Which partners/guests to include
   - **Due Date** — When the review must be completed
3. Click **Create**

## After Creation

The system generates review instances — one decision per guest user in scope. Reviewers can then work through each decision on the review detail page.
```

Create `docs/users/access-reviews/03-reviewing-decisions.md`:
```markdown
---
title: Reviewing Decisions
---

# Reviewing Decisions

Once a review is created, work through the decisions on the review detail page.

## Making Decisions

For each guest in the review:
1. Review the guest's information (name, email, partner, last sign-in)
2. Click **Approve** to confirm continued access or **Deny** to flag for removal
3. Optionally add a justification note

## Applying Remediations

After all decisions are made, admins can apply remediations:
- **Apply** — Remove denied guests from the tenant via Graph API
- This action is irreversible — denied guests will need to be re-invited

## Review History

Completed reviews are kept in the system for audit purposes. You can view past reviews, their decisions, and whether remediations were applied.
```

**Step 5: Create entitlements docs**

Create `docs/users/entitlements/01-access-packages.md`:
```markdown
---
title: Access Packages
---

# Entitlements — Access Packages

Entitlements let you create self-service access packages that external users can request.

## What Are Access Packages?

An access package bundles together resources (groups, SharePoint sites) that a guest user needs. Instead of manually assigning access, you create a package and users request it.

## Viewing Packages

The Entitlements page lists all access packages with:
- **Name** — Package name
- **Description** — What access is included
- **Resources** — Groups and sites in the package
- **Active Assignments** — How many users currently have this package

## Creating Packages

Operators and admins can create new packages:
1. Click **Create Package**
2. Enter a name and description
3. Select groups and/or SharePoint sites to include
4. Click **Create**
```

Create `docs/users/entitlements/02-assignments.md`:
```markdown
---
title: Assignments
---

# Entitlement Assignments

Assignments track who has been granted an access package and their approval status.

## Assignment Lifecycle

1. A user or admin **requests** the access package
2. An operator or admin **approves** or **denies** the request
3. If approved, the user is added to the package's groups and sites
4. Access can be **revoked** at any time

## Managing Assignments

On the access package detail page:
- **Pending** assignments need approval — click Approve or Deny
- **Active** assignments can be revoked if access is no longer needed
- **Denied/Revoked** assignments are kept for audit purposes

## Approval Workflow

When a new assignment request comes in, it appears on your Dashboard under "Pending Approvals" and on the entitlement detail page. Review the request and the user's details before approving.
```

**Step 6: Create conditional access, reports, and activity docs**

Create `docs/users/conditional-access/01-viewing-policies.md`:
```markdown
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
```

Create `docs/users/reports/01-compliance-reports.md`:
```markdown
---
title: Compliance Reports
---

# Compliance Reports

The Reports page lets you generate compliance reports about your external collaboration posture.

## Available Reports

The compliance report covers:
- Partner organization summary with trust scores
- Guest user statistics (active, stale, pending)
- Cross-tenant access policy coverage
- Access review completion rates
- Entitlement assignment summary

## Exporting

Click **Export** to download the report as a CSV file. This is useful for sharing with auditors or importing into other compliance tools.

## Filters

You can filter the report data by:
- Date range
- Specific partners
- Policy status
```

Create `docs/users/activity/01-activity-log.md`:
```markdown
---
title: Activity Log
---

# Activity Log

The Activity page shows a chronological audit trail of all actions performed in Partner365.

## Logged Actions

Every significant action is recorded:
- Partner created, updated, or deleted
- Guest invited, updated, or removed
- Cross-tenant policies modified
- Access review decisions made
- Entitlement assignments approved, denied, or revoked
- Sync operations completed
- User management changes

## Filtering

Filter the activity log by:
- **Action Type** — Select specific action categories
- **User** — Filter by who performed the action
- **Date Range** — Narrow to a specific time period
- **Search** — Full-text search in action details

## Details

Each log entry shows:
- **Action** — What happened
- **Description** — Human-readable summary
- **User** — Who performed the action
- **Timestamp** — When it occurred
- **Changes** — Before/after values for modifications (where applicable)
```

**Step 7: Create admin docs**

Create `docs/users/admin/01-user-management.md`:
```markdown
---
title: User Management
admin: true
---

# User Management

Admins can manage application users from the Admin > Users page.

## User List

View all registered users with their:
- Name and email
- Role (Admin, Operator, Viewer)
- Account status (Approved/Pending)
- Last login date

## Managing Users

### Changing Roles
Click the role dropdown next to any user to change their role. Changes take effect immediately.

### Approving Users
New users who register must be approved by an admin before they can access the application. Pending users appear with an "Approve" button.

### Removing Users
Click the delete button to remove a user. This does not affect their Microsoft Entra ID account — only their Partner365 access.
```

Create `docs/users/admin/02-sync-configuration.md`:
```markdown
---
title: Sync Configuration
admin: true
---

# Sync Configuration

The Admin > Sync page shows the status of background data synchronization.

## How Sync Works

Partner365 runs background sync jobs every 15 minutes to reconcile local data with Microsoft Entra ID:
- **Partner Sync** — Updates partner organization details and cross-tenant access policies
- **Guest Sync** — Updates guest user profiles, sign-in activity, and invitation status

## Sync Status

The page shows:
- Last sync time for each sync type
- Success/failure status
- Number of records updated

## Manual Sync

Click **Sync Now** to trigger an immediate sync. This is useful after making changes in the Microsoft Entra admin center that you want reflected immediately in Partner365.
```

Create `docs/users/admin/03-graph-settings.md`:
```markdown
---
title: Graph API Settings
admin: true
---

# Graph API Settings

The Admin > Graph page shows the Microsoft Graph API connection status.

## Connection Status

View the current configuration:
- **Tenant ID** — Your Microsoft 365 tenant identifier
- **Client ID** — The app registration client ID
- **Connection Status** — Whether Partner365 can successfully authenticate and call Graph API
- **Token Status** — Current access token validity

## Permissions

The page displays the Graph API permissions granted to the application. Partner365 requires specific permissions to manage cross-tenant access policies, guest users, and read directory data.

## Troubleshooting

If the connection status shows an error:
1. Verify your `.env` file has the correct `MICROSOFT_GRAPH_TENANT_ID`, `MICROSOFT_GRAPH_CLIENT_ID`, and `MICROSOFT_GRAPH_CLIENT_SECRET`
2. Check that the app registration in Azure has the required API permissions
3. Ensure admin consent has been granted for all permissions
4. Review the Activity Log for detailed error messages
```

Create `docs/users/admin/04-collaboration-settings.md`:
```markdown
---
title: Collaboration Settings
admin: true
---

# Collaboration Settings

The Admin > Collaboration page shows your tenant's external collaboration settings from Microsoft Entra ID.

## Settings Overview

This page displays the tenant-wide settings that govern external collaboration:
- **Guest invite restrictions** — Who can invite guest users
- **Collaboration restrictions** — Allowed/blocked domains for B2B collaboration
- **External user leave settings** — Whether external users can remove themselves
- **Guest user access restrictions** — Default permissions for guest users

## Important Notes

- These settings are **read-only** in Partner365 — they reflect your Entra ID configuration
- Changes must be made in the Microsoft Entra admin center
- These settings apply globally and affect all partner organizations
```

Create `docs/users/admin/05-templates.md`:
```markdown
---
title: Templates
admin: true
---

# Partner Templates

Templates let admins create reusable cross-tenant access policy configurations.

## Why Templates?

Instead of manually configuring policies for each partner, create templates for common scenarios:
- **Restrictive Vendor** — Minimal inbound access, no outbound
- **Trusted Subsidiary** — Full inbound/outbound with MFA trust
- **Standard Customer** — Moderate access with specific app targeting

## Managing Templates

### Creating Templates
1. Navigate to the Templates page
2. Click **Create Template**
3. Configure the cross-tenant access policy settings:
   - Inbound B2B collaboration (users, groups, applications)
   - Outbound B2B collaboration
   - Inbound/Outbound B2B direct connect
   - Trust settings (MFA, device compliance)
4. Give the template a name and description
5. Click **Save**

### Editing Templates
Click on any template to modify its settings. Changes only affect future partner creations — existing partners keep their current policies.

### Deleting Templates
Remove templates that are no longer needed. This does not affect partners that were created using the template.

## Using Templates

When adding a new partner organization, you can select a template in the creation form. The template's policy settings are applied to the new partner's cross-tenant access policy.
```

**Step 8: Commit all docs**

```bash
git add docs/users/
git commit -m "docs: add end-user and admin documentation markdown files"
```

---

### Task 4: Create DocsController

**Files:**
- Create: `app/Http/Controllers/DocsController.php`
- Modify: `routes/web.php`

**Step 1: Write the test**

Create `tests/Feature/DocsControllerTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\User;

test('unauthenticated users cannot access docs', function () {
    $this->get('/docs')->assertRedirect(route('login'));
});

test('authenticated users can access docs index', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('docs/Show')
            ->has('sidebar')
            ->has('content')
            ->has('currentPage')
        );
});

test('authenticated users can access a specific doc page', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs/getting-started/overview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('docs/Show')
            ->has('content')
            ->where('currentPage', 'getting-started/overview')
        );
});

test('invalid doc page returns 404', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs/nonexistent/page')
        ->assertNotFound();
});

test('sidebar contains expected sections', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('docs/Show')
            ->has('sidebar', fn ($sidebar) => $sidebar
                ->each(fn ($section) => $section
                    ->has('name')
                    ->has('admin')
                    ->has('pages')
                )
            )
        );
});

test('path traversal is blocked', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/docs/../../etc/passwd')
        ->assertNotFound();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DocsControllerTest`
Expected: FAIL — controller and route don't exist yet.

**Step 3: Create the controller**

Create `app/Http/Controllers/DocsController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

class DocsController extends Controller
{
    private string $docsPath;

    public function __construct()
    {
        $this->docsPath = base_path('docs/users');
    }

    public function index(): Response
    {
        $sidebar = $this->buildSidebar();

        if (empty($sidebar) || empty($sidebar[0]['pages'])) {
            abort(404);
        }

        $firstPage = $sidebar[0]['pages'][0]['slug'];

        return $this->renderPage($firstPage, $sidebar);
    }

    public function show(string $path): Response
    {
        // Block path traversal
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404);
        }

        $sidebar = $this->buildSidebar();

        return $this->renderPage($path, $sidebar);
    }

    private function renderPage(string $slug, array $sidebar): Response
    {
        $filePath = $this->resolveFilePath($slug);

        if (! $filePath || ! File::exists($filePath)) {
            abort(404);
        }

        $raw = File::get($filePath);
        $parsed = $this->parseFrontmatter($raw);

        // Mark active page in sidebar
        $sidebar = array_map(function ($section) use ($slug) {
            $section['pages'] = array_map(function ($page) use ($slug) {
                $page['active'] = $page['slug'] === $slug;

                return $page;
            }, $section['pages']);

            return $section;
        }, $sidebar);

        return Inertia::render('docs/Show', [
            'sidebar' => $sidebar,
            'content' => $parsed['content'],
            'currentPage' => $slug,
            'pageTitle' => $parsed['title'],
        ]);
    }

    private function buildSidebar(): array
    {
        $sections = [];

        if (! File::isDirectory($this->docsPath)) {
            return [];
        }

        $directories = collect(File::directories($this->docsPath))->sort()->values();

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            $files = collect(File::files($dir))
                ->filter(fn ($file) => $file->getExtension() === 'md')
                ->sortBy(fn ($file) => $file->getFilename())
                ->values();

            $pages = [];
            $sectionAdmin = false;

            foreach ($files as $file) {
                $parsed = $this->parseFrontmatter(File::get($file->getPathname()));
                $slug = $dirName . '/' . preg_replace('/^\d+-/', '', $file->getFilenameWithoutExtension());

                $isAdmin = $parsed['admin'] ?? false;
                if ($isAdmin) {
                    $sectionAdmin = true;
                }

                $pages[] = [
                    'title' => $parsed['title'] ?? $this->titleFromFilename($file->getFilenameWithoutExtension()),
                    'slug' => $slug,
                    'active' => false,
                ];
            }

            if (! empty($pages)) {
                $sections[] = [
                    'name' => $parsed['section'] ?? $this->titleFromDirectory($dirName),
                    'admin' => $sectionAdmin,
                    'pages' => $pages,
                ];
            }
        }

        return $sections;
    }

    private function resolveFilePath(string $slug): ?string
    {
        $parts = explode('/', $slug);
        if (count($parts) !== 2) {
            return null;
        }

        [$dir, $page] = $parts;
        $dirPath = $this->docsPath . '/' . $dir;

        if (! File::isDirectory($dirPath)) {
            return null;
        }

        // Find file matching the slug (with any numeric prefix)
        $files = File::files($dirPath);
        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $nameWithoutPrefix = preg_replace('/^\d+-/', '', $file->getFilenameWithoutExtension());
            if ($nameWithoutPrefix === $page) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function parseFrontmatter(string $content): array
    {
        if (! str_starts_with($content, '---')) {
            return ['content' => $content, 'title' => null, 'admin' => false];
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);

        if (count($parts) < 3) {
            return ['content' => $content, 'title' => null, 'admin' => false];
        }

        $meta = Yaml::parse($parts[1]) ?? [];

        return [
            'content' => trim($parts[2]),
            'title' => $meta['title'] ?? null,
            'section' => $meta['section'] ?? null,
            'admin' => $meta['admin'] ?? false,
        ];
    }

    private function titleFromFilename(string $filename): string
    {
        $name = preg_replace('/^\d+-/', '', $filename);

        return str_replace('-', ' ', ucfirst($name));
    }

    private function titleFromDirectory(string $dirname): string
    {
        return ucwords(str_replace('-', ' ', $dirname));
    }
}
```

**Step 4: Add routes**

Add these lines to `routes/web.php` inside the `auth+verified+approved` middleware group, before the closing `});`:

```php
    Route::get('docs', [App\Http\Controllers\DocsController::class, 'index'])->name('docs.index');
    Route::get('docs/{path}', [App\Http\Controllers\DocsController::class, 'show'])->name('docs.show')->where('path', '.*');
```

Add the use statement at the top:
```php
use App\Http\Controllers\DocsController;
```

**Step 5: Run tests**

Run: `php artisan test --filter=DocsControllerTest`
Expected: All 6 tests pass.

**Step 6: Commit**

```bash
git add app/Http/Controllers/DocsController.php tests/Feature/DocsControllerTest.php routes/web.php
git commit -m "feat: add DocsController with routes and tests"
```

---

### Task 5: Create the Vue docs page

**Files:**
- Create: `resources/js/components/docs/DocSidebar.vue`
- Create: `resources/js/pages/docs/Show.vue`

**Step 1: Create DocSidebar component**

Create `resources/js/components/docs/DocSidebar.vue`:

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type DocPage = {
    title: string;
    slug: string;
    active: boolean;
};

type DocSection = {
    name: string;
    admin: boolean;
    pages: DocPage[];
};

const props = defineProps<{
    sections: DocSection[];
}>();

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const visibleSections = computed(() =>
    props.sections.filter((s) => !s.admin || isAdmin.value),
);
</script>

<template>
    <nav class="w-64 shrink-0 border-r border-border">
        <div class="sticky top-0 h-full overflow-y-auto p-4">
            <div v-for="section in visibleSections" :key="section.name" class="mb-6">
                <h3
                    class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                >
                    {{ section.name }}
                </h3>
                <ul class="space-y-1">
                    <li v-for="docPage in section.pages" :key="docPage.slug">
                        <Link
                            :href="`/docs/${docPage.slug}`"
                            class="block rounded-md px-3 py-1.5 text-sm transition-colors"
                            :class="
                                docPage.active
                                    ? 'bg-accent font-medium text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                            "
                        >
                            {{ docPage.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</template>
```

**Step 2: Create Show.vue page**

Create `resources/js/pages/docs/Show.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MarkdownIt from 'markdown-it';
import { computed } from 'vue';
import DocSidebar from '@/components/docs/DocSidebar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type DocPage = {
    title: string;
    slug: string;
    active: boolean;
};

type DocSection = {
    name: string;
    admin: boolean;
    pages: DocPage[];
};

const props = defineProps<{
    sidebar: DocSection[];
    content: string;
    currentPage: string;
    pageTitle: string;
}>();

const md = new MarkdownIt({
    html: false,
    linkify: true,
    typographer: true,
});

const renderedContent = computed(() => md.render(props.content));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Documentation', href: '/docs' },
];
</script>

<template>
    <Head :title="`Docs — ${pageTitle}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-8rem)]">
            <DocSidebar :sections="sidebar" />
            <main class="flex-1 overflow-y-auto p-8">
                <article
                    class="prose prose-sm max-w-none dark:prose-invert prose-headings:text-foreground prose-p:text-foreground/90 prose-a:text-primary prose-strong:text-foreground prose-code:text-foreground prose-th:text-foreground"
                    v-html="renderedContent"
                />
            </main>
        </div>
    </AppLayout>
</template>
```

**Step 3: Verify it compiles**

Run: `npm run build`
Expected: Build succeeds without errors.

**Step 4: Commit**

```bash
git add resources/js/components/docs/DocSidebar.vue resources/js/pages/docs/Show.vue
git commit -m "feat: add docs page with sidebar and markdown rendering"
```

---

### Task 6: Add Documentation link to app sidebar

**Files:**
- Modify: `resources/js/components/AppSidebar.vue:1-64`

**Step 1: Add the docs nav item**

In `resources/js/components/AppSidebar.vue`:

1. Add import for the `BookOpen` icon from `lucide-vue-next` (add it to the existing import block at line 3-14)
2. Add this item to the `mainNavItems` array at line 56, before the Activity item:

```typescript
{ title: 'Documentation', href: '/docs', icon: BookOpen },
```

The full items array should end with:
```typescript
        { title: 'Reports', href: '/reports', icon: BarChart3 },
        { title: 'Activity', href: '/activity', icon: Activity },
        { title: 'Documentation', href: '/docs', icon: BookOpen },
    ];
```

**Step 2: Verify build**

Run: `npm run build`
Expected: Build succeeds.

**Step 3: Commit**

```bash
git add resources/js/components/AppSidebar.vue
git commit -m "feat: add Documentation link to app sidebar"
```

---

### Task 7: Run full CI checks

**Step 1: Run lint and format**

Run: `composer run lint && npm run lint && npm run format`

**Step 2: Run type check**

Run: `npm run types:check`
Expected: No type errors.

**Step 3: Run full test suite**

Run: `composer run ci:check`
Expected: All checks pass.

**Step 4: Fix any issues found and commit**

```bash
git add -A
git commit -m "fix: address lint and format issues for docs feature"
```

---

### Task 8: Final commit and summary

**Step 1: Verify everything is committed**

Run: `git status`
Expected: Clean working tree.

**Step 2: Review the full diff**

Run: `git log --oneline -10`
Expected: See all docs-related commits.
