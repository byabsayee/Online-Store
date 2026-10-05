#!/bin/sh
# Runs as root on every container start, then hands over to supervisord.
# The uploads folder is a Docker volume (or a bind-mounted host folder) and is often owned by root,
# which made every photo / logo / font upload fail with "uploads folder is not writable".
set -e
UP=/var/www/html/uploads
mkdir -p "$UP/products" "$UP/branding" "$UP/fonts"
chown -R www-data:www-data "$UP" 2>/dev/null || true
chmod -R u+rwX,g+rX "$UP" 2>/dev/null || true
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
