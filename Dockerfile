FROM php:8.2-fpm

# Install system dependencies and Node.js (for building Vite assets)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nginx \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Laravel
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP and Node dependencies, then build frontend assets
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm install \
    && npm run build

# Set permissions for Laravel storage and cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Configure Nginx for Laravel (pointing public document root)
RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/nginx/sites-available/default

# Expose port 80 for Render web traffic
EXPOSE 80

# Start Nginx and PHP-FPM
CMD service nginx start && php-fpm