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

# Copy application files
COPY . /var/www/html/

# Install PHP dependencies
# First try to install from lock file, if that fails (lock file outdated), update and install
RUN composer install --no-dev --optimize-autoloader --no-interaction || \
    (composer update --no-dev --optimize-autoloader --no-interaction && \
     composer install --no-dev --optimize-autoloader --no-interaction)

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

# Expose port (Render will set PORT)
EXPOSE 8080

# Start Apache using the script
CMD ["/usr/local/bin/start-apache.sh"]

