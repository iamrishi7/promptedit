#!/bin/sh
# Web containers answer Laravel's /up; worker and scheduler have no HTTP port.
case "${CONTAINER_ROLE:-web}" in
  worker|scheduler) exit 0 ;;
esac
php -r 'exit(@file_get_contents("http://127.0.0.1:" . (getenv("PORT") ?: 8000) . "/up") === false ? 1 : 0);'
