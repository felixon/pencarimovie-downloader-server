<?php

declare(strict_types=1);

/**
 * Render compatibility shim for the upstream PencariMovie server.
 *
 * The upstream API intentionally treats non-local requests as untrusted because
 * the original application is designed to run on the same machine/LAN as the
 * browser. Render terminates the public connection at its proxy before PHP sees
 * the request, so REMOTE_ADDR is not loopback even for the application's own UI.
 *
 * Only enable this behavior when the deployment explicitly opts into
 * PENCARIMOVIE_RENDER_MODE. The host check prevents accidentally enabling it in
 * another environment. This does not expose or alter the bot token.
 */
$renderMode = trim((string) (getenv('PENCARIMOVIE_RENDER_MODE') ?: ($_SERVER['PENCARIMOVIE_RENDER_MODE'] ?? '')));
if ($renderMode !== '1') {
    return;
}

$host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
$host = preg_replace('/:\\d+$/', '', $host) ?? $host;
$configuredHost = strtolower(trim((string) (getenv('PENCARIMOVIE_PUBLIC_HOST') ?: 'pencarimovie-downloader.onrender.com')));

$allowed = $host !== '' && (
    $host === $configuredHost
    || str_ends_with($host, '.onrender.com')
);

if (!$allowed) {
    return;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// The upstream local-only check is applied only to API requests. Keep normal
// static pages and Nuvio routes untouched.
if (str_starts_with($path, '/api/')) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
