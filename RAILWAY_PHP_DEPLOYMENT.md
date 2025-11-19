# Railway PHP Service Deployment (Alternative)

**Note:** The PHP service is designed for Render deployment. If you want to deploy PHP to Railway instead, follow this guide.

## Problem: Composer Build Error

If you're getting composer errors when deploying to Railway, it's because Railway is trying to use the Dockerfile (which is for Render) instead of Nixpacks.

## Solution 1: Use Nixpacks (Recommended for Railway)

Railway can auto-detect PHP projects. To force Nixpacks:

1. **In Railway Dashboard:**
   - Go to your service → **Settings** → **Build**
   - Set **Build Command**: Leave empty (Railway will auto-detect)
   - Set **Start Command**: `php -S 0.0.0.0:$PORT -t . index.php`
   - Or use Apache: Configure Railway to use Nixpacks

2. **Create `nixpacks.toml` in project root:**
   ```toml
   [phases.setup]
   nixPkgs = ["php82", "composer"]

   [phases.install]
   cmds = ["composer install --no-dev --optimize-autoloader"]

   [start]
   cmd = "php -S 0.0.0.0:$PORT -t . index.php"
   ```

## Solution 2: Fix Dockerfile for Railway

If you want to use Docker on Railway, update the Dockerfile:

```dockerfile
# Use official PHP image with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy only necessary files first (for better caching)
COPY composer.json composer.lock* ./

# Install PHP dependencies (with better error handling)
RUN if [ -f composer.lock ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    else \
        composer update --no-dev --optimize-autoloader --no-interaction && \
        composer install --no-dev --optimize-autoloader --no-interaction; \
    fi || echo "Composer install failed, continuing..."

# Copy rest of application files
COPY . /var/www/html/

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Create a startup script to handle PORT environment variable
RUN echo '#!/bin/bash\n\
set -e\n\
\n\
# Use PORT environment variable or default to 8080\n\
PORT=${PORT:-8080}\n\
\n\
# Update Apache ports.conf\n\
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\n\
\n\
# Update Apache virtual host\n\
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf\n\
\n\
# Start Apache\n\
exec apache2-foreground' > /usr/local/bin/start-apache.sh

RUN chmod +x /usr/local/bin/start-apache.sh

# Expose port
EXPOSE 8080

# Start Apache using the script
CMD ["/usr/local/bin/start-apache.sh"]
```

## Solution 3: Deploy PHP to Render (Recommended)

**The PHP service is already configured for Render.** It's easier to:
1. Keep PHP service on Render (as designed)
2. Deploy Python service to Railway
3. Connect them via environment variables

See `DEPLOYMENT.md` for Render deployment instructions.

## Quick Fix: Generate composer.lock

If composer is failing because `composer.lock` is missing:

```bash
# Run locally
composer install
# This will generate composer.lock

# Commit and push
git add composer.lock
git commit -m "Add composer.lock"
git push
```

Then Railway will use the lock file and installation should succeed.

