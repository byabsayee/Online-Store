#!/usr/bin/env bash
# Populates ./vendor on the host with PHPMailer + mPDF, for local dev only.
# Production/CI never needs this — the Dockerfile runs composer install
# itself and bakes vendor/ into the published image. This script exists
# purely so docker-compose.override.yml's live-reload bind mount (which
# shadows the image's /var/www/html with your local ./src) has a matching
# ./vendor to mount back on top.
#
# Run this once after cloning, and again any time you change composer.json.
set -euo pipefail
cd "$(dirname "$0")"
docker run --rm -v "$PWD":/app -w /app composer:2 install --no-dev --no-interaction --no-progress --optimize-autoloader
echo
echo "vendor/ is ready. (Re)start the stack with: docker compose up -d"
