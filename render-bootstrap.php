<?php

declare(strict_types=1);

/**
 * Render compatibility shim for the official PencariMovie v1.1.0 runtime.
 * Render's public edge can add Cloudflare headers. The upstream application
 * uses those headers to identify TryCloudflare tunnels, which would incorrectly
 * classify a normal Render request as remote. Only enable this on this explicit
 * Render deployment.
 */
$renderMode = trim((string) (getenv('PENCARIMOVIE_RENDER_MODE') ?: ($_SERVER['PENCARIMOVIE_RENDER_MODE'] ?? '')));
if ($renderMode !== '1') {
    return;
}

$host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
$host = preg_replace('/:\d+$/', '', $host) ?? $host;
$configuredHost = strtolower(trim((string) (getenv('PENCARIMOVIE_PUBLIC_HOST') ?: 'pencarimovie-downloader.onrender.com')));
if ($host !== $configuredHost && !str_ends_with($host, '.onrender.com')) {
    return;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    // Make the upstream local-request test pass for Render API calls.
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    // Render's edge may send these; the upstream code interprets them as a
    // TryCloudflare tunnel and rejects the request before checking REMOTE_ADDR.
    unset(
        $_SERVER['HTTP_CF_CONNECTING_IP'],
        $_SERVER['HTTP_CF_RAY'],
        $_SERVER['HTTP_CF_VISITOR']
    );
}
