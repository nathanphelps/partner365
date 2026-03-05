# Partner Trust Score Design

## Overview

A composite trust score (0-100) for each partner organization, calculated daily from DNS hygiene signals and existing Microsoft Graph tenant metadata. Displayed as a sortable column on the partners list and as a detailed breakdown card on the partner detail page.

## Use Case

Risk-based decision support: show admins a score so they can make informed decisions about enabling B2B policies (inbound, MFA trust, etc.). The score is advisory only -- no automated gating.

## Score Components

### DNS Hygiene (60 points total)

| Signal | Points | Check Method |
|--------|--------|-------------|
| DMARC record present | 15 | DNS TXT lookup `_dmarc.<domain>` |
| DMARC policy is `reject` or `quarantine` (not `none`) | 5 | Parse DMARC record `p=` value |
| SPF record present | 15 | DNS TXT lookup for `v=spf1` |
| DKIM selector discoverable | 5 | DNS TXT lookup `selector1._domainkey.<domain>` (common selectors) |
| DNSSEC enabled | 10 | DNS query with DNSSEC validation |
| Domain age >= 2 years | 5 | RDAP lookup |
| Domain age >= 5 years | 5 | RDAP lookup (bonus, cumulative with above) |

### Entra ID / Graph Metadata (40 points total)

| Signal | Points | Source |
|--------|--------|--------|
| Tenant has >= 1 verified domain | 15 | Already synced tenant data |
| Tenant has multiple verified domains | 5 | Already synced tenant data |
| MFA trust is reciprocated (partner has MFA configured) | 10 | Graph API cross-tenant policy read |
| Partner tenant age >= 1 year | 5 | Graph tenant metadata |
| Partner tenant age >= 3 years | 5 | Graph tenant metadata |

## Score Tiers (for color coding)

- **80-100** -- High trust (green)
- **50-79** -- Medium trust (amber)
- **0-49** -- Low trust (red)

## Backend Architecture

### New Service: `TrustScoreService`

- `calculateScore(PartnerOrganization $partner): TrustScoreResult` -- runs all checks, returns score + breakdown
- DNS lookups via PHP's `dns_get_record()` -- no external dependencies
- RDAP lookups via HTTP to `rdap.org` (public, no auth)
- Results stored on the `partner_organizations` table: `trust_score` (int), `trust_score_breakdown` (JSON), `trust_score_calculated_at` (timestamp)

### New Command: `php artisan score:partners`

- Runs daily via scheduler
- Iterates all partners with a `domain` set, calculates score, stores result
- Skips partners without a domain (score = null)

### Controller Change

`PartnerController` passes `trust_score`, `trust_score_breakdown`, and `trust_score_calculated_at` as Inertia props (already available from the model).

## Frontend

### Partners List Page

- New "Trust Score" column -- displays the numeric score with color-coded badge (green/amber/red)
- Column is sortable

### Partner Detail Page

- New "Trust Score" card -- shows the composite score prominently, with a breakdown table listing each signal, its status (pass/fail), and points awarded
- Shows "Last calculated: <relative time>"

## Data Sources

All Tier 1 (no API keys) and Tier 3 (already available from Graph):

- **DNS records**: `dns_get_record()` for SPF, DMARC, DKIM, DNSSEC, MX
- **RDAP**: Public HTTP endpoint at `rdap.org` for domain age (WHOIS replacement)
- **Graph API**: Tenant metadata and cross-tenant policy data already synced

## Refresh Cadence

- Daily via Laravel scheduler (alongside existing sync jobs)
- Stored results persist between calculations
- Partners without a domain get no score (null)

## Testing

- `TrustScoreService` unit tests with mocked DNS responses (wrapper around `dns_get_record` for testability) and `Http::fake()` for RDAP
- Feature test for the `score:partners` command
- Existing partner detail/list page tests updated to assert score props are passed
