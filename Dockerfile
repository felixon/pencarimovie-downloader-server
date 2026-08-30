FROM debian:bookworm-slim

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates tar perl \
    && rm -rf /var/lib/apt/lists/*

# Use the same packaged PencariMovie runtime as the known-working deployment.
# v1.0.1 is the current published release; v1.0.0 does not exist.
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

# The packaged backend was originally forcing MadelineProto's web self-restart
# mode on every request. That mode is intended to keep a single long-running
# web bot alive, but it can serialize independent API constructors inside the
# same FrankenPHP process. Our server already has its own stream-slot locking
# and Render provides long-lived PHP requests, so disable that global self-
# restart flag. This is the key concurrency fix: each stream slot can construct
# its own MadelineProto session without waiting on the first stream's web lock.
RUN perl -0pi -e 's/if \(!isset\(\$_GET\['"'"'MadelineSelfRestart'"'"'\]\)\) \{\s*\$_GET\['"'"'MadelineSelfRestart'"'"'\] = '"'"'1'"'"';\s*\}/unset(\$_GET['"'"'MadelineSelfRestart'"'"']);/g; s/\$_GET\['"'"'MadelineSelfRestart'"'"'\] = '"'"'1'"'"';/unset(\$_GET['"'"'MadelineSelfRestart'"'"']);/g' /app/backend.php

# Give every concurrent stream the server-side bot token if its dedicated
# session needs to be initialized or repaired.
RUN sed -i 's@\[\$madeline, \$error\] = fd_boot_madeline(null, \[\], \$streamSlot\['"'"'session'"'"'\]);@\$streamBotToken = trim((string) (getenv("PENCARIMOVIE_BOT_TOKEN") ?: (\$_SERVER["PENCARIMOVIE_BOT_TOKEN"] ?? \$_ENV["PENCARIMOVIE_BOT_TOKEN"] ?? "")));\n    fd_log("stream slot acquired", ["slot" => \$streamSlot["slot"], "session" => \$streamSlot["session"]]);\n    [\$madeline, \$error] = fd_boot_madeline(\$streamBotToken !== "" ? \$streamBotToken : null, [], \$streamSlot["session"]);@' /app/backend.php

# Verify the source was patched during the image build. Fail the build rather
# than silently deploying a backend that still forces MadelineSelfRestart.
RUN ! grep -n "MadelineSelfRestart.*= '1'" /app/backend.php

ENV PENCARIMOVIE_STORAGE_DIR=/app/storage

EXPOSE 10000

CMD ["/bin/sh", "-c", "exec /app/bin/frankenphp php-server --listen 0.0.0.0:${PORT:-10000} --root /app"]
