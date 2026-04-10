FROM php:8.3-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libsqlite3-dev libxml2-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite sockets curl mbstring dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . /app

RUN composer install --no-interaction --prefer-dist

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
