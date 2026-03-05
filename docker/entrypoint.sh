#!/bin/bash
set -e

# Ensure SQLite database exists if using SQLite
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/app/database/database.sqlite}"
    if [ ! -f "$DB_PATH" ]; then
        echo "Creating SQLite database at $DB_PATH..."
        touch "$DB_PATH"
    fi
    chown www-data:www-data "$DB_PATH"
fi

# Ensure storage directories have correct permissions
chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Run migrations
php artisan migrate --force

# Cache configuration for production
php artisan optimize

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
