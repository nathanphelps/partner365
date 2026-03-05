# GD-OTS Inspired Theme Design

## Overview

Update Partner365's color scheme and typography to align with the General Dynamics OTS (gdots.com) corporate design language. The current indigo/purple shadcn-vue theme shifts to a navy/corporate blue palette with Roboto typography, while preserving dark mode support and existing component structure.

## Scope

- Color scheme update (CSS variables only)
- Typography swap (Inter to Roboto/Open Sans)
- Dark mode preserved with navy-toned variant
- No component shape/shadow/layout changes

## Color Palette

### Light Mode (`:root`)

| Token | New Value | Hex | Notes |
|-------|-----------|-----|-------|
| `--background` | `hsl(0 0% 97%)` | `#f7f7f7` | GD-OTS light gray |
| `--foreground` | `hsl(0 0% 19%)` | `#313131` | GD-OTS dark text |
| `--card` | `hsl(0 0% 100%)` | `#ffffff` | White cards |
| `--card-foreground` | `hsl(0 0% 19%)` | `#313131` | Match foreground |
| `--popover` | `hsl(0 0% 100%)` | `#ffffff` | White popovers |
| `--popover-foreground` | `hsl(0 0% 19%)` | `#313131` | Match foreground |
| `--primary` | `hsl(208 100% 29%)` | `#005293` | GD-OTS CTA blue |
| `--primary-foreground` | `hsl(0 0% 100%)` | `#ffffff` | White on blue |
| `--secondary` | `hsl(0 0% 93%)` | `#eeeeee` | GD-OTS neutral gray |
| `--secondary-foreground` | `hsl(0 0% 19%)` | `#313131` | Dark text |
| `--muted` | `hsl(0 0% 95%)` | `#f2f2f2` | Light muted bg |
| `--muted-foreground` | `hsl(0 0% 42%)` | `#6b6b6b` | GD-OTS gray text |
| `--accent` | `hsl(200 40% 92%)` | — | Subtle blue tint |
| `--accent-foreground` | `hsl(0 0% 19%)` | `#313131` | Dark text |
| `--destructive` | `hsl(0 84.2% 60.2%)` | — | Unchanged (functional) |
| `--destructive-foreground` | `hsl(0 0% 98%)` | — | Unchanged |
| `--border` | `hsl(0 0% 81%)` | `#cecece` | GD-OTS border |
| `--input` | `hsl(0 0% 84%)` | `#d6d6d6` | Input borders |
| `--ring` | `hsl(211 76% 43%)` | `#1e73be` | GD-OTS link blue |
| `--radius` | `0.5rem` | — | Unchanged |
| `--header` | `hsl(0 0% 96%)` | `#f5f5f5` | Neutral light |

### Sidebar (Dark Navy)

| Token | New Value | Notes |
|-------|-----------|-------|
| `--sidebar-background` | `hsl(212 100% 12%)` | Deep navy `#001f3d` |
| `--sidebar-foreground` | `hsl(210 40% 80%)` | Light blue-gray text |
| `--sidebar-primary` | `hsl(210 60% 92%)` | Near-white highlight |
| `--sidebar-primary-foreground` | `hsl(212 100% 12%)` | Navy text on light |
| `--sidebar-accent` | `hsl(212 80% 20%)` | Medium navy accent |
| `--sidebar-accent-foreground` | `hsl(210 40% 80%)` | Light text |
| `--sidebar-border` | `hsl(212 80% 18%)` | Dark navy border |
| `--sidebar-ring` | `hsl(211 76% 43%)` | Focus ring blue |

### Chart Colors

| Token | New Value | Notes |
|-------|-----------|-------|
| `--chart-1` | `hsl(208 100% 29%)` | Primary navy |
| `--chart-2` | `hsl(197 60% 45%)` | Teal blue |
| `--chart-3` | `hsl(211 76% 43%)` | Medium blue |
| `--chart-4` | `hsl(43 74% 66%)` | Gold accent |
| `--chart-5` | `hsl(173 58% 39%)` | Teal green |

### Dark Mode (`.dark`)

| Token | New Value | Notes |
|-------|-----------|-------|
| `--background` | `hsl(212 30% 7%)` | Dark navy-gray |
| `--foreground` | `hsl(0 0% 98%)` | Off-white text |
| `--card` | `hsl(212 25% 10%)` | Dark card bg |
| `--card-foreground` | `hsl(0 0% 98%)` | Light text |
| `--popover` | `hsl(212 25% 10%)` | Match card |
| `--popover-foreground` | `hsl(0 0% 98%)` | Light text |
| `--primary` | `hsl(208 100% 29%)` | Same navy blue |
| `--primary-foreground` | `hsl(0 0% 100%)` | White |
| `--secondary` | `hsl(212 20% 15%)` | Dark gray |
| `--secondary-foreground` | `hsl(0 0% 98%)` | Light text |
| `--muted` | `hsl(212 20% 16%)` | Dark muted |
| `--muted-foreground` | `hsl(0 0% 64%)` | Medium gray |
| `--accent` | `hsl(212 25% 15%)` | Dark blue accent |
| `--accent-foreground` | `hsl(0 0% 98%)` | Light text |
| `--destructive` | `hsl(0 84% 60%)` | Unchanged |
| `--destructive-foreground` | `hsl(0 0% 98%)` | Unchanged |
| `--border` | `hsl(212 20% 15%)` | Dark border |
| `--input` | `hsl(212 20% 15%)` | Dark input |
| `--ring` | `hsl(211 76% 43%)` | Blue focus ring |
| `--header` | `hsl(212 25% 10%)` | Dark header |

Dark mode chart colors:

| Token | New Value |
|-------|-----------|
| `--chart-1` | `hsl(211 76% 50%)` |
| `--chart-2` | `hsl(197 60% 50%)` |
| `--chart-3` | `hsl(30 80% 55%)` |
| `--chart-4` | `hsl(43 74% 60%)` |
| `--chart-5` | `hsl(173 58% 45%)` |

Dark mode sidebar:

| Token | New Value |
|-------|-----------|
| `--sidebar-background` | `hsl(212 100% 8%)` |
| `--sidebar-foreground` | `hsl(210 40% 80%)` |
| `--sidebar-primary` | `hsl(210 60% 92%)` |
| `--sidebar-primary-foreground` | `hsl(212 100% 12%)` |
| `--sidebar-accent` | `hsl(212 80% 16%)` |
| `--sidebar-accent-foreground` | `hsl(210 40% 80%)` |
| `--sidebar-border` | `hsl(212 80% 14%)` |
| `--sidebar-ring` | `hsl(211 76% 43%)` |

## Typography

Replace Inter with Roboto as primary, Open Sans as fallback:

```
'Roboto', 'Open Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'
```

Load via Google Fonts `<link>` in the Blade layout template.

## Files to Modify

1. **`resources/css/app.css`** — All CSS variable values + `--font-sans` definition
2. **`resources/views/app.blade.php`** (or equivalent Blade layout) — Add Google Fonts `<link>` for Roboto + Open Sans

## What Does NOT Change

- Component shapes, shadows, border-radius
- Semantic status colors (green/amber/red in TrustScoreBadge etc.)
- Destructive/error color (red)
- Layout structure (sidebar, header, content areas)
- Component variant logic (CVA patterns in shadcn-vue)
