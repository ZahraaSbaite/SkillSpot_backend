FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli mbstring gd xml zip bcmath

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock* ./

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-dev --no-scripts --no-interaction || true

# Copy application files
COPY . .

# Run composer install again in case composer.lock wasn't available initially
RUN composer install --optimize-autoloader --no-dev --no-interaction

# Create necessary directories and set permissions
RUN mkdir -p /app/public && chmod -R 755 /app

# Expose port
EXPOSE 8080

# Start PHP built-in server
CMD php -S 0.0.0.0:$PORT -t public