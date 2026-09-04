FROM debian:bookworm-slim

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates tar \
    && rm -rf /var/lib/apt/lists/*

# Use the official PencariMovie Server v1.1.0 Linux x86_64 release package.
ARG PENCARIMOVIE_VERSION=v1.1.0
RUN curl -fL \
    "https://github.com/aiskendi/pencarimovie-server/releases/download/${PENCARIMOVIE_VERSION}/pencarimovie-downloader-linux-x86_64.tar.gz" \
    -o /tmp/pencarimovie.tar.gz \
    && tar -xzf /tmp/pencarimovie.tar.gz -C /app \
    && rm /tmp/pencarimovie.tar.gz \
    && chmod +x /app/bin/frankenphp

# Keep the repository's server-side application changes while using the
# official v1.1.0 runtime/package as the base.
COPY backend.php /app/backend.php
COPY index.php /app/index.php
COPY router.php /app/router.php
COPY render-bootstrap.php /app/render-bootstrap.php
COPY public /app/public

# Long Telegram-backed streams must not be terminated by PHP's default
# execution timeout.
RUN if grep -qE '^[;[:space:]]*max_execution_time[[:space:]]*=' /app/bin/php.ini; then \
        sed -Ei 's/^[;[:space:]]*max_execution_time[[:space:]]*=.*/max_execution_time = 0/' /app/bin/php.ini; \
    else \
        printf '\nmax_execution_time = 0\n' >> /app/bin/php.ini; \
    fi

# Always load the Render compatibility shim before any PHP entrypoint runs.
# This is more reliable than changing router.php/index.php because FrankenPHP
# may dispatch extensionless API URLs without going through either file first.
RUN printf '\nauto_prepend_file = /app/render-bootstrap.php\n' >> /app/bin/php.ini

# Render provides the real bot token through PENCARIMOVIE_BOT_TOKEN.
# The browser may still submit a token, but the hosted server-side token is
# authoritative. The secret is never written into the image or frontend.
RUN sed -i "/\\$botToken = trim((string) (\\$input\\['bot_token'\\] ?? ''));/a\\        \\$configuredBotToken = trim((string) (\\$_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? \\$_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? ''));\\n        if (\\$configuredBotToken !== '') {\\n            \\$botToken = \\$configuredBotToken;\\n        }" /app/backend.php

ENV PENCARIMOVIE_RENDER_MODE=1
ENV PENCARIMOVIE_PUBLIC_HOST=pencarimovie-downloader.onrender.com
ENV PENCARIMOVIE_STORAGE_DIR=/app/storage

EXPOSE 10000

CMD ["/bin/sh", "-c", "exec /app/bin/frankenphp php-server --listen 0.0.0.0:${PORT:-10000} --root /app"]
