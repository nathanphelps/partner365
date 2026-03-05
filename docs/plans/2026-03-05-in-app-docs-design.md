# In-App Documentation Design

## Overview

Add end-user and admin documentation as markdown files in `docs/users/`, rendered in-app at `/docs` behind authentication. The docs page uses a sidebar navigation and client-side markdown rendering.

## Decisions

- **Approach:** File-system driven. Directories define sections, files define pages, YAML frontmatter defines metadata.
- **Rendering:** Client-side with `markdown-it` npm package. Raw markdown passed as Inertia props.
- **Styling:** `@tailwindcss/typography` prose classes for rendered HTML.
- **Access:** Authenticated users only, inside AppLayout. Admin sections hidden client-side by role.
- **Navigation:** Sidebar link in main app navigation, visible to all roles.

## File Structure

```
docs/users/
  getting-started/
    01-overview.md
    02-navigation.md
  partners/
    01-viewing-partners.md
    02-adding-partners.md
    03-partner-details.md
  guests/
    01-guest-list.md
    02-inviting-guests.md
    03-guest-details.md
  access-reviews/
    01-overview.md
    02-creating-reviews.md
    03-reviewing-decisions.md
  entitlements/
    01-access-packages.md
    02-assignments.md
  conditional-access/
    01-viewing-policies.md
  reports/
    01-compliance-reports.md
  activity/
    01-activity-log.md
  admin/
    01-user-management.md
    02-sync-configuration.md
    03-graph-settings.md
    04-collaboration-settings.md
    05-templates.md
```

### Frontmatter Format

```yaml
---
title: Viewing Partners
section: Partners
order: 1
admin: false
---
```

- `title` — display name in sidebar
- `section` — overrides directory-derived section name (optional)
- `order` — overrides numeric prefix ordering (optional)
- `admin` — marks page as admin-only (default: false)

## Backend

### Routes

Inside the auth+verified middleware group:

```
GET /docs        → DocsController@index
GET /docs/{path} → DocsController@show  (where path is wildcard e.g. "partners/viewing-partners")
```

### DocsController

Two methods:

- `index()` — scans `docs/users/` recursively, builds sidebar tree, loads first page as default content
- `show($path)` — loads specific page markdown + sidebar tree

### Sidebar Tree Prop

```php
[
    ['name' => 'Getting Started', 'admin' => false, 'pages' => [
        ['title' => 'Overview', 'slug' => 'getting-started/overview', 'active' => true],
        ['title' => 'Navigation', 'slug' => 'getting-started/navigation', 'active' => false],
    ]],
    ['name' => 'Administration', 'admin' => true, 'pages' => [...]],
]
```

Frontmatter parsed with `symfony/yaml` (already a Laravel dependency).

## Frontend

### Components

```
pages/docs/Show.vue             — main page (AppLayout + doc sidebar + content)
components/docs/DocSidebar.vue  — sidebar navigation
```

### Show.vue

- Uses `AppLayout`
- Two-panel: left doc sidebar, right rendered markdown content
- Sidebar sections grouped by directory, admin sections conditionally shown
- Markdown rendered with `markdown-it` (HTML disabled, linkify enabled)
- Content wrapped in Tailwind prose classes
- Navigation via `router.visit()` for SPA transitions

### markdown-it Config

- HTML: disabled (security)
- Linkify: enabled
- Typography: enabled
- No syntax highlighting plugin initially

## Dependencies

### New npm packages

- `markdown-it` — markdown to HTML rendering
- `@tailwindcss/typography` — prose styling

### No new PHP packages

`symfony/yaml` already bundled with Laravel.

## App Sidebar

Add "Documentation" link to main sidebar, visible to all roles, placed near bottom before Settings.
