FROM php:8.1-cli

RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    git \
    libpq-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_pgsql pdo_mysql mbstring

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

RUN mkdir -p engine output \
    && curl -L -o /tmp/fet.tar.gz "https://www.lalescu.ro/liviu/fet/download/fet6.13.1/fet_6.13.1_linux.tar.gz" \
    && tar -xzf /tmp/fet.tar.gz -C engine/ \
    && chmod +x engine/fet-cl \
    && rm /tmp/fet.tar.gz

COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "."]
