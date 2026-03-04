# Partner365 Landing Page + Branded Auth Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the default Laravel welcome page and auth branding with a clean, Partner365-branded landing page and auth layout.

**Architecture:** Four files change — two branding components (`AppLogoIcon.vue`, `AppLogo.vue`), the landing page (`Welcome.vue`), and the auth layout (`AuthSimpleLayout.vue`). Branding components propagate to sidebar, auth pages, and anywhere else they're used. No backend changes needed — the existing `Route::inertia('/', 'Welcome', [...])` route stays as-is.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Tailwind CSS, Lucide icons (`lucide-vue-next`), Inertia.js (`@inertiajs/vue3`)

**Design doc:** `docs/plans/2026-03-04-landing-page-design.md`

---

### Task 1: Replace AppLogoIcon.vue with Partner365 shield SVG

**Files:**
- Modify: `resources/js/components/AppLogoIcon.vue`

**Context:** This component is used in: `AppLogo.vue` (sidebar), `AuthSimpleLayout.vue` (auth pages), `AuthCardLayout.vue`, `AuthSplitLayout.vue`, `AppSidebar.vue`, `AppHeader.vue`. It accepts a `className` prop and passes through `$attrs`. The SVG must use `fill="currentColor"` so color is inherited from parent. Current viewBox is `0 0 40 42`.

**Step 1: Replace the SVG**

Replace the entire `<svg>` contents in `AppLogoIcon.vue` with a new Partner365 shield mark. The shield is a clean geometric shape with "365" integrated. Keep the same component API: `className` prop, `inheritAttrs: false`, `v-bind="$attrs"`.

```vue
<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        :class="className"
        v-bind="$attrs"
    >
        <!-- Shield shape -->
        <path
            fill="currentColor"
            d="M24 2L6 10v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V10L24 2zm0 4.4L38 12v10c0 9.4-6.5 18.2-14 20.7V4.4zM10 12l14-5.6v38.3C16.5 42.2 10 33.4 10 24V12z"
            opacity="0.15"
        />
        <path
            fill="currentColor"
            d="M24 2L6 10v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V10L24 2zm14 20c0 9.4-6.5 18.2-14 20.7C16.5 40.2 10 31.4 10 22V12l14-5.6L38 12v10z"
        />
        <!-- "365" text -->
        <text
            x="24"
            y="28"
            text-anchor="middle"
            fill="currentColor"
            font-family="Inter, system-ui, sans-serif"
            font-size="11"
            font-weight="700"
        >365</text>
    </svg>
</template>
```

**Step 2: Visual verification**

Run: `npm run build`
Expected: Build succeeds with no errors.

Then open https://p365.d4.solutions in browser and verify:
- The shield icon renders in the login page header
- It looks correct at small sizes

**Step 3: Commit**

```bash
git add resources/js/components/AppLogoIcon.vue
git commit -m "feat: replace Laravel logo with Partner365 shield mark"
```

---

### Task 2: Update AppLogo.vue wordmark

**Files:**
- Modify: `resources/js/components/AppLogo.vue`

**Context:** This component shows the icon + text label in the sidebar. Currently says "Laravel Starter Kit".

**Step 1: Update the wordmark text**

Change the text from "Laravel Starter Kit" to "Partner365":

```vue
<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold"
            >Partner365</span
        >
    </div>
</template>
```

**Step 2: Build and verify**

Run: `npm run build`
Expected: Build succeeds. Log in and verify sidebar shows "Partner365" with the new icon.

**Step 3: Commit**

```bash
git add resources/js/components/AppLogo.vue
git commit -m "feat: update sidebar wordmark to Partner365"
```

---

### Task 3: Rewrite Welcome.vue as branded landing page

**Files:**
- Modify: `resources/js/pages/Welcome.vue` (full rewrite)

**Context:** The route at `/` renders this page via `Route::inertia('/', 'Welcome', ['canRegister' => ...])`. It receives `canRegister` as a prop. The page also has access to `$page.props.auth.user` to check if user is logged in (standard Inertia shared data). It currently imports routes from `@/routes` — keep using `dashboard`, `login`, `register` from there.

**Lucide icons available** (already in project): `Building2`, `Shield`, `Users`, `ClipboardList` — import from `lucide-vue-next`.

**Step 1: Rewrite Welcome.vue**

Replace the entire file with the branded landing page:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, ClipboardList, Shield, Users } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const features = [
    { icon: Building2, label: 'Partner Orgs', description: 'Manage external partner organizations and cross-tenant relationships' },
    { icon: Shield, label: 'Access Policies', description: 'Configure cross-tenant access and MFA trust settings' },
    { icon: Users, label: 'Guest Lifecycle', description: 'Invite, track, and manage B2B guest users across tenants' },
    { icon: ClipboardList, label: 'Activity Audit', description: 'Full audit trail of all partner and guest operations' },
];
</script>

