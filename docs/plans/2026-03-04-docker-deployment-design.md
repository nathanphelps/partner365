# Docker Single-Container Deployment Design

## Overview

Package Partner365 as a single Docker container that runs anywhere: on-prem, Azure, AWS, generic VPS, or Kubernetes. The container includes the web server, queue worker, and task scheduler — no external dependencies required (SQLite by default).

## Container Architecture

Single container based on FrankenPHP (via Laravel Octane) running three processes managed by supervisord:

1. `php artisan octane:frankenphp` — Web server on port 8000
2. `php artisan queue:work --sleep=3 --tries=3` — Database queue worker
3. `php artisan schedule:work` — Scheduler for background sync commands

## Image Build (Multi-stage Dockerfile)

**Stage 1 — Node build:**
- Base: `node:22-alpine`
- Install dependencies, run `npm run build`
- Output: `public/build/` (Vite-compiled assets)

**Stage 2 — PHP production image:**
- Base: `dunglas/frankenphp:php8.4` (Debian-based)
- Install PHP extensions: `pdo_sqlite`, `pcntl`, `intl`
- Install supervisord
- `composer install --no-dev --optimize-autoloader`
- Copy built frontend assets from stage 1
- Copy application code
- Run `php artisan optimize` (caches config, routes, views)

## Process Management

`docker/supervisord.conf` manages all three processes:
- Web server is the critical process — if it dies, the container exits
- Queue worker and scheduler auto-restart on failure
- Logs go to stdout/stderr for container log collection

## Health Check

`/health` route returns JSON:
- `status`: "ok"
- `database`: connectivity check result

Dockerfile `HEALTHCHECK` uses `curl http://localhost:8000/health`.

## Database Strategy

SQLite by default. The database file lives at `database/database.sqlite` and should be volume-mounted for persistence. Switching to an external database (Postgres, MySQL) requires only env var changes:
- `DB_CONNECTION=pgsql` (or `mysql`)
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

No code changes needed — Laravel handles this natively.

## Entrypoint Script (`docker/entrypoint.sh`)

On container start:
1. Ensure SQLite file exists (if using SQLite)
2. Run `php artisan migrate --force`
3. Start supervisord

## Docker Compose (Local Dev)

```yaml
services:
  app:
    build: .
    ports:
      - "8000:8000"
    volumes:
      - db-data:/app/database
    env_file: .env
volumes:
  db-data:
```

## Files to Create/Modify

| File | Action |
|------|--------|
| `Dockerfile` | Create |
| `.dockerignore` | Create |
| `docker-compose.yml` | Create |
| `docker/supervisord.conf` | Create |
| `docker/entrypoint.sh` | Create |
| `routes/web.php` | Modify — add `/health` route |
| `composer.json` | Modify — add `laravel/octane` |
