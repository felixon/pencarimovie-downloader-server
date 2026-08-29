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

// Diagnostic for the WordPress /save-bot-token handshake.
// It deliberately never returns the bot token, encrypted credentials, API hash,
// or API secret. It compares the JSON and form-encoded request styles so we can
// detect a WordPress/PHP request-parsing mismatch without exposing secrets.
if ($path === '/api/wp-token-check' || $path === '/api/wp-token-check/') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $token = trim((string) ($_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? $_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? ''));
    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'PENCARIMOVIE_BOT_TOKEN is not available to PHP at runtime.']);
        exit;
    }

    $url = 'https://pencarimovie.com/wp-json/fastdownloader/v1/save-bot-token';

    $post = static function (string $body, string $contentType) use ($url): array {
        $response = false;
        $httpCode = 0;
        $contentTypeHeader = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: ' . $contentType,
                    'User-Agent: PencariMovie-Downloader/1.0',
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentTypeHeader = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nContent-Type: {$contentType}\r\nUser-Agent: PencariMovie-Downloader/1.0\r\n",
                    'content' => $body,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $response = @file_get_contents($url, false, $context);
            if (isset($http_response_header) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
                $httpCode = (int) $m[1];
            }
            if (isset($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (stripos($line, 'Content-Type:') === 0) {
                        $contentTypeHeader = trim(substr($line, strlen('Content-Type:')));
                    }
                }
            }
        }

        $data = is_string($response) ? json_decode(trim($response), true) : null;
        return [
            'http_code' => $httpCode,
            'content_type' => $contentTypeHeader,
            'json' => is_array($data),
            'ok' => is_array($data) && (($data['ok'] ?? false) === true),
            'message' => is_array($data) ? (string) ($data['message'] ?? '') : '',
        ];
    };

    $jsonResult = $post(
        json_encode(['bot_token' => $token], JSON_UNESCAPED_SLASHES),
        'application/json'
    );
    $formResult = $post(
        http_build_query(['bot_token' => $token], '', '&', PHP_QUERY_RFC3986),
        'application/x-www-form-urlencoded'
    );

    echo json_encode([
        'ok' => $jsonResult['ok'] || $formResult['ok'],
        'json_request' => $jsonResult,
        'form_request' => $formResult,
        'note' => 'Secrets and encrypted credentials are intentionally omitted.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// All application, API, Nuvio and static-file routes continue through the
// existing router. The router serves the dashboard at /.
require __DIR__ . '/router.php';
