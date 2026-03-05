# Indigo Theme Cohesion Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace stark black-and-white page backgrounds and header with indigo-tinted colors that visually connect to the sidebar and landing page.

**Architecture:** Pure CSS variable changes plus two Vue template tweaks. The theme system uses CSS custom properties in `:root` and `.dark` blocks, consumed via Tailwind's `@theme inline` mapping. Headers need an explicit background class added to their template markup.

**Tech Stack:** Tailwind CSS v4, CSS custom properties, Vue 3 SFC templates

---

### Task 1: Update light-mode CSS variables to indigo-tinted values

**Files:**
- Modify: `resources/css/app.css:92-127` (`:root` block)

**Step 1: Update the `:root` background variables**

Replace the neutral gray values with indigo-tinted equivalents. Change these lines in the `:root` block:

```css
:root {
    --background: hsl(230 25% 97%);
    --foreground: hsl(0 0% 3.9%);
    --card: hsl(0 0% 100%);
    --card-foreground: hsl(0 0% 3.9%);
    --popover: hsl(0 0% 100%);
    --popover-foreground: hsl(0 0% 3.9%);
    --primary: hsl(239 84% 67%);
    --primary-foreground: hsl(0 0% 100%);
    --secondary: hsl(230 15% 91%);
    --secondary-foreground: hsl(0 0% 9%);
    --muted: hsl(230 20% 95%);
    --muted-foreground: hsl(0 0% 45.1%);
    --accent: hsl(230 20% 95%);
    --accent-foreground: hsl(0 0% 9%);
    --destructive: hsl(0 84.2% 60.2%);
    --destructive-foreground: hsl(0 0% 98%);
    --border: hsl(230 15% 91%);
    --input: hsl(230 15% 89%);
    --ring: hsl(239 84% 67%);
    /* ... chart/sidebar vars unchanged ... */
```

What changed (only these 6 values):
- `--background`: `hsl(0 0% 100%)` -> `hsl(230 25% 97%)`
- `--secondary`: `hsl(0 0% 92.1%)` -> `hsl(230 15% 91%)`
- `--muted`: `hsl(0 0% 96.1%)` -> `hsl(230 20% 95%)`
- `--accent`: `hsl(0 0% 96.1%)` -> `hsl(230 20% 95%)`
- `--border`: `hsl(0 0% 92.8%)` -> `hsl(230 15% 91%)`
- `--input`: `hsl(0 0% 89.8%)` -> `hsl(230 15% 89%)`

**Step 2: Verify the dev server compiles without errors**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds with no errors.

**Step 3: Commit**

```bash
git add resources/css/app.css
git commit -m "style: tint light-mode backgrounds with indigo wash"
```

---

### Task 2: Update dark-mode CSS variables to indigo-tinted values

**Files:**
- Modify: `resources/css/app.css:129-163` (`.dark` block)

**Step 1: Update the `.dark` background variables**

Replace the neutral dark values with blue-tinted equivalents. Change these lines in the `.dark` block:

```css
.dark {
    --background: hsl(230 15% 7%);
    --foreground: hsl(0 0% 98%);
    --card: hsl(0 0% 3.9%);
    --card-foreground: hsl(0 0% 98%);
    --popover: hsl(0 0% 3.9%);
    --popover-foreground: hsl(0 0% 98%);
    --primary: hsl(239 84% 67%);
    --primary-foreground: hsl(0 0% 100%);
    --secondary: hsl(230 15% 15%);
    --secondary-foreground: hsl(0 0% 98%);
    --muted: hsl(230 15% 16%);
    --muted-foreground: hsl(0 0% 63.9%);
    --accent: hsl(230 15% 15%);
    --accent-foreground: hsl(0 0% 98%);
    --destructive: hsl(0 84% 60%);
    --destructive-foreground: hsl(0 0% 98%);
    --border: hsl(230 12% 15%);
    --input: hsl(230 12% 15%);
    --ring: hsl(239 84% 67%);
    /* ... chart/sidebar vars unchanged ... */
```

