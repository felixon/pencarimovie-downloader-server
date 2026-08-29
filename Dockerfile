# Build Composer dependencies in the official Composer image.
FROM composer:2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Runtime image.
FROM dunglas/frankenphp:php8.3-bookworm

WORKDIR /app

COPY . /app
COPY --from=composer /app/vendor /app/vendor

RUN mkdir -p /app/storage /app/storage/stream-pool \
    && chmod -R 777 /app/storage

RUN printf '%s\n' \
    'max_execution_time = 0' \
    'max_input_time = 0' \
    'memory_limit = 512M' \
    > /usr/local/etc/php/conf.d/pencarimovie.ini

# Render's Docker runtime does not allow binaries that carry Linux file
# capabilities. FrankenPHP inherits Caddy's capability used for privileged
# ports, so remove it before the image is deployed. We listen on Render's
# unprivileged PORT, so the capability is unnecessary.
RUN setcap -r /usr/local/bin/frankenphp || true

# Render supplies PORT at runtime. The explicit command avoids relying on
# the image's default Caddy configuration/port.
ENV SERVER_NAME=:8080
EXPOSE 8080

CMD ["sh", "-c", "frankenphp php-server --listen 0.0.0.0:${PORT:-8080} --root /app"]
