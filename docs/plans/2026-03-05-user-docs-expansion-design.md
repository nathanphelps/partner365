# User Documentation Expansion Design

**Date:** 2026-03-05
**Status:** Approved

## Problem

The existing user documentation (21 files, ~600 lines total) provides basic structural coverage of all app sections but lacks depth. Complex features like Access Reviews, Conditional Access, and Entitlements are surface-level. There are no best practices, troubleshooting guides, use-case scenarios, glossary, or onboarding walkthrough.

## Audience

Mixed: some users are M365-savvy IT admins, others are less technical (security analysts, compliance officers). Documentation should explain *why* alongside *how*, assuming M365 familiarity but not deep Entra ID expertise.

## Approach

Expand + Split: grow existing docs to 3-5x depth, split longer topics into multiple pages where natural, add new standalone pages and a Concepts section. Keeps existing structure (mirrors app navigation) while adding depth.

## Target Scope

~33 files, ~2,500-3,500 lines total (up from 21 files, ~600 lines).

## New Standalone Pages

### First Steps Walkthrough (`getting-started/00-first-steps.md`)
- Prerequisites (what IT should have configured)
- First login experience
- Guided walkthrough: add first partner, invite first guest, review dashboard
- What to do next (links to deeper sections)
- ~80-120 lines

### Glossary (`glossary/01-glossary.md`)
- Alphabetical reference of key terms
- Terms: B2B Collaboration, B2B Direct Connect, Cross-Tenant Access Policy, Conditional Access, Device Compliance, Entitlement Management, MFA Trust, Partner Organization, Trust Score, Access Package, Access Review, Sensitivity Labels, SharePoint Site Permissions
- Each term: 2-3 sentence definition + link to relevant doc page
- ~100-150 lines

## New Concepts Section (`concepts/`)

Cross-cutting topics for less-technical users:

| File | Content | Lines |
|------|---------|-------|
| `01-cross-tenant-policies.md` | Inbound vs outbound, B2B collab vs direct connect, policy composition, mapping to Entra admin center | 100-120 |
| `02-b2b-collaboration.md` | Guest accounts in Entra ID, invitation/redemption flow, guest vs member, default access | 80-100 |
| `03-trust-score.md` | Score factors, calculation methodology, low/medium/high meaning, improvement guidance | 60-80 |
| `04-security-model.md` | RBAC, read-only vs write features, audit trail, Zero Trust alignment | 80-100 |

## Expanded Existing Sections

### Getting Started (2 → 3 files)
- `00-first-steps.md` — New walkthrough (see above)
- `01-overview.md` — Expand key concepts, add "How Partner365 Fits Into Your M365 Security"
- `02-dashboard.md` — Explain metrics, action items, how to act on them

### Partners (3 → 5 files)
- `01-viewing-partners.md` — Expand filtering/sorting, column explanations
- `02-adding-partners.md` — Prerequisites, template guidance, what happens behind the scenes
- `03-partner-details.md` — Policy deep dive (inbound/outbound, collab/direct connect), trust score
- `04-best-practices.md` — **New.** When to add partners, policy recommendations, review cadence
- `05-troubleshooting.md` — **New.** Partner not found, sync issues, policy not applying

### Guests (3 → 5 files)
- `01-guest-list.md` — Column explanations, bulk actions detail
- `02-inviting-guests.md` — Prerequisites, guest experience, invitation lifecycle
- `03-guest-details.md` — Access info tabs with context
- `04-best-practices.md` — **New.** Lifecycle management, stale account monitoring
- `05-troubleshooting.md` — **New.** Failed invitations, access issues, redemption problems

### Access Reviews (3 → 5 files)
- `01-overview.md` — Why reviews matter, compliance context, guest lifecycle relationship
- `02-creating-reviews.md` — Scope options, due date guidance, reviewer selection
- `03-reviewing-decisions.md` — Decision criteria, remediation effects, bulk decisions
- `04-best-practices.md` — **New.** Frequency recommendations, scope strategies, expired reviews
- `05-troubleshooting.md` — **New.** Missing instances, remediation failures

### Conditional Access (1 → 3 files)
- `01-viewing-policies.md` — Policy type explanations for external users
- `02-understanding-policies.md` — **New.** How CA affects guests, common patterns (MFA, location, device)
- `03-troubleshooting.md` — **New.** Guest blocked, report-only mode explained

### Entitlements (2 → 4 files)
- `01-access-packages.md` — What packages solve, when to use vs direct assignment
- `02-assignments.md` — Lifecycle detail, approval workflows
- `03-best-practices.md` — **New.** Package design, assignment policies, expiration strategies
- `04-troubleshooting.md` — **New.** Stuck assignments, missing approvals

### Reports (1 → 2 files)
- `01-compliance-reports.md` — Compliance context, metric interpretation, export formats
- `02-using-reports.md` — **New.** Common scenarios, audit preparation, sharing with stakeholders

### Activity (1 file — expand in place)
- `01-activity-log.md` — Action type explanations, filtering strategies, investigation use

### Admin (5 → 6 files)
- All 5 existing files expanded with more context
- `06-troubleshooting.md` — **New.** Consolidated: sync failures, Graph API permissions, user access

## Content Guidelines

- **Tone:** Professional but accessible. Explain *why* not just *how*.
- **Structure:** Brief intro → task-oriented sections → "Good to know" callouts → cross-references
- **Callouts:** `> **Good to know:**` blockquotes for tips and context
- **Cross-references:** Relative markdown links to related pages and Glossary
- **Target per file:** 60-150 lines
- **No visuals:** Text-only markdown

## What's NOT in scope
- Screenshots or visual aids
- Video content
- API/developer documentation
- Installation/deployment documentation
- Changes to the in-app docs rendering system (DocsController, Vue components)
