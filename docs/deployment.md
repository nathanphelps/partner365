# Deployment

## Docker (Recommended)

The simplest way to deploy Partner365 is with Docker. The single container includes FrankenPHP (via Laravel Octane), a queue worker, and the task scheduler — no external dependencies required.

### Quick Start

```bash
# Build and run
docker compose up -d --build

# Or build and run directly
docker build -t partner365:latest .
docker run -d -p 8000:8000 \
  -e APP_KEY=base64:your-key-here \
  -e MICROSOFT_GRAPH_TENANT_ID=your-tenant-id \
  -e MICROSOFT_GRAPH_CLIENT_ID=your-client-id \
  -e MICROSOFT_GRAPH_CLIENT_SECRET=your-secret \
  -v partner365-db:/app/database \
  partner365:latest
```

### What's Inside the Container

| Process | Description |
|---------|-------------|
| FrankenPHP (Octane) | Web server on port 8000 |
| Queue Worker | Processes background jobs (`database` driver) |
| Scheduler | Runs `schedule:work` for sync commands |

All processes are managed by supervisord. The container exits if the web server dies.

### Database

SQLite by default — the database file lives at `/app/database/database.sqlite`. Mount a volume to persist data:

```bash
docker run -v partner365-db:/app/database ...
```

To use an external database instead, set env vars:

```env
DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=partner365
DB_USERNAME=partner365
DB_PASSWORD=your-password
```

### Health Check

The container exposes a health check at `GET /health` that returns:

```json
{"status": "ok", "database": "ok"}
```

Docker's built-in `HEALTHCHECK` polls this endpoint every 30 seconds.

### Docker Compose

The included `docker-compose.yml` provides a ready-to-use setup:

```bash
# Copy and configure .env
cp .env.example .env
# Edit .env with your APP_KEY and Graph API credentials

# Build and start
docker compose up -d --build

# View logs
docker compose logs -f

# Stop
docker compose down
```

The Compose file maps port 8000 (configurable via `APP_PORT` env var) and creates a named volume for the SQLite database.

### Reverse Proxy

In production, place a reverse proxy (Nginx, Caddy, Traefik) in front of the container to handle TLS:

```nginx
server {
    listen 443 ssl;
    server_name partner365.yourdomain.com;

    ssl_certificate /etc/ssl/certs/partner365.pem;
    ssl_certificate_key /etc/ssl/private/partner365.key;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Updating (Docker)

```bash
git pull origin main
docker compose up -d --build
```

The entrypoint script automatically runs migrations on container start.

---

## Bare Metal / VM

For deployments without Docker.

### Requirements

- PHP 8.2+ with extensions: `mbstring`, `xml`, `ctype`, `json`, `bcmath`, `pdo_pgsql` (or `pdo_sqlite`)
- Composer 2.x
- Node.js 18+ (for building frontend assets)
- PostgreSQL 14+ (recommended for production) or SQLite
- A web server (Nginx, Apache, or Laravel Octane)
- Cron access (for the scheduler)

## Production Setup

### 1. Clone and Install

```bash
git clone https://github.com/nathanphelps/partner365.git
cd partner365

composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 2. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with production values:

```env
APP_NAME=Partner365
APP_ENV=production
APP_DEBUG=false
APP_URL=https://partner365.yourdomain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=partner365
DB_USERNAME=partner365
DB_PASSWORD=your-secure-password

# Cache & Session
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

# Graph API (see docs/azure-setup.md)
MICROSOFT_GRAPH_TENANT_ID=your-tenant-id
MICROSOFT_GRAPH_CLIENT_ID=your-client-id
MICROSOFT_GRAPH_CLIENT_SECRET=your-client-secret
```

### 3. Run Migrations

```bash
php artisan migrate --force
```

### 4. Optimize for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 5. Set Up Scheduler

Add this cron entry to run the Laravel scheduler:

```cron
* * * * * cd /path-to/partner365 && php artisan schedule:run >> /dev/null 2>&1
```

This runs the background commands:
- `sync:partners` — every 15 minutes
- `sync:guests` — every 15 minutes
- `sync:access-reviews` — every 15 minutes
- `score:partners` — daily (calculates domain trust scores)

### 6. Set Up Queue Worker (Optional)

If using queued jobs (e.g., for future bulk import):

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Use a process manager like Supervisor to keep it running:

```ini
[program:partner365-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path-to/partner365/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/path-to/partner365/storage/logs/worker.log
```

## Web Server Configuration

### Nginx

```nginx
server {
    listen 80;
    server_name partner365.yourdomain.com;
    root /path-to/partner365/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Apache

Ensure `mod_rewrite` is enabled. The `.htaccess` in the `public/` directory handles URL rewriting automatically.

## Environment Variables Reference

| Variable | Required | Default | Description |
|----------|:--------:|---------|-------------|
| `APP_NAME` | No | `Laravel` | Application name shown in UI |
| `APP_ENV` | Yes | `local` | `local`, `staging`, or `production` |
| `APP_DEBUG` | Yes | `true` | Set to `false` in production |
| `APP_URL` | Yes | — | Full URL of the application |
| `DB_CONNECTION` | Yes | `sqlite` | `pgsql` for production |
| `DB_HOST` | Yes* | — | Database host (*if using pgsql) |
| `DB_DATABASE` | Yes | — | Database name |
| `DB_USERNAME` | Yes* | — | Database user |
| `DB_PASSWORD` | Yes* | — | Database password |
| `MICROSOFT_GRAPH_TENANT_ID` | Yes | — | Azure AD tenant ID |
| `MICROSOFT_GRAPH_CLIENT_ID` | Yes | — | App registration client ID |
| `MICROSOFT_GRAPH_CLIENT_SECRET` | Yes | — | App registration client secret |
| `MICROSOFT_GRAPH_CLOUD_ENVIRONMENT` | No | `commercial` | Cloud environment (`commercial` or `gcc_high`) |
| `MICROSOFT_GRAPH_SCOPES` | No | Auto from cloud env | OAuth scopes (overrides cloud env default) |
| `MICROSOFT_GRAPH_BASE_URL` | No | Auto from cloud env | Graph API base URL (overrides cloud env default) |
| `MICROSOFT_GRAPH_SYNC_INTERVAL` | No | `15` | Sync interval in minutes |

## Initial Data

After deployment, you'll need to:

1. **Create an admin user** — Register via the web interface, then update the role in the database:
   ```bash
   php artisan tinker
   >>> User::first()->update(['role' => 'admin']);
   ```

2. **Run initial sync** to populate partner and guest data:
   ```bash
   php artisan sync:partners
   php artisan sync:guests
   ```

3. **Create onboarding templates** — Log in as admin and navigate to Templates to create reusable policy configurations.

## Updating (Bare Metal)

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Monitoring

- **Health check** — `GET /health` returns JSON with app and database status
- **Laravel logs** — `storage/logs/laravel.log` (bare metal) or `docker compose logs` (Docker)
- **Sync output** — Run commands manually to check: `php artisan sync:partners -v`
- **Graph API errors** — Check logs for `GraphApiException` entries
- **Failed jobs** — `php artisan queue:failed` (if using queues)

## Backups

Back up regularly:
- Database (PostgreSQL `pg_dump` or copy SQLite file / Docker volume)
- `.env` file (contains secrets)
- `storage/` directory (logs, cache)

The application itself is stateless and can be redeployed from git at any time.
