# Partner365 Landing Page + Branded Auth Design

## Context

The current `Welcome.vue` is the default Laravel starter kit page with generic Laravel branding. The auth pages use the Laravel logo. Both need to be replaced with Partner365 branding.

**Audience**: Internal IT admins who manage M365 partner organizations and guest users.
**Tone**: Clean, minimal, professional admin tool.
**Color**: Blue/indigo palette. Primary: indigo-600 (`#4F46E5`).

## Branding Components

### AppLogoIcon.vue

Replace the Laravel geometric logo SVG with a Partner365 shield mark. The shield incorporates "365" in its design. Must work at small sizes (sidebar, 20px) and large (landing hero, 64px+).

### AppLogo.vue

Update wordmark from "Laravel Starter Kit" to "Partner365".

## Landing Page (Welcome.vue)

A branded login gate for internal IT admins. Not a marketing site.

### Layout

```
┌─────────────────────────────────────────────┐
│  [Shield] Partner365              [Sign in] │
├─────────────────────────────────────────────┤
│                                             │
│              [Shield Icon]                  │
│             Partner365                      │
│                                             │
│   Manage M365 partner organizations,        │
│   cross-tenant policies, and guest users.   │
│                                             │
│           [ Get Started → ]                 │
│                                             │
│  ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐   │
│  │Ptnrs │  │Policy│  │Guest │  │Audit │   │
│  └──────┘  └──────┘  └──────┘  └──────┘   │
│                                             │
├─────────────────────────────────────────────┤
│  © 2026 Partner365                          │
└─────────────────────────────────────────────┘
```

### Specifications

- Self-contained page, no AppLayout wrapper (unauthenticated context)
- Dark mode support via Tailwind `dark:` classes
- Header: logo + wordmark left, "Sign in" / "Dashboard" link right (depending on auth state)
- Hero: large shield icon, "Partner365" wordmark, tagline, indigo CTA button linking to login
- Feature pills: 4 items with Lucide icons — Partner Orgs (Building2), Cross-Tenant Policies (Shield), Guest Lifecycle (Users), Activity Audit (ClipboardList)
- Subtle radial gradient behind hero for depth
- Footer: copyright line
- Props: `canRegister` (boolean) from existing route definition

## Auth Layout (AuthSimpleLayout.vue)

Centered card layout with branded background.

### Changes from current

- Replace Laravel logo with Partner365 shield + "Partner365" wordmark
- Add subtle indigo-tinted background gradient:
  - Light: indigo-50 → white
  - Dark: indigo-950 → background
- Card form area unchanged — already clean and well-structured

### Auth pages affected

All pages inherit from AuthSimpleLayout via AuthLayout.vue:
- Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge

No changes needed to individual auth page files.

## Color Tokens

Using Tailwind's built-in indigo palette for landing + auth only:
- Primary actions: `indigo-600` (hover: `indigo-500`)
- Background accents: `indigo-50` light / `indigo-950` dark
- Text: existing foreground/muted-foreground tokens (unchanged)

The main app UI (dashboard, partners, etc.) keeps its current neutral shadcn-vue theme tokens.

## Files to Change

| File | Action |
|------|--------|
| `resources/js/components/AppLogoIcon.vue` | Replace SVG with Partner365 shield |
| `resources/js/components/AppLogo.vue` | Update wordmark text |
| `resources/js/pages/Welcome.vue` | Full rewrite — branded landing page |
| `resources/js/layouts/auth/AuthSimpleLayout.vue` | Branded background + updated logo |
