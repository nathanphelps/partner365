# Stage 1: Generate Wayfinder route helpers
FROM dunglas/frankenphp:php8.4 AS route-generator

RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_sqlite pcntl intl

WORKDIR /app

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate

# Stage 2: Build frontend assets
FROM node:22-alpine AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources/ resources/
COPY --from=route-generator /app/resources/js/routes resources/js/routes
COPY --from=route-generator /app/resources/js/actions resources/js/actions
COPY --from=route-generator /app/resources/js/wayfinder resources/js/wayfinder
COPY vite.config.ts tsconfig.json ./
COPY public/ public/
RUN DOCKER_BUILD=1 npm run build

# Stage 3: PHP production image
FROM dunglas/frankenphp:php8.4 AS production

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    supervisor \
    curl \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN install-php-extensions \
    pdo_sqlite \
    pcntl \
    intl

# Set working directory
WORKDIR /app

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code
COPY . .

# Copy built frontend assets from stage 1
COPY --from=frontend-build /app/public/build public/build

# Run post-install scripts and optimize
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# Copy supervisord config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Create storage and cache directories with correct permissions
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && mkdir -p database \
    && chown -R www-data:www-data storage bootstrap/cache database public

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

ENTRYPOINT ["/entrypoint.sh"]
