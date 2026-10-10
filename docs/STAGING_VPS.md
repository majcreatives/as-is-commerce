# Stage 32.23 — Production-shaped staging VPS (operator runbook)

This is the operational sequence for the staging droplet that **mirrors
production**: a persistent queue worker and a Reverb broadcast server on real
Redis, where the shared-host staging cannot run them. It is the deliberate
other branch of the deployment environment — the Hostinger staging stays on
database-backed state and `BROADCAST_CONNECTION=null` (`AGENTS.md` §76–§80,
`docs/DEPLOYMENT.md`).

Nothing in this file makes a production change. `APP_ENV=staging`, Paystack is
**test mode**, and no production database is involved.

## 0. GitHub is in the loop

- All code changes land on `origin/main` first; nothing is built on the server.
- The deployable is the zip attached to a GitHub Release by `build-deploy.yml`.
- The remote swap is `ops/deploy-staging-vps.sh`, versioned like any code.
- **It is UNEXERCISED until the first droplet deploy.** The auto-deploy job is
  gated on secrets so it cannot fire before the droplet exists, but the first
  real swap must be watched, on a throwaway tag, before this VPS is trusted.

## 1. Why this VPS exists

| Capability | Shared hosting | Staging VPS |
| --- | --- | --- |
| Notification-mail queue worker | none (finite cron only) | Supervisor `queue:work`, so `NOTIFICATIONS_QUEUE_MAIL=true` genuinely delivers |
| Real-time auction transport | `BROADCAST_CONNECTION=null`, polling | Reverb + Redis; polling **stays authoritative** (§76–§79) |
| Cache / queue | database | Redis |
| Sessions | database | **database (unchanged on purpose)** |
| Scheduler | hPanel cron | system cron, same UTC alignment (§82, §85) |

A Reverb or Redis outage is **not a commerce outage**: bids, closures,
payments and inventory are decided in MySQL under row locks and dispatched to
the transport only after they commit. The worst a broken transport does is
leave a page refreshing on its poll interval.

## A1 — Prerequisites

1. **A public hostname** for the droplet (mandatory — the shared-host
   `*.hostingersite.com` name is bound to Hostinger hosting and cannot point at
   a VPS). A subdomain of a domain you own, or a freshly registered one.
2. **A DigitalOcean account** (or equivalent). Recommended: Ubuntu **24.04 LTS**
   droplet, **2 vCPU / 4 GB / 80 GB NVMe**, region **LON1** (London — closest
   DO region to Ghana). Single box runs Nginx + PHP-FPM + MariaDB + Redis +
   Reverb + Supervisor comfortably.
3. Add your SSH key; the droplet gets a public IPv4.

## A2 — Provision the stack

As `root`:

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update -y && apt-get upgrade -y

# PHP 8.4 + extensions (phpredis is the Redis client; reverb needs none extra)
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
  php8.4-{cli,fpm,mbstring,dom,gd,zip,bcmath,intl,mysql,xml,curl,redis,common,opcache} \
  nginx mariadb-server redis-server supervisor certbot python3-certbot-nginx \
  fail2ban unzip git

# PHP binary on the PATH for cron and supervisor
ln -s /usr/bin/php8.4 /usr/local/bin/php
```

MariaDB is installed from the distro repository **unless 11.8.x is required** —
the verified matrix is MariaDB 11.8.x (`AGENTS.md` §91–§93). Prefer the
official MariaDB repo for 11.8:

```bash
curl -LsS https://r.mariadb.com/downloads/mariadb_repo_setup | bash -s -- --mariadb-server-version=11.8 --skip-maxscale
apt-get install -y mariadb-server
```

Create the database and a least-privilege user:

```sql
CREATE DATABASE asis_commerce_vps_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'asis_vps_staging'@'127.0.0.1' IDENTIFIED BY 'REPLACE';
GRANT ALL PRIVILEGES ON asis_commerce_vps_staging.* TO 'asis_vps_staging'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Service wiring:

```bash
systemctl enable --now mariadb redis-server supervisor certbot.timer
```

### Nginx

Serve `current/public` and proxy the Reverb socket over TLS on 443
(`REVERB_CLIENT` port 443 in the env template). Minimal site (the real config is
yours; the essentials: root points at the `current` symlink, PHP-FPM socket,
`index.php`):

```nginx
server {
    listen 80;
    server_name staging.example.gh;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name staging.example.gh;

    ssl_certificate     /etc/letsencrypt/live/staging.example.gh/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/staging.example.gh/privkey.pem;

    root /var/www/as-is-commerce-vps/current/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location /app {   # Reverb WebSocket over TLS
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }
}
```

Issue the cert first so Nginx can reload: `certbot --nginx -d staging.example.gh`.

### Supervisor

`/etc/supervisor/conf.d/as-is-commerce-vps.conf`:

```ini
[program:queue-worker]
directory=/var/www/as-is-commerce-vps/current
command=php artisan queue:work --queue=notifications --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
stderr_logfile=/var/log/as-is-queue.err.log

[program:reverb]
directory=/var/www/as-is-commerce-vps/current
command=php artisan reverb:start
autostart=true
autorestart=true
user=www-data
stderr_logfile=/var/log/as-is-reverb.err.log
```

### Cron (one line, as in DEPLOYMENT.md §3)

```
* * * * * cd /var/www/as-is-commerce-vps/current && php artisan schedule:run >> /dev/null 2>&1
```

