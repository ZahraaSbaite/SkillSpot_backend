FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy all files (including vendor folder)
COPY . /var/www/html/

# Update Apache configuration to point to /public
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/apache2.conf

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Create necessary directories with proper permissions
RUN mkdir -p /var/www/html/uploads /var/www/html/certificates && \
    chown -R www-data:www-data /var/www/html/uploads /var/www/html/certificates && \
    chmod -R 775 /var/www/html/uploads /var/www/html/certificates

EXPOSE 80

CMD ["apache2-foreground"]