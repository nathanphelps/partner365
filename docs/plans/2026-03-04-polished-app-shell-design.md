# Polished App Shell Design

**Date:** 2026-03-04
**Goal:** Make the sidebar, header, and layout shell feel like a polished productivity tool (Notion/Slack vibe) instead of a starter kit.

## Scope

Sidebar + header + layout shell only. No page content changes.

## Sidebar

### Logo Area
- Increase padding around the logo for more breathing room
- Add a subtle bottom separator to anchor it visually

### Navigation Items
- Increase vertical spacing between items (`gap-1` → `gap-2`)
- Add a left accent bar (3px indigo) on the active item for a bolder active state
- Keep `size="lg"` and `!size-5` icons we already have

### Grouping & Footer
- Remove the empty `NavFooter` stub (empty `footerNavItems` array is dead weight)
- Add a visual separator before the user area

### User Area
- More padding and a subtle top border/separator
- Slightly larger avatar for more presence

### Overall Spacing
- More generous padding throughout: `px-2` → `px-3`, `py-0` → `py-2`
- Productivity tools feel spacious, not cramped

## Header (AppSidebarHeader)

- Increase height slightly for more breathing room
- Keep the `bg-header` indigo tint
- Keep sidebar trigger + breadcrumbs

## Layout Shell

- Add top padding between header and page content (`pt-2` or `pt-4` on content wrapper) so pages don't slam against the header

## What Stays the Same

- Dark navy sidebar color
- Indigo-tinted page backgrounds
- All page content (tables, cards, dashboards)
- Logo icon design

## Files to Modify

1. `resources/js/components/AppSidebar.vue` — Logo area padding, remove NavFooter usage
2. `resources/js/components/NavMain.vue` — Item spacing, active state accent bar
3. `resources/js/components/NavFooter.vue` — Delete or gut
4. `resources/js/components/NavUser.vue` — Padding, separator, avatar size
5. `resources/js/components/AppSidebarHeader.vue` — Height adjustment
6. `resources/js/layouts/app/AppSidebarLayout.vue` or `resources/js/components/AppContent.vue` — Content top padding
