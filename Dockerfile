FROM php:8.2-fpm

# Install system dependencies, Nginx, SQLite dev libraries, and Node.js
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libsqlite3-dev \
    zip \
    unzip \
    nginx \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Laravel
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd pdo_sqlite

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Install dependencies and build frontend assets
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm install \
    && npm run build

# Set proper permissions for Laravel storage, cache, and database directory
RUN mkdir -p database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache database \
    && chmod 664 database/database.sqlite

# Write an explicit Nginx configuration for Laravel
RUN echo 'server {\n\
    listen 80;\n\
    listen [::]:80;\n\
    root /var/www/html/public;\n\
    index index.php index.html index.htm;\n\
    server_name _;\n\
    \n\
    location / {\n\
        try_files $uri $uri/ /index.php?$query_string;\n\
    }\n\
    \n\
    location ~ \\.php$ {\n\
        include fastcgi_params;\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_index index.php;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
    }\n\
    \n\
    location ~ /\\.ht {\n\
        deny all;\n\
    }\n\
}' > /etc/nginx/sites-available/default

# Ensure PHP-FPM listens on TCP port 9000
RUN sed -i 's#listen = /run/php/php8.2-fpm.sock#listen = 127.0.0.1:9000#g' /usr/local/etc/php-fpm.d/www.conf

EXPOSE 80

# Startup script: Run migrations/seeders then start services
CMD php artisan migrate --force --seed && php-fpm -D && nginx -g "daemon off;"