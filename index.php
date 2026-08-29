<?php

declare(strict_types=1);

// Render's health check commonly probes /. Keep the root endpoint fast and
// independent of Telegram/MadelineProto so deployment health checks do not
// block on session initialization.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PencariMovie server OK\n";
    exit;
}

// All API and addon routes continue through the existing application router.
require __DIR__ . '/router.php';
