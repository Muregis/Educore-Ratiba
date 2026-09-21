FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    git \
    libpq-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_pgsql pdo_mysql mbstring

# Enable Apache mod_rewrite
RUN a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy app files first (engine/ is excluded via .dockerignore)
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Download the Linux FET engine binary AFTER COPY (so Windows .exe is never present)
RUN mkdir -p engine output \
    && curl -L -o /tmp/fet.tar.gz "https://www.lalescu.ro/liviu/fet/download/fet6.13.1/fet_6.13.1_linux.tar.gz" \
    && tar -xzf /tmp/fet.tar.gz -C /tmp/fet_extracted/ --strip-components=1 2>/dev/null || \
       (mkdir -p /tmp/fet_extracted && tar -xzf /tmp/fet.tar.gz -C /tmp/fet_extracted/) \
    && find /tmp/fet_extracted -name "fet-cl" -exec cp {} engine/fet-cl \; \
    && chmod +x engine/fet-cl \
    && rm -rf /tmp/fet.tar.gz /tmp/fet_extracted

# Set permissions
RUN chown -R www-data:www-data /var/www/html/output \
    && chown -R www-data:www-data /var/www/html/engine \
    && chmod -R 755 /var/www/html/output

# Apache config: serve from /var/www/html, allow .htaccess
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

EXPOSE 80

CMD ["apache2-foreground"]
