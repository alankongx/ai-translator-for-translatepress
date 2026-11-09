#!/usr/bin/env bash
# Run WP-CLI inside the e2e environment: dev/e2e/wp.sh plugin list
cd "$(dirname "$0")" && exec docker compose --profile cli run --rm -T cli wp "$@"
