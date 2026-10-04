#!/usr/bin/env bash
# Build a local test image, tagged to match exactly what docker-compose.yml
# references (ghcr.io/techzeeland/kafeel:latest). This is for local
# development/testing only — production deploys (including via Portainer)
# should use the image .github/workflows/docker-publish.yml already
# published to GHCR, not a local build.
#
# Run this after cloning, and again any time you change the Dockerfile,
# nginx config, supervisor config, or PHP extensions/ini. You do NOT need
# to re-run it for everyday src/*.php, CSS, or JS edits — those are picked
# up live via the bind mount in docker-compose.override.yml.
set -euo pipefail
cd "$(dirname "$0")"
docker build -t ghcr.io/techzeeland/kafeel:latest -f docker/php/Dockerfile .
echo
echo "Built ghcr.io/techzeeland/kafeel:latest locally."
echo "Run 'docker compose up -d' to (re)create the stack with it, or"
echo "'docker compose up -d --force-recreate web' if the stack is already running."
