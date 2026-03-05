# Page Consistency, Error Pages & Polish Design

## Context

The app has indigo branding on the landing page, auth layout, and CSS tokens, but many components still use hardcoded neutral/zinc/black colors. There are no error pages at all — 403/404/500 show raw Laravel output. The favicon is still the Laravel red flame. Several "Laravel" references and doc links remain.

## Error Pages

Create branded Blade templates at `resources/views/errors/` for: 403, 404, 419, 500, 503.

Each page: full-screen centered card on indigo gradient background (matches auth layout), Partner365 shield icon + wordmark, error code + message, "Go Home" button. Standalone HTML — no JS/Inertia dependency.

## Hardcoded Color Cleanup

Replace raw `zinc-*`, `neutral-*`, `text-black`, `bg-black`, `bg-white` with theme tokens (`text-foreground`, `bg-background`, `bg-card`, `text-muted-foreground`, `border-border`).

| File | Changes |
|------|---------|
| `resources/js/pages/Welcome.vue` | zinc-* → theme tokens |
| `resources/js/layouts/auth/AuthSimpleLayout.vue` | zinc-* → theme tokens |
| `resources/js/components/AppHeader.vue` | neutral-*, text-black, bg-black → theme tokens; remove Laravel docs link |
| `resources/js/components/AppearanceTabs.vue` | neutral-*, bg-white → bg-muted/bg-background |
| `resources/js/components/NavFooter.vue` | neutral-* → text-muted-foreground |
| `resources/js/components/UserInfo.vue` | text-black → text-foreground |
| `resources/js/layouts/auth/AuthCardLayout.vue` | text-black → theme token |
| `resources/js/pages/settings/Profile.vue` | text-neutral-600 → text-muted-foreground |
| `resources/js/pages/settings/Password.vue` | text-neutral-600 → text-muted-foreground |

Not touching: `text-green-600` success messages — semantic status colors are acceptable.

## Miscellaneous Branding

| Item | Change |
|------|--------|
| `public/favicon.svg` | Replace with Partner365 shield SVG |
| `resources/js/app.ts` | Progress bar #4B5563 → #4F46E5, fallback 'Laravel' → 'Partner365' |
| `.env.example` | APP_NAME=Laravel → APP_NAME=Partner365 |
| `config/app.php` | Fallback 'Laravel' → 'Partner365' |
| `resources/js/components/AppSidebar.vue` | Remove Laravel docs link from footer |
| `resources/js/components/AppHeader.vue` | Remove Documentation link |
