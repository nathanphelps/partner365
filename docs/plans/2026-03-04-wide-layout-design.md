# Wide Layout Design for View Pages

## Problem

All Show/detail pages use narrow max-width constraints (`max-w-4xl`, `max-w-3xl`, `max-w-xl`), leaving significant unused horizontal space in the sidebar layout. Cards are stacked vertically even when they could sit side-by-side.

## Changes

### partners/Show.vue
- Remove `max-w-4xl` from outer container (full width)
- Wrap Policies + Notes cards in `grid grid-cols-1 lg:grid-cols-2 gap-6`
- Guest Users and Danger Zone remain full-width below the grid

### guests/Show.vue
- Remove `max-w-3xl` from outer container (full width)
- Cards stay stacked vertically (only 2 cards, no parallel content)

### templates/Create.vue
- Change `max-w-xl` to `max-w-3xl mx-auto` (wider, centered)

### templates/Edit.vue
- Change `max-w-xl` to `max-w-3xl mx-auto` (wider, centered)
- Danger Zone card also centered under the form

## Scope

CSS class changes only in `<template>` sections. No logic, component, or script changes.