<template>
    <Head title="Partner365 — M365 Partner Management">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div class="flex min-h-screen flex-col bg-white dark:bg-zinc-950">
        <!-- Header -->
        <header class="flex items-center justify-between px-6 py-4 lg:px-8">
            <div class="flex items-center gap-2.5">
                <AppLogoIcon class="size-8 text-indigo-600 dark:text-indigo-400" />
                <span class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Partner365</span>
            </div>
            <nav class="flex items-center gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
                    >
                        Sign in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero -->
        <main class="flex flex-1 flex-col items-center justify-center px-6 py-16 lg:py-24">
            <div class="relative flex flex-col items-center text-center">
                <!-- Subtle radial gradient background -->
                <div class="pointer-events-none absolute -top-32 h-96 w-96 rounded-full bg-indigo-100 opacity-40 blur-3xl dark:bg-indigo-900 dark:opacity-20" />

                <div class="relative">
                    <AppLogoIcon class="mx-auto size-16 text-indigo-600 dark:text-indigo-400 lg:size-20" />
                    <h1 class="mt-6 text-4xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 lg:text-5xl">
                        Partner365
                    </h1>
                    <p class="mx-auto mt-4 max-w-md text-lg text-zinc-600 dark:text-zinc-400">
                        Manage M365 partner organizations, cross-tenant policies, and guest users.
                    </p>
                    <div class="mt-8">
                        <Link
                            :href="$page.props.auth.user ? dashboard() : login()"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-3 text-sm font-medium text-white transition hover:bg-indigo-500"
                        >
                            Get Started
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Feature pills -->
            <div class="mt-16 grid max-w-2xl grid-cols-2 gap-4 lg:grid-cols-4 lg:gap-6">
                <div
                    v-for="feature in features"
                    :key="feature.label"
                    class="flex flex-col items-center rounded-xl border border-zinc-200 bg-white p-5 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div class="flex size-10 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-950">
                        <component :is="feature.icon" class="size-5 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <h3 class="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ feature.label }}</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ feature.description }}</p>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="px-6 py-6 text-center text-xs text-zinc-400 dark:text-zinc-600">
            &copy; {{ new Date().getFullYear() }} Partner365
        </footer>
    </div>
</template>
```

**Step 2: Build and verify**

Run: `npm run build`
Expected: Build succeeds. Visit https://p365.d4.solutions and verify:
- Header shows shield + "Partner365" left, "Sign in" button right
- Hero section with large icon, title, tagline, "Get Started" CTA
- Four feature cards below
- Footer with copyright
- Dark mode works (toggle via browser preference)

**Step 3: Commit**

```bash
git add resources/js/pages/Welcome.vue
git commit -m "feat: replace default welcome page with Partner365 landing page"
```

---

### Task 4: Brand the auth layout

**Files:**
- Modify: `resources/js/layouts/auth/AuthSimpleLayout.vue`

**Context:** This layout wraps all auth pages (Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge) via `AuthLayout.vue`. Currently a plain white/dark background with a centered form. We're adding:
- Indigo gradient background
- Partner365 wordmark visible below the icon

**Step 1: Update AuthSimpleLayout.vue**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-gradient-to-b from-indigo-50 to-white p-6 md:p-10 dark:from-indigo-950 dark:to-zinc-950"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex h-10 w-10 items-center justify-center rounded-md"
                        >
                            <AppLogoIcon
                                class="size-10 text-indigo-600 dark:text-indigo-400"
                            />
                        </div>
                        <span class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Partner365</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
```

**Changes from original:**
- Background: `bg-background` → `bg-gradient-to-b from-indigo-50 to-white dark:from-indigo-950 dark:to-zinc-950`
- Icon container: `h-9 w-9` → `h-10 w-10`; icon `size-9` → `size-10`
- Icon color: `fill-current text-[var(--foreground)] dark:text-white` → `text-indigo-600 dark:text-indigo-400`
- Added visible wordmark: `<span class="text-lg font-semibold ...">Partner365</span>` (was `sr-only` before)

**Step 2: Build and verify**

Run: `npm run build`
Expected: Build succeeds. Visit https://p365.d4.solutions/login and verify:
- Subtle indigo gradient background (light: indigo-50 → white, dark: indigo-950 → zinc-950)
- Partner365 shield icon + "Partner365" wordmark above the form title
- Form card unchanged
- Also check /register, /forgot-password to confirm they inherit the same branding

**Step 3: Commit**

```bash
git add resources/js/layouts/auth/AuthSimpleLayout.vue
git commit -m "feat: add branded indigo gradient background to auth layout"
```

---

### Task 5: Final build and verification

**Step 1: Run full build**

Run: `npm run build`
Expected: Clean build, no warnings.

**Step 2: Run lint and type check**

Run: `npm run lint && npm run types:check`
Expected: No lint errors, no type errors.

**Step 3: Visual verification checklist**

Open browser and check each page:
- [ ] `/` — Landing page renders correctly, responsive on mobile
- [ ] `/login` — Branded auth layout with gradient
- [ ] `/register` — Same branded layout
- [ ] `/forgot-password` — Same branded layout
- [ ] Log in → sidebar shows "Partner365" with shield icon
- [ ] Dark mode works on landing page and auth pages

**Step 4: Run backend tests (ensure nothing broke)**

Run: `php artisan test`
Expected: All tests pass. (No backend changes were made, but good to confirm.)