What changed (only these 6 values):
- `--background`: `hsl(0 0% 3.9%)` -> `hsl(230 15% 7%)`
- `--secondary`: `hsl(0 0% 14.9%)` -> `hsl(230 15% 15%)`
- `--muted`: `hsl(0 0% 16.08%)` -> `hsl(230 15% 16%)`
- `--accent`: `hsl(0 0% 14.9%)` -> `hsl(230 15% 15%)`
- `--border`: `hsl(0 0% 14.9%)` -> `hsl(230 12% 15%)`
- `--input`: `hsl(0 0% 14.9%)` -> `hsl(230 12% 15%)`

**Step 2: Verify the dev server compiles without errors**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds with no errors.

**Step 3: Commit**

```bash
git add resources/css/app.css
git commit -m "style: tint dark-mode backgrounds with indigo wash"
```

---

### Task 3: Add header background CSS variable

**Files:**
- Modify: `resources/css/app.css:92-127` (`:root` block, add new variable)
- Modify: `resources/css/app.css:129-163` (`.dark` block, add new variable)
- Modify: `resources/css/app.css:10-62` (`@theme inline` block, add mapping)

**Step 1: Add `--header` CSS variable to `:root`**

Add after the `--sidebar` line at the end of `:root`:

```css
    --header: hsl(232 30% 96%);
```

**Step 2: Add `--header` CSS variable to `.dark`**

Add after the `--sidebar` line at the end of `.dark`:

```css
    --header: hsl(232 20% 10%);
```

**Step 3: Add Tailwind theme mapping in `@theme inline`**

Add after the `--color-sidebar-ring` line:

```css
    --color-header: var(--header);
```

This makes `bg-header` available as a Tailwind utility class.

**Step 4: Verify the build compiles**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds with no errors.

**Step 5: Commit**

```bash
git add resources/css/app.css
git commit -m "style: add header background CSS variable for theme"
```

---

### Task 4: Apply header background to AppSidebarHeader

**Files:**
- Modify: `resources/js/components/AppSidebarHeader.vue:17-19`

**Step 1: Add `bg-header` class to the header element**

The current `<header>` tag on line 18 has this class string:

```
"flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
```

Add `bg-header` to the beginning:

```
"bg-header flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
```

**Step 2: Verify the build compiles**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds with no errors.

**Step 3: Commit**

```bash
git add resources/js/components/AppSidebarHeader.vue
git commit -m "style: apply indigo-tinted background to sidebar header"
```

---

### Task 5: Apply header background to AppHeader

**Files:**
- Modify: `resources/js/components/AppHeader.vue:69`

**Step 1: Add `bg-header` class to the header container**

The outer div on line 69 currently has:

```
"border-b border-sidebar-border/80"
```

Change to:

```
"bg-header border-b border-sidebar-border/80"
```

**Step 2: Also style the breadcrumb bar (line 261-264)**

The breadcrumb container div on line 261 currently has:

```
"flex w-full border-b border-sidebar-border/70"
```

Change to:

```
"bg-header flex w-full border-b border-sidebar-border/70"
```

**Step 3: Verify the build compiles**

Run: `npm run build 2>&1 | tail -5`
Expected: Build succeeds with no errors.

**Step 4: Commit**

```bash
git add resources/js/components/AppHeader.vue
git commit -m "style: apply indigo-tinted background to app header"
```

---

### Task 6: Run full CI check and visual verification

**Step 1: Run lint check**

Run: `composer run lint:check`
Expected: No lint errors.

**Step 2: Run TypeScript check**

Run: `npm run types:check`
Expected: No type errors.

**Step 3: Run test suite**

Run: `php artisan test`
Expected: All tests pass (CSS-only changes should not break any tests).

**Step 4: Commit any lint fixes if needed**

```bash
git add -A
git commit -m "style: fix any lint issues from theme changes"
```
