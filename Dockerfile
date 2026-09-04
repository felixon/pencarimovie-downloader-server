FROM debian:bookworm-slim

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates tar \
    && rm -rf /var/lib/apt/lists/*

# Use the developer's official PencariMovie Server v1.1.0 Linux x86_64
# release package as the application itself. The uploaded Windows package
# confirms the same v1.1.0 application structure; Render needs the Linux build.
ARG PENCARIMOVIE_VERSION=v1.1.0
RUN curl -fL \
    "https://github.com/aiskendi/pencarimovie-server/releases/download/${PENCARIMOVIE_VERSION}/pencarimovie-downloader-linux-x86_64.tar.gz" \
    -o /tmp/pencarimovie.tar.gz \
    && tar -xzf /tmp/pencarimovie.tar.gz -C /app \
    && rm /tmp/pencarimovie.tar.gz \
    && chmod +x /app/bin/frankenphp

# Render compatibility is loaded before the official package's PHP entrypoint.
COPY render-bootstrap.php /app/render-bootstrap.php
RUN printf '\nauto_prepend_file = /app/render-bootstrap.php\n' >> /app/bin/php.ini

# Long Telegram-backed streams must not be terminated by PHP's default
# execution timeout.
RUN if grep -qE '^[;[:space:]]*max_execution_time[[:space:]]*=' /app/bin/php.ini; then \
        sed -Ei 's/^[;[:space:]]*max_execution_time[[:space:]]*=.*/max_execution_time = 0/' /app/bin/php.ini; \
    else \
        printf '\nmax_execution_time = 0\n' >> /app/bin/php.ini; \
    fi

ENV PENCARIMOVIE_RENDER_MODE=1
ENV PENCARIMOVIE_PUBLIC_HOST=pencarimovie-downloader.onrender.com
ENV PENCARIMOVIE_STORAGE_DIR=/app/storage

EXPOSE 10000

CMD ["/bin/sh", "-c", "exec /app/bin/frankenphp php-server --listen 0.0.0.0:${PORT:-10000} --root /app"]
