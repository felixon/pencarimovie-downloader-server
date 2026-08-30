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

# MadelineProto's web self-restart/IPC mode must not be forced in FrankenPHP.
# There are two assignments in the packaged backend: one at file startup and
# another inside fd_boot_madeline(). Disable BOTH. Each request must construct
# its own in-process API instance instead of waiting on a shared IPC server.
RUN perl -0pi -e 's/if \(!isset\(\$_GET\['"'"'MadelineSelfRestart'"'"'\]\)\) \{\s*\$_GET\['"'"'MadelineSelfRestart'"'"'\] = '"'"'1'"'"';\s*\}/unset(\$_GET['"'"'MadelineSelfRestart'"'"']);/g; s/\$_GET\['"'"'MadelineSelfRestart'"'"'\] = '"'"'1'"'"';/unset(\$_GET['"'"'MadelineSelfRestart'"'"']);/g' /app/backend.php

# Do not destroy the stream pool when the dashboard logs in. Active streams
# have independent sessions and must survive a token refresh/login request.
RUN perl -0pi -e 's/\n\s*fd_clear_stream_pool\(\);\n\s*fd_clear_session\(\);/\n        fd_clear_session();/s' /app/backend.php

# Do not warm/create stream sessions during login. This avoids unnecessary
# MadelineProto initialization and IPC contention. Stream sessions are created
# lazily when /api/download actually receives a stream request.
RUN perl -0pi -e 's/\n\s*\$pool = fd_warm_stream_pool\(\$botToken\);\n\s*fd_log\('\''stream pool warmup complete'\'', \$pool\);/\n            fd_log('\''stream pool warmup skipped; streams are lazy'\'', []);/s' /app/backend.php

# The slot lock still limits the number of simultaneous streams, but every
# active request receives its own unique MadelineProto session directory.
# This prevents stream 2 from touching stream 1's session/IPC state.
RUN perl -0pi -e 's/(\$streamSlot = fd_acquire_stream_slot\(\);)/$1\n    $streamSlot['"'"'session'"'"'] = FD_STREAM_POOL_DIR . DIRECTORY_SEPARATOR . '\''active-'\'' . $streamSlot['"'"'slot'"'"'] . '\''-'\'' . getmypid() . '\''-'\'' . bin2hex(random_bytes(6)) . '\''.madeline'\'';/s' /app/backend.php

# Give every concurrent stream the server-side bot token if its dedicated
# session needs to be initialized or repaired.
RUN sed -i 's@\[\$madeline, \$error\] = fd_boot_madeline(null, \[\], \$streamSlot\['"'"'session'"'"'\]);@\$streamBotToken = trim((string) (getenv("PENCARIMOVIE_BOT_TOKEN") ?: (\$_SERVER["PENCARIMOVIE_BOT_TOKEN"] ?? \$_ENV["PENCARIMOVIE_BOT_TOKEN"] ?? "")));\n    fd_log("stream slot acquired", ["slot" => \$streamSlot["slot"], "session" => \$streamSlot["session"]]);\n    [\$madeline, \$error] = fd_boot_madeline(\$streamBotToken !== "" ? \$streamBotToken : null, [], \$streamSlot["session"]);@' /app/backend.php

# Verify that the image will not force MadelineSelfRestart and that login no
# longer performs the stream-pool warmup.
RUN ! grep -n "MadelineSelfRestart.*= '1'" /app/backend.php \
    && ! grep -n "fd_warm_stream_pool(\$botToken)" /app/backend.php

ENV PENCARIMOVIE_STORAGE_DIR=/app/storage

EXPOSE 10000

CMD ["/bin/sh", "-c", "exec /app/bin/frankenphp php-server --listen 0.0.0.0:${PORT:-10000} --root /app"]