The sweep commands are bounded, idempotent and overlap-locked
(`App\Support\ScheduleLocks`); a late run closes auctions **late, not wrong**.

### UFW / fail2ban / backups

```bash
ufw allow 22/tcp; ufw allow 80/tcp; ufw allow 443/tcp; ufw enable
# do NOT open 8080 to the public: Reverb is reached through Nginx TLS
systemctl enable --now fail2ban
```

Nightly (cron as root): `mariadb-dump` of the staging DB + a `storage` tarball
to DO Spaces with rotation, and a droplet snapshot. Backups are restorable, and
storage/app is why the swap script copies it forward.

## A3 — First deploy (manual, before auto-deploy is enabled)

1. Create the app root and a deploy user with sudo-for-these-dirs or ownership
   of `/var/www/as-is-commerce-vps`.
2. Download the release zip from the GitHub Release for the current tag (never
   any other source), then run the swap script once by hand against a throwaway
   tag to **exercise `ops/deploy-staging-vps.sh`**:
   ```bash
   cp /tmp/as-is-commerce-stage32.23.zip /tmp/as-is-commerce.<the-tag>.zip  # naming as the script expects
   STAGING_ROOT=/var/www/as-is-commerce-vps bash /tmp/deploy-staging-vps.sh <the-tag>
   ```
   Watch it: integrity check, carry-forward, symlink, swap, caches, migrate.
3. On the fresh release, build the first `.env` **from `.env.staging-vps.example`**
   (the zip ships it): `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL=https://<hostname>`,
   MariaDB credentials above, Paystack **test** keys, SMTP, Redis client,
   `BROADCAST_CONNECTION=reverb` + `REVERB_*` + `REVERB_SERVER_*`. Then:
   ```bash
   php artisan key:generate
   php artisan app:check-environment --strict   # must exit 0
   ```
   The `--strict` reading treats `staging` as public and will refuse a
   debug-on, mail-less or plaintext-cookie site.
4. Seed reference data once (all idempotent) and create the administrator:
   ```bash
   php artisan db:seed --class=RoleSeeder --force
   php artisan db:seed --class=PermissionSeeder --force
   php artisan db:seed --class=SettingsSeeder --force
   php artisan db:seed --class=CreditPackageSeeder --force
   php artisan app:create-admin --role=super_admin
   ```
5. Reload Supervisor so the worker and Reverb pick up the real `.env`:
   ```bash
   supervisorctl reread && supervisorctl update && supervisorctl restart all
   ```

## A4 — Enable auto-deploy (GitHub Actions)

The `deploy-staging-vps` job in `.github/workflows/build-deploy.yml` runs only
when the repository **variable** `STAGING_VPS_HOST` is set. Set repository
variables (hostname/user are not sensitive) and one secret:

```text
# Repository variables
STAGING_VPS_HOST      the droplet hostname (or IP for the first run)
STAGING_VPS_USER      the deploy user

# Repository secret
STAGING_VPS_SSH_KEY   the deploy user's private key (never shared)
```

From then on, every pushed `stage*` tag downloads its own release asset, copies
it and `ops/deploy-staging-vps.sh` to the droplet, and runs the swap. `.env`
and `storage/app` are carried forward by the script, so the manual steps in A3
do not repeat.

## A5 — Verification (must all pass)

```bash
curl -fsS https://<hostname>/health      # {"status":"ok"}
curl -fsS https://<hostname>/up           # framework boot health
```

- `GET /` renders the marketplace; products/auctions/blog pages 200.
- Admin login works; exception centre and scheduler status are reachable.
- **Redis is actually used**: `redis-cli ping` → `PONG`, and a page view writes
  a cache key; `queue:monitor` or the worker log shows jobs.
- **Queue worker delivers mail**: trigger a real notification and confirm the
  message is sent (not merely queued). `storage/logs/laravel.log` stays clean.
- **Scheduler stamps** `sweeps:*:last_run` update after `schedule:run`.
- **Broadcast is enhancement, not authority**: the auction room updates, and a
  `supervisorctl stop reverb` run still lets the room poll, bid, close and
  settle — polling is authoritative (§76–§79).
- TLS/cookies: `http` → 301 https; `SESSION_SECURE_COOKIE=true` observed in
  the session cookie flags (subset of `VERIFY_26_SECURITY_CONFIG_AUDIT.md` links).
- Paystack **test mode**: one credit purchase verified end-to-end; the webhook
  lands and is HMAC-verified; `/checkout/callback` redirects match.
- `app:check-environment --strict` exits 0 on the live `.env`.

## A6 — Rollback

The Hostinger staging host stays running untouched throughout the move — it is
on a different hostname, so there is no DNS fight and the old URL remains a
complete recovery surface. On the VPS, each release directory is immutable and
`current.old` holds the previous release briefly during a swap. Roll a bad tag
back by pointing `current` at the previous release and re-running caches, or by
keeping the Hostinger URL canonical until parity has held for N days.

When parity holds, retiring the Hostinger staging host is a **separate
decision**, not a step of this stage.

## Nothing else is done here

- No production migration, no production `.env`, no live Paystack keys.
- No Echo client work yet (Stage 32.24 at the move): the live polling room is
  unchanged until the broadcast server exists to be tested against.
- `APP_ENV` stays `staging`; `SESSION_DRIVER` stays `database`.