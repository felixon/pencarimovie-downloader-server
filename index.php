<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Dedicated lightweight health endpoint for Render.
if ($path === '/health' || $path === '/health/') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PencariMovie server OK\n";
    exit;
}

// All application, API, Nuvio and static-file routes continue through the
// existing router. The router serves the dashboard at /.
require __DIR__ . '/router.php';
