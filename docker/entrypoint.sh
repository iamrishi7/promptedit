#!/bin/sh
# One image, three roles (set CONTAINER_ROLE): web (default), worker, scheduler.
set -e

cd /app

# Configuration comes from the environment at runtime, so caches are built here, not in the image.
php artisan optimize --quiet

case "${CONTAINER_ROLE:-web}" in
  web)
    if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
      # --isolated: only one container migrates when several start at once (cache lock in Dragonfly).
      php artisan migrate --force --isolated
    fi
    exec frankenphp run --config /etc/frankenphp/Caddyfile
    ;;
  worker)
    # default first: submissions and webhook events before image copies.
    exec php artisan queue:work --queue=default,images --sleep=1 --tries=3 --timeout=80 --max-time=3600 --memory=256
    ;;
  scheduler)
    # Runs the Pixelforge poller every minute (missed webhooks, lost jobs, image copies).
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
