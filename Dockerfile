# My Mate - all-in-one application image (web UI + engine daemons).
#
# Runs php-fpm + nginx + the background daemons (loop, Horizon, Reverb, scheduler, the
# remote-agent hub) under supervisor. PostgreSQL and Redis are NOT in here - point the
# container at them with env vars (see docker-compose.yml for a ready-to-run stack).
#
# Build:  docker build -t athenanetworks/mymate:1.0.0 .
# Run:    docker compose up      (brings up postgres + redis + this image)

# --- 1. frontend assets ------------------------------------------------------
FROM node:20-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./
# The public Pusher-protocol app key baked into the SPA (channel auth uses the per-install
# REVERB_APP_SECRET at runtime, so this is safe to share).
ARG VITE_REVERB_APP_KEY=mymate
RUN VITE_REVERB_APP_KEY="$VITE_REVERB_APP_KEY" npx vite build

# --- 2. fping 5.5 (JSON output; distro packages are too old) -----------------
FROM debian:bookworm-slim AS fping
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential autoconf automake libtool curl ca-certificates \
    && curl -fsSL https://github.com/schweikert/fping/releases/download/v5.5/fping-5.5.tar.gz | tar xz \
    && cd fping-5.5 && ./configure --prefix=/usr/local && make && make install

# --- 2b. rusted backup engine (Go; the 1.26 toolchain is auto-fetched) --------
FROM golang:1.24-bookworm AS rusted
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates \
    && git clone --depth 1 https://github.com/JoshFinlayAU/rusted.git /src
WORKDIR /src
RUN CGO_ENABLED=0 GOTOOLCHAIN=auto go build -trimpath -o /rusted ./cmd/rusted

# --- 3. composer (named stage so `COPY --from=composer` works everywhere) -----
FROM composer:2 AS composer

# --- 4. runtime --------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS runtime

# The servers we run under supervisor (openssl, for the self-signed HTTPS cert, is already
# in the base image).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx supervisor tini postgresql-client procps libcap2-bin ca-certificates git \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions via the maintained installer - it pulls the right C libraries, builds redis
# from source (pecl.php.net is flaky), and cleans up build deps so the image stays lean.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql pgsql gd zip intl bcmath sockets pcntl snmp sodium

# phpredis straight from its GitHub release - pecl.php.net is unreachable from some build
# networks, so don't depend on it.
ADD https://github.com/phpredis/phpredis/archive/refs/tags/6.1.0.tar.gz /tmp/phpredis.tar.gz
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && tar -xzf /tmp/phpredis.tar.gz -C /tmp \
    && cd /tmp/phpredis-6.1.0 && phpize && ./configure && make -j"$(nproc)" && make install \
    && docker-php-ext-enable redis && cd / && rm -rf /tmp/phpredis* \
    && apt-get purge -y $PHPIZE_DEPS && rm -rf /var/lib/apt/lists/* \
    && php -m | grep -qi '^redis$'

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Vendor first (cached until composer files change).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# App source, then finish the autoloader + package manifest with all extensions present.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# Built assets + fping.
COPY --from=assets /app/public/build ./public/build
COPY --from=fping /usr/local/sbin/fping /usr/local/sbin/fping
RUN setcap cap_net_raw+ep /usr/local/sbin/fping

# Rusted backup engine + its data dirs (the entrypoint generates the config on first start).
COPY --from=rusted /rusted /usr/local/bin/rusted
RUN mkdir -p /var/lib/rusted/backups /etc/rusted \
    && chown -R www-data:www-data /var/lib/rusted /etc/rusted

# Container config + entrypoint.
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/zz-mymate-uploads.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/mymate.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions \
        storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/mymate.conf"]
