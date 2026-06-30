# Spine Scout

<img src="assets/images/spine-scout-logo.webp" alt="Spine Scout" width="200">

> [!WARNING]
> **Early access - active development.** Spine Scout is a brand-new project under active maintenance. Expect breaking changes, rough edges, and incomplete features. Pin to a specific image tag if you deploy it, and back up your database before upgrading.

Spine Scout is a self-hosted **discovery and request hub for books** - think *Seerr, but for books*. It is the glue between where you find books, how you get them, and where they end up.

Spine Scout itself does **not** host your library. It orchestrates the surrounding tools: it offers the search UI, the request/approval workflow, duplicate checks against your library.

It is designed to plug into:

- **[Komga](https://komga.org/)**-compatible library / reader that owns the files - **[Grimmory](https://github.com/grimmory-tools/grimmory)** is the currently confirmed-working client
- **[Hardcover](https://hardcover.app/)**, **[Open Library](https://openlibrary.org/)** - metadata providers

## 📸 Screenshots

<p align="center">
  <img src="README_images/homescreen_sections.webp" alt="Home screen sections" width="800">
  <br><em>Home screen with curated sections</em>
</p>

<p align="center">
  <img src="README_images/browse_books.webp" alt="Browse books" width="800">
  <br><em>Browse books</em>
</p>

<p align="center">
  <img src="README_images/book_popup.webp" alt="Book detail popup" width="800">
  <br><em>Book detail popup</em>
</p>

<p align="center">
  <img src="README_images/author_popup.webp" alt="Author detail popup" width="800">
  <br><em>Author detail popup</em>
</p>

## ✨ Features

- **Unified search** - query metadata providers (Hardcover, Open Library) for rich book discovery
- **Request workflow** - family and trusted users browse and request books; admins approve and route them to a downloader
- **Library awareness** - syncs against your Komga-compatible library so users see what's already owned and avoid duplicate requests
- **Multi-user** - built-in accounts with admin/user roles; per-user request history
- **Pluggable integrations** - each external system lives behind a clean client boundary so you can mix and match (or extend) the tools you already self-host
- **Background sync** - Symfony Messenger + Scheduler keep library state and metadata fresh on a configurable cadence

## 🚀 Quick Start

### Prerequisites

- Docker & Docker Compose
- A **PostgreSQL** database and (recommended) a **FlareSolverr** instance. You don't
  need to install these yourself - the Compose file below runs them alongside Spine Scout.

### Install (run the published images)

Spine Scout publishes two images to GHCR:

- **`ghcr.io/krught/spine-scout-app`** - the PHP application. It runs as both the
  `app` (web requests) and the `worker` (background downloads / scheduler)
  services - same image, different command.
- **`ghcr.io/krught/spine-scout-web`** - nginx, the HTTP entry point.

You bring a Postgres database and a FlareSolverr container; the Compose file below
wires everything up. No source checkout required.

1. Create a directory for your deployment and add a **`.env`** file with your
   parameters (every value Spine Scout reads is set here):

   ```dotenv
   # .env
   APP_ENV=prod
   APP_SECRET=change-me-to-a-long-random-string   # CHANGE ME

   # Host port for the web UI
   SPINESCOUT_HTTP_PORT=9092

   # Host paths for persistent data
   SPINESCOUT_COVER_CACHE_DIR=./book-covers
   SPINESCOUT_DOWNLOAD_DIR=./library
   SPINESCOUT_DATABASE_DATA_DIR=./application-data

   # PostgreSQL credentials
   POSTGRES_VERSION=16
   POSTGRES_DB=spinescout                          # CHANGE ME
   POSTGRES_USER=spinescout                        # CHANGE ME
   POSTGRES_PASSWORD=change-me                     # CHANGE ME

   # Max concurrent php-fpm workers
   PHP_FPM_MAX_CHILDREN=15
   ```

2. Add a **`docker-compose.yaml`** next to it. It pulls the two Spine Scout images
   plus the Postgres and FlareSolverr services it needs, and reads your `.env`:

   ```yaml
   services:
     # PHP application (web requests).
     app:
       image: ghcr.io/krught/spine-scout-app:latest   # pin to e.g. :0.0.6 in production
       environment: &app-env
         APP_ENV: ${APP_ENV:-prod}
         APP_SECRET: ${APP_SECRET}
         DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=${POSTGRES_VERSION}&charset=utf8
         MESSENGER_TRANSPORT_DSN: doctrine://default?auto_setup=0
         PHP_FPM_MAX_CHILDREN: ${PHP_FPM_MAX_CHILDREN:-15}
       restart: unless-stopped
       volumes:
         - ${SPINESCOUT_COVER_CACHE_DIR:-./book-covers}:/var/www/html/book-covers:rw
         - ${SPINESCOUT_DOWNLOAD_DIR:-./library}:/var/www/html/library:rw
         # - ${SPINESCOUT_TORRENT_DIR:-./downloads}:/downloads:rw # completed torrents location
       depends_on:
         database:
           condition: service_healthy

     # nginx front end - the only service that exposes a port.
     web:
       image: ghcr.io/krught/spine-scout-web:latest
       ports:
         - "${SPINESCOUT_HTTP_PORT:-9092}:80"
       restart: unless-stopped
       depends_on:
         - app

     # Background worker (downloads + scheduler). Same image as `app`, run as the
     # unprivileged user with the consume command. Scale it with the download
     # workload: `docker compose up -d --scale worker=3`.
     worker:
       image: ghcr.io/krught/spine-scout-app:latest
       user: www-data
       command: [php, bin/console, messenger:consume, async, scheduler_default, --time-limit=3600, --memory-limit=192M, -vv]
       environment:
         <<: *app-env
         SPINESCOUT_RUN_MIGRATIONS: "0"   # the app service owns migrations
       restart: unless-stopped
       volumes:
         - ${SPINESCOUT_COVER_CACHE_DIR:-./book-covers}:/var/www/html/book-covers:rw
         - ${SPINESCOUT_DOWNLOAD_DIR:-./library}:/var/www/html/library:rw
        # - ${SPINESCOUT_TORRENT_DIR:-./downloads}:/downloads:rw # completed torrents location  
       depends_on:
         database:
           condition: service_healthy
         app:
           condition: service_started

     # Required: PostgreSQL database.
     database:
       image: postgres:${POSTGRES_VERSION:-16}-alpine
       environment:
         POSTGRES_DB: ${POSTGRES_DB}
         POSTGRES_USER: ${POSTGRES_USER}
         POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
       healthcheck:
         test: ["CMD", "pg_isready", "-d", "${POSTGRES_DB}", "-U", "${POSTGRES_USER}"]
         timeout: 5s
         retries: 5
         start_period: 60s
       restart: unless-stopped
       volumes:
         - ${SPINESCOUT_DATABASE_DATA_DIR:-./application-data}:/var/lib/postgresql/data:rw

     # Recommended: Cloudflare-challenge solver used by some download sources.
     # Spine Scout reaches it at http://flaresolverr:8191 automatically.
     flaresolverr:
       image: ghcr.io/flaresolverr/flaresolverr:latest
       environment:
         LOG_LEVEL: info
         CAPTCHA_SOLVER: none
         TZ: UTC
       restart: unless-stopped
   ```

3. Pull and start the stack:

   ```bash
   docker compose pull
   docker compose up -d
   ```

4. Open `http://localhost:9092` and complete the setup wizard to create your admin
   account and configure your first integration.

> **Scaling downloads:** the `worker` is its own service, so run more of them when
> your download/search volume grows - `docker compose up -d --scale worker=3`.

> **Upgrading:** bump the image tags (or re-pull `:latest`), then
> `docker compose pull && docker compose up -d`. Database migrations run
> automatically on start (the `app` service runs them) - **back up your database
> first.**

### Install from source (development)

Prefer to build the images yourself? Clone the repo and use the bundled Compose
files, which build from `docker/php/Dockerfile` and `docker/nginx/Dockerfile`:

```bash
git clone https://github.com/Krught/Spine-Scout.git
cd Spine-Scout
cp .env.template .env      # edit secrets
docker compose up -d --build
```

### Environment Variables

| Variable | Description | Default |
|--|--|--|
| `APP_ENV` | Symfony environment (`prod` or `dev`) | `prod` |
| `APP_SECRET` | Symfony app secret - **change this** | (placeholder) |
| `SPINESCOUT_HTTP_PORT` | Host port for the web UI | `9092` |
| `SPINESCOUT_COVER_CACHE_DIR` | Host path for the on-disk cover cache | `./book-covers` |
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` | Database credentials - **change these** | `app` / `app` / `ChangeMe!` |
| `DATABASE_URL` | Full Postgres DSN (built from the values above) | derived |
| `MESSENGER_TRANSPORT_DSN` | Symfony Messenger transport | `doctrine://default?auto_setup=0` |

## ⚙️ Configuration

Most configuration happens in the **Settings** area of the web UI after first launch. From there you can:

- Connect your **Komga**-compatible library via its REST API
- Add **metadata provider** credentials (Hardcover API key, etc.)
- Configure **torrent** fulfillment — your **indexers** and a **download client** under Settings → Torrents
- Manage users, roles, and per-user request preferences
- Adjust per-integration sync cadence and trigger manual "Sync now" runs

### Torrent downloads: mapping the download path

Books and audiobooks can be fulfilled over BitTorrent. Audiobooks always use it; for books, add **Torrent** to *Settings → Direct downloads → Source priority* (drag it above the HTTP sources to prefer it, or below to use it as a fallback). Spine Scout searches your indexers, sends the best match to your download client, then **moves the finished files into your library** — audiobooks to your audiobook folder, books to your ebook library folder. To do the move it reads the client's completed downloads from disk; it does not pull files over the client's API.

The convention is a single fixed mount: **bind-mount your download client's completed-downloads folder into the Spine Scout `app` and `worker` containers at `/downloads`.** Spine Scout then resolves a finished torrent at `/downloads/<torrent name>`, so it works no matter what absolute path the client uses on its own host (e.g. `/mnt/videos/torr/...`) — only the basename matters. No path-mapping fields are needed in the UI; the `/downloads` mount is the whole contract.

**Production** — add the bind to both the `app` and `worker` services of your production `docker-compose.yaml` (alongside the existing library/cover mounts):

```yaml
services:
  app:
    volumes:
      - ${SPINESCOUT_DOWNLOAD_DIR:-./library}:/var/www/html/library:rw
      - ${SPINESCOUT_TORRENT_DIR:-./downloads}:/downloads:rw   # completed torrents
  worker:
    volumes:
      - ${SPINESCOUT_DOWNLOAD_DIR:-./library}:/var/www/html/library:rw
      - ${SPINESCOUT_TORRENT_DIR:-./downloads}:/downloads:rw   # completed torrents
```

Set `SPINESCOUT_TORRENT_DIR` in your `.env` to wherever completed torrents physically land **on the Spine Scout host**. 

## 🛠️ Tech Stack

- **Symfony 8** (PHP 8.4) - framework, HTTP client, Messenger, Scheduler, Security
- **PostgreSQL 16** + **Doctrine ORM / Migrations**
- **Twig** + **Symfony UX** (Stimulus / Turbo) + **AssetMapper / importmap**
- **Docker Compose** - nginx + php-fpm + worker + database

More detail in [`project_documentation/`](project_documentation/).

## 🧑‍💻 Development

```bash
# Bring up the dev stack
docker compose up -d

# Run migrations
docker compose exec app php bin/console doctrine:migrations:migrate

# Run tests
docker compose exec app vendor/bin/phpunit

# Tail logs
docker compose logs -f app worker
```

## 📄 License

See [LICENSE](LICENSE).

## ⚠️ Disclaimer

Spine Scout is a discovery and orchestration interface that displays results from external metadata providers. **It does not host, store, or distribute any content of its own.** The developers are not responsible for how this tool is used or for what is accessed through it.

Users are solely responsible for:

- Ensuring they have the legal right to access, download, or store any material discovered or requested through Spine Scout
- Complying with copyright laws and intellectual property rights in their jurisdiction
- Understanding and accepting the terms of any external services, sources, or integrations they configure

Use of this tool is entirely at your own risk.
