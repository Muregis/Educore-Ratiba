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

# Install FET CLI from Debian; fail the build if the binary is missing
RUN apt-get update \
    && apt-get install -y fet \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p engine output \
    && FET_BIN="$(command -v fet-cl || true)" \
    && if [ -z "$FET_BIN" ] || [ ! -x "$FET_BIN" ]; then \
         echo "ERROR: fet-cl not found after apt install fet" >&2; \
         dpkg -L fet || true; \
         exit 1; \
       fi \
    && cp -f "$FET_BIN" engine/fet-cl \
    && chmod 755 engine/fet-cl \
    && ln -sf /usr/bin/fet-cl engine/fet-cl-system \
    && ./engine/fet-cl --version \
    && echo "FET engine installed OK: $(./engine/fet-cl --version 2>&1 | head -1)"

# Set permissions
RUN chown -R www-data:www-data /var/www/html/output \
    && chown -R www-data:www-data /var/www/html/engine \
    && chmod -R 755 /var/www/html/output \
    && chmod 755 /var/www/html/engine/fet-cl

# Apache config: serve from /var/www/html, allow .htaccess
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/app.conf \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && a2enconf app

# Raise PHP limits so FET can run up to ~10 minutes
RUN printf 'max_execution_time = 600\nmax_input_time = 600\nmemory_limit = 512M\n' \
    > /usr/local/etc/php/conf.d/fet-limits.ini

EXPOSE 80

RUN cat > /entrypoint.sh << 'EOS'
#!/bin/bash
set -e
# Ensure engine binary is present even if volume mounts overwrite engine/
if [ ! -x /var/www/html/engine/fet-cl ] && [ -x /usr/bin/fet-cl ]; then
  mkdir -p /var/www/html/engine
  cp -f /usr/bin/fet-cl /var/www/html/engine/fet-cl
  chmod 755 /var/www/html/engine/fet-cl
fi
echo "Starting Apache..."
exec apache2-foreground
EOS
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
