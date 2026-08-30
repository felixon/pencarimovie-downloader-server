FROM debian:bookworm-slim

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates tar \
    && rm -rf /var/lib/apt/lists/*

# Use the same packaged PencariMovie runtime as the known-working deployment.
RUN curl -fL \
    "https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.1/pencarimovie-downloader-linux-x86_64.tar.gz" \
    -o /tmp/pencarimovie.tar.gz \
    && tar -xzf /tmp/pencarimovie.tar.gz -C /app \
    && rm /tmp/pencarimovie.tar.gz \
    && chmod +x /app/bin/frankenphp

# Long Telegram-backed streams must not be terminated by PHP's default
# execution timeout.
RUN if grep -qE '^[;[:space:]]*max_execution_time[[:space:]]*=' /app/bin/php.ini; then \
        sed -Ei 's/^[;[:space:]]*max_execution_time[[:space:]]*=.*/max_execution_time = 0/' /app/bin/php.ini; \
    else \
        printf '\nmax_execution_time = 0\n' >> /app/bin/php.ini; \
    fi

# Render provides the real bot token through PENCARIMOVIE_BOT_TOKEN.
# The browser may still submit a token, but the hosted server-side token is
# authoritative. The secret is never written into the image or frontend.
RUN sed -i "/\$botToken = trim((string) (\$input\['bot_token'\] ?? ''));/a\        \$configuredBotToken = trim((string) (\$_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? \$_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? ''));\n        if (\$configuredBotToken !== '') {\n            \$botToken = \$configuredBotToken;\n        }" /app/backend.php

# Apply the stream-session/concurrency patch after the packaged backend is extracted.
# The packaged runtime exposes PHP through FrankenPHP; invoke the script with
# FrankenPHP's embedded PHP interpreter rather than assuming /app/bin/php exists.
# COPY patch-runtime.php /tmp/patch-runtime.php
# RUN /app/bin/frankenphp php-cli /tmp/patch-runtime.php \
    # && rm /tmp/patch-runtime.php

ENV PENCARIMOVIE_STORAGE_DIR=/app/storage

EXPOSE 10000

CMD ["/bin/sh", "-c", "exec /app/bin/frankenphp php-server --listen 0.0.0.0:${PORT:-10000} --root /app"]
