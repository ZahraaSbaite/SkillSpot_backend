FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli mbstring gd xml curl zip bcmath

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json ./

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-dev --no-scripts --no-interaction

# Copy application files
COPY . .

# Expose port (Render will set this via $PORT)
EXPOSE 8080

# Start PHP built-in server
# The server will listen on the port specified by Render's $PORT environment variable
CMD php -S 0.0.0.0:$PORT -t public