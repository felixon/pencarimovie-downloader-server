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

// Safe Telegram token diagnostic for Render deployments.
// This checks the token stored in PENCARIMOVIE_BOT_TOKEN directly with
// Telegram's Bot API without ever returning the token itself.
if ($path === '/api/env-token-check' || $path === '/api/env-token-check/') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $token = trim((string) ($_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? $_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? ''));
    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'PENCARIMOVIE_BOT_TOKEN is not available to PHP at runtime.']);
        exit;
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/getMe';
    $response = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    $data = is_string($response) ? json_decode($response, true) : null;
    if (!is_array($data)) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'Telegram did not return valid JSON.',
            'http_code' => $httpCode,
        ]);
        exit;
    }

    if (($data['ok'] ?? false) === true) {
        $bot = is_array($data['result'] ?? null) ? $data['result'] : [];
        echo json_encode([
            'ok' => true,
            'token_available' => true,
            'telegram_valid' => true,
            'bot_id' => $bot['id'] ?? null,
            'bot_username' => $bot['username'] ?? null,
            'bot_name' => $bot['first_name'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'token_available' => true,
        'telegram_valid' => false,
        'http_code' => $httpCode,
        'telegram_error' => $data['description'] ?? 'Telegram rejected the token.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// All application, API, Nuvio and static-file routes continue through the
// existing router. The router serves the dashboard at /.
require __DIR__ . '/router.php';
