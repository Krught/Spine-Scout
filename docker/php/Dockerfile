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

# Run the Symfony auto-scripts now that the full source is in place
ENV APP_ENV=prod
ENV APP_DEBUG=0
RUN composer run-script post-install-cmd --no-interaction \
    && mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var vendor

COPY docker/php/entrypoint.sh /usr/local/bin/spinescout-entrypoint
RUN chmod +x /usr/local/bin/spinescout-entrypoint

EXPOSE 9000
ENTRYPOINT ["spinescout-entrypoint"]
CMD ["php-fpm"]
