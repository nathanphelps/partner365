# Partner Favicon/Logo Display

## Overview

Fetch and cache favicons for partner organizations based on their domain. Display on the partner index page (small icon next to name) and show page (larger logo in header), with initials fallback when no favicon is available.

## Favicon Fetching

- New Artisan command: `sync:favicons` runs daily via scheduler
- Iterates partners with a `domain` value that don't yet have a cached favicon
- `--force` flag to re-fetch all
- For each partner:
  1. Fetch `https://{domain}` homepage HTML
  2. Parse `<link rel="icon">` / `<link rel="shortcut icon">` tags for the best icon URL
  3. If no link tag found, fall back to `https://{domain}/favicon.ico`
  4. Download the icon file, store to `storage/app/public/favicons/{partner_id}.{ext}`
  5. Save the relative path to a new `favicon_path` column on `partner_organizations`
  6. If fetch fails, leave `favicon_path` null (no error, just skip)

## Database

- New migration: add nullable `favicon_path` string column to `partner_organizations`

## Backend

- New service method or dedicated class for favicon fetching logic (HTML parsing + download)
- Controller passes `favicon_path` as part of existing partner data (already serialized)
- Public URL served via Laravel's storage link (`/storage/favicons/...`)

## Frontend - Index Page

- Small favicon (20-24px) next to the partner name in the table
- Avatar component: show favicon image if `favicon_path` exists, initials fallback otherwise

## Frontend - Show Page

- Larger favicon/logo (40-48px) in the header next to the display name
- Same Avatar pattern with initials fallback

## Out of Scope

- No manual upload UI
- No image format conversion or resizing (store as-is, CSS handles sizing)
- No re-fetch on partner create/update (daily sync handles it)
