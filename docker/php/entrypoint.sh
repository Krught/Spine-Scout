#!/usr/bin/env bash
set -euo pipefail

# Ensure the cover cache directory exists and is writable by php-fpm.
# Docker auto-creates missing bind-mount sources as root-owned; without this
# fixup, www-data couldn't write into the cache and warming would fail silently.
COVER_DIR=/var/www/html/book-covers
mkdir -p "$COVER_DIR"
chown -R www-data:www-data "$COVER_DIR" 2>/dev/null || true

# Wait for the database to be reachable before doing anything else.
if [[ -n "${DATABASE_URL:-}" ]]; then
    echo "[spinescout] waiting for database..."
    tries=0
    until php -r '
        $url = parse_url(getenv("DATABASE_URL"));
        $host = $url["host"] ?? "database";
        $port = $url["port"] ?? 5432;
        $fp = @fsockopen($host, (int) $port, $errno, $errstr, 2);
        if (!$fp) { exit(1); }
        fclose($fp);
        exit(0);
    '; do
        tries=$((tries + 1))
        if (( tries > 60 )); then
            echo "[spinescout] database never became reachable" >&2
            exit 1
        fi
        sleep 1
    done
    echo "[spinescout] database reachable"
fi

# In dev, the image's prod vendor is missing dev-only bundles (DebugBundle,
# MakerBundle, WebProfilerBundle), and host bind-mounts can leave stale autoload
# classmaps. Always run composer install in dev — it's idempotent.
if [[ "${APP_ENV:-prod}" == "dev" ]]; then
    git config --global --add safe.directory /var/www/html >/dev/null 2>&1 || true
    echo "[spinescout] APP_ENV=dev: ensuring composer dev dependencies..."
    composer install --no-interaction --prefer-dist --no-progress --no-scripts
fi

# Run pending Doctrine migrations on every boot. Safe no-op when up to date.
if [[ "${SPINESCOUT_RUN_MIGRATIONS:-1}" == "1" ]]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || {
        echo "[spinescout] migrations failed" >&2
        exit 1
    }
fi

# In prod, warm the cache. In dev it'll be warmed on first request anyway.
if [[ "${APP_ENV:-prod}" == "prod" ]]; then
    php bin/console cache:warmup --no-interaction || true
fi

exec "$@"
