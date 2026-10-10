#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# As-Is-Commerce -- production-shaped staging VPS swap
#
# Run by the GitHub Actions `deploy-staging-vps` job after every pushed
# `stage*` tag, never by hand and never from the developer machine. The
# deployable is always the zip GitHub built; this script only unpacks it into
# a fresh release directory, carries the runtime state forward, and swaps.
#
# Layout (docs/STAGING_VPS.md):
#   /var/www/as-is-commerce-vps/
#     releases/as-is-commerce.<tag>/   one per release, immutable once live
#     current -> the release Nginx serves
#
# STATE CARRIED FORWARD (the release zip contains no user data):
#   .env            boot configuration; created once during first provision
#   storage/app/    uploaded images and private uploads
#
# STATUS: UNEXERCISED until the first droplet deploy at the move. This is
# written by construction from the Hostinger staging swap pattern and must be
# exercised exactly once on a test release before the VPS is treated as live.
# ---------------------------------------------------------------------------
set -euo pipefail

tag="${1:?usage: deploy-staging-vps.sh <stage-tag>}"

root="${STAGING_ROOT:-/var/www/as-is-commerce-vps}"
releases="${root}/releases"
staging="${releases}/as-is-commerce.${tag}"
current="${root}/current"

artifact="/tmp/as-is-commerce-${tag}.zip"

if [ ! -f "${artifact}" ]; then
    echo "missing ${artifact}" >&2
    exit 1
fi

# Integrity before we touch anything live. The zip is only ever replaced by
# re-running this script with the same tag, never edited in place.
unzip -tq "${artifact}"

mkdir -p "${releases}"
rm -rf "${staging}"
unzip -q "${artifact}" -d "${staging}"

# Carry runtime state from the previous live release, if one exists.
if [ -L "${current}" ] && [ -d "${current}" ]; then
    cp -a "${current}/.env" "${staging}/.env"
    mkdir -p "${staging}/storage/app"
    cp -a "${current}/storage/app/." "${staging}/storage/app/" 2>/dev/null || true
fi

# Absolute storage link INTO this release, so images keep resolving after the
# swap. A relative link would point at the old directory name and 404 at the
# first request after the swap.
rm -f "${staging}/public/storage"
ln -s "${staging}/storage/app/public" "${staging}/public/storage"

# Swap. First deploy has no `current`; the same command sequence handles both.
ln -sfn "${staging}" "${root}/current.next"
if [ -L "${current}" ]; then
    mv -T "${current}" "${root}/current.old"
fi
mv -T "${root}/current.next" "${current}"
rm -rf "${root}/current.old"

cd "${staging}"

chmod -R u+rwX storage bootstrap/cache

# §A6 -- clear then cache. A stale config cache makes the app ignore `.env`.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

# Boot sanity: these fail loudly if the new release cannot run.
php artisan config:show app >/dev/null

echo "deployed ${tag} -> ${current}"