FROM php:8.3-fpm as base

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . .

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-interaction

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 9000
EXPOSE 9000

# Development stage
FROM base as development

# Install Xdebug for development
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Install additional development tools
RUN apt-get update && apt-get install -y \
    vim \
    nano \
    htop \
    && rm -rf /var/lib/apt/lists/*

CMD ["php-fpm"]

# Production stage
FROM base as production

# Remove development dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Install additional production extensions
RUN docker-php-ext-install opcache

# Create log directory
RUN mkdir -p /var/log && chown www-data:www-data /var/log

# Set production permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/var

CMD ["php-fpm"]