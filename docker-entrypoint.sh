#!/bin/bash
set -e

# Run migrations
php artisan migrate --force 2>/dev/null || true

# Cache config & routes
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true

# Start Laravel dev server
exec php artisan serve --host=0.0.0.0 --port=8080
