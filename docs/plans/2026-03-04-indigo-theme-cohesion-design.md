# Indigo Theme Cohesion Design

**Date:** 2026-03-04
**Goal:** Replace stark black-and-white page backgrounds and header with indigo-tinted colors that visually connect to the sidebar and landing page.

## Problem

The sidebar uses a rich dark navy palette (`hsl(243 47% 12%)`), and the landing page has indigo accents with a subtle gradient backdrop. But the authenticated app pages and header are pure white (`hsl(0 0% 100%)`) with neutral gray accents — creating a jarring disconnect.

## Design

### Header Treatment

Give the sidebar header (`AppSidebarHeader.vue`) and app header (`AppHeader.vue`) a distinct indigo-tinted background instead of inheriting plain white from `bg-background`.

- Light mode: `hsl(232 30% 96%)` — a soft, noticeable indigo wash
- Dark mode: `hsl(232 20% 10%)` — a blue-tinted dark surface

### Page Background (CSS Variables)

Change `--background` from pure white/black to blue-gray tinted:

| Variable | Current (light) | New (light) | Current (dark) | New (dark) |
|----------|-----------------|-------------|-----------------|------------|
| `--background` | `hsl(0 0% 100%)` | `hsl(230 25% 97%)` | `hsl(0 0% 3.9%)` | `hsl(230 15% 7%)` |
| `--muted` | `hsl(0 0% 96.1%)` | `hsl(230 20% 95%)` | `hsl(0 0% 16.08%)` | `hsl(230 15% 16%)` |
| `--accent` | `hsl(0 0% 96.1%)` | `hsl(230 20% 95%)` | `hsl(0 0% 14.9%)` | `hsl(230 15% 15%)` |
| `--secondary` | `hsl(0 0% 92.1%)` | `hsl(230 15% 91%)` | `hsl(0 0% 14.9%)` | `hsl(230 15% 15%)` |
| `--border` | `hsl(0 0% 92.8%)` | `hsl(230 15% 91%)` | `hsl(0 0% 14.9%)` | `hsl(230 12% 15%)` |
| `--input` | `hsl(0 0% 89.8%)` | `hsl(230 15% 89%)` | `hsl(0 0% 14.9%)` | `hsl(230 12% 15%)` |

### Cards Stay White

Keep `--card` as pure white (`hsl(0 0% 100%)`) in light mode so cards float above the tinted background with visual depth. Dark mode cards stay at their current value.

### What Stays the Same

- Sidebar colors (already correct)
- Landing page (already has indigo character)
- Primary/ring colors (indigo `hsl(239 84% 67%)`)
- All foreground/text colors
- Popover colors (follow card)
- Chart colors

## Files to Modify

1. `resources/css/app.css` — Update CSS variables in `:root` and `.dark`
2. `resources/js/components/AppSidebarHeader.vue` — Add header background class
3. `resources/js/components/AppHeader.vue` — Add header background class

## Visual Result

Dark navy sidebar → soft indigo-tinted header → subtle blue-gray content area with white cards. A smooth gradient of visual intensity from left to right.
