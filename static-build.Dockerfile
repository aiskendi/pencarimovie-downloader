# ==============================================================================
# Stage 1: Build & Optimize Composer Dependencies
# ==============================================================================
FROM composer:2 AS composer-builder
WORKDIR /app

# Copy Composer manifests
COPY composer.json composer.lock ./

# Install production dependencies only, optimizing the autoloader
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# ==============================================================================
# Stage 2: Package Static Binary via FrankenPHP Builder
# ==============================================================================
FROM dunglas/frankenphp:static-builder-gnu AS binary-builder

# Setup the application build directory
WORKDIR /go/src/app/dist/app

# Copy dependencies from Stage 1
COPY --from=composer-builder /app/vendor ./vendor

# Copy application source files and folders
COPY backend.php index.php router.php Caddyfile ./
COPY public/ ./public/

# Ensure storage directory exists with .gitkeep
RUN mkdir -p storage && touch storage/.gitkeep

# Compile the embedded static binary
WORKDIR /go/src/app/
RUN EMBED=dist/app/ ./build-static.sh
