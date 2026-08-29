# Build Composer dependencies in the official Composer image.
FROM composer:2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Runtime image.
FROM dunglas/frankenphp:php8.3-bookworm

WORKDIR /app

# Copy the complete PHP server first.
COPY . /app

# Copy the vendor directory produced by Composer.
COPY --from=composer /app/vendor /app/vendor

# Runtime storage directories.
RUN mkdir -p /app/storage /app/storage/stream-pool \
    && chmod -R 777 /app/storage

# Long-running Telegram video streams must not be terminated by PHP's execution timeout.
RUN printf '%s\n' \
    'max_execution_time = 0' \
    'max_input_time = 0' \
    'memory_limit = 512M' \
    > /usr/local/etc/php/conf.d/pencarimovie.ini

ENV SERVER_NAME=:8080

EXPOSE 8080

CMD ["sh", "-c", "frankenphp php-server --listen 0.0.0.0:${PORT:-8080} --root /app"]
