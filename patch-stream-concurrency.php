<?php

declare(strict_types=1);

$file = '/app/backend.php';
if (!is_file($file)) {
    throw new RuntimeException("backend.php not found at {$file}");
}

$code = (string) file_get_contents($file);
$original = $code;

function patch_once(string &$code, string $search, string $replace, string $label): void
{
    $count = substr_count($code, $search);
    if ($count !== 1) {
        throw new RuntimeException("Expected exactly one match for {$label}, found {$count}");
    }
    $code = str_replace($search, $replace, $code);
}

// Give the backend a little headroom beyond the two simultaneous players.
patch_once(
    $code,
    "define('FD_STREAM_POOL_SIZE', 2);",
    "define('FD_STREAM_POOL_SIZE', 4);",
    'stream pool size'
);

// Log the actual session selected by the caller. The old log always reported
// FD_SESSION_PATH, which made dedicated stream sessions look like the shared
// session even when the override was being used.
patch_once(
    $code,
    "        'session_exists' => is_dir(FD_SESSION_PATH) || is_file(FD_SESSION_PATH),",
    "        'session_path' => $sessionPathOverride ?: FD_SESSION_PATH,\n        'session_exists' => is_dir($sessionPathOverride ?: FD_SESSION_PATH) || is_file($sessionPathOverride ?: FD_SESSION_PATH),",
    'actual Madeline session logging'
);

// Make the stream slot/session decision visible before MadelineProto is booted.
patch_once(
    $code,
    "    $streamSlot = fd_acquire_stream_slot();",
    "    $streamSlot = fd_acquire_stream_slot();\n    fd_log('stream slot acquired', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n        'pid' => getmypid(),\n    ]);",
    'stream slot diagnostics'
);

patch_once(
    $code,
    "    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);",
    "    if ($streamSlot['session'] === '') {\n        throw new RuntimeException('Stream slot did not provide a session path.');\n    }\n    fd_log('booting dedicated stream session', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n        'exists' => is_dir($streamSlot['session']) || is_file($streamSlot['session']),\n    ]);\n    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);",
    'dedicated stream boot'
);

// Prevent buffering layers from delaying the video response and explicitly
// disable proxy buffering where the hosting stack supports this header.
patch_once(
    $code,
    "    fd_log('starting concurrent downloadToBrowser', [",
    "    if (function_exists('set_time_limit')) { @set_time_limit(0); }\n    if (!headers_sent()) {\n        header('Cache-Control: no-store, no-cache, must-revalidate');\n        header('X-Accel-Buffering: no');\n        header('X-Stream-Slot: ' . (string) $streamSlot['slot']);\n    }\n    fd_log('starting concurrent downloadToBrowser', [",
    'stream response headers'
);

if ($code === $original) {
    throw new RuntimeException('No changes were made to backend.php');
}

if (file_put_contents($file, $code, LOCK_EX) === false) {
    throw new RuntimeException('Could not write patched backend.php');
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

fwrite(STDOUT, "Stream concurrency patch applied successfully.\n");
