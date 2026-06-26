# syntax=docker/dockerfile:1.7

# ---- Stage 1: Composer dependencies ---------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./

# Install prod deps without running scripts (the app source isn't here yet)
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress

# ---- Stage 2: PHP-FPM runtime ---------------------------------------------
FROM php:8.4-fpm-alpine AS app

# System packages required by Symfony + Doctrine (Postgres) + intl + GD.
# Cloudflare bypass is delegated to the bundled FlareSolverr compose service, so
# no browser ships in this image.
RUN apk add --no-cache \
        bash \
        git \
        icu-libs \
        libpq \
        libzip \
        oniguruma \
        postgresql-client \
        zip \
        unzip \
        libjpeg-turbo \
        libpng \
        libwebp \
        freetype \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        oniguruma-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        freetype-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        opcache \
        pdo \
        pdo_pgsql \
        zip \
    && apk del --no-network .build-deps

# Production-ish PHP defaults; the override compose layer can replace these for dev
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-spinescout.ini

# Composer binary (handy for running scripts at container start in dev)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Bring in vendor from the composer stage first so subsequent app changes don't bust the cache
COPY --from=vendor /app/vendor ./vendor

# App source
COPY . .

# Stamp the build with its version. CI passes the released tag (e.g. "1.2.3"), a
# nightly stamp ("1.2.3N" - the latest release the nightly was built on), or a
# "sha-…"; a plain `docker build` (the dev stack) leaves it at "dev".
# config/services.yaml reads APP_VERSION at runtime for the UI version badge.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Symfony's Runtime boots the Dotenv component, which requires a .env file to
# exist (it throws otherwise), and compiling the prod cache below resolves a few
# env vars (DATABASE_URL, APP_SECRET). Real production configuration is injected
# as container environment variables by Compose, sourced from the operator's own
# .env that sits next to their docker-compose.yaml, and ALWAYS takes precedence
# (Dotenv never overrides existing environment variables). The values below are
# therefore build-time-only placeholders that let the framework bootstrap and the
# cache compile; they are overridden at runtime. The developer's local .env is
# excluded via .dockerignore and is never baked into the image.
ENV APP_ENV=prod
ENV APP_DEBUG=0
RUN printf '%s\n' \
        'APP_ENV=prod' \
        'APP_DEBUG=0' \
        'APP_SHARE_DIR=var/share' \
        'DEFAULT_URI=http://localhost' \
        'MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0' \
        'APP_SECRET=build-time-placeholder-overridden-at-runtime' \
        'DATABASE_URL=postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8' \
        > .env

# Run the Symfony auto-scripts now that the full source is in place, then compile
# the AssetMapper output. In prod Symfony does NOT serve /assets/* dynamically, so
# the fingerprinted files in public/assets/ (+ manifest.json) must be generated at
# build time or every CSS/JS request 404s. The nginx image copies this public/
# tree so the served files match the hashes the app renders.
RUN composer run-script post-install-cmd --no-interaction \
    && php bin/console asset-map:compile --no-interaction \
    && mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var vendor

COPY docker/php/entrypoint.sh /usr/local/bin/spinescout-entrypoint
RUN chmod +x /usr/local/bin/spinescout-entrypoint

EXPOSE 9000
ENTRYPOINT ["spinescout-entrypoint"]
CMD ["php-fpm"]
