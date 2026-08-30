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

// Allow more than two concurrent stream requests. Two players remain fully
// supported, while extra requests no longer become artificially blocked.
patch_once(
    $code,
    "define('FD_STREAM_POOL_SIZE', 2);",
    "define('FD_STREAM_POOL_SIZE', 4);",
    'stream pool size'
);

// The previous diagnostic always printed FD_SESSION_PATH, which is the shared
// login session. That made dedicated stream sessions appear to use the shared
// session even when an override was passed. Log the actual selected path.
patch_once(
    $code,
    "        'session_exists' => is_dir(FD_SESSION_PATH) || is_file(FD_SESSION_PATH),",
    "        'session_path' => $sessionPathOverride ?: FD_SESSION_PATH,\n        'session_exists' => is_dir($sessionPathOverride ?: FD_SESSION_PATH) || is_file($sessionPathOverride ?: FD_SESSION_PATH),",
    'actual session diagnostics'
);

// Log slot ownership so concurrent requests can be proven to use different
// stream sessions rather than waiting on the shared login session.
patch_once(
    $code,
    "    $streamSlot = fd_acquire_stream_slot();",
    "    $streamSlot = fd_acquire_stream_slot();\n    fd_log('stream slot acquired', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n        'pid' => getmypid(),\n    ]);",
    'stream slot acquisition diagnostics'
);

patch_once(
    $code,
    "    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);",
    "    if ($streamSlot['session'] === '') {\n        throw new RuntimeException('Stream slot did not provide a session path.');\n    }\n    fd_log('booting dedicated stream session', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n        'exists' => is_dir($streamSlot['session']) || is_file($streamSlot['session']),\n    ]);\n    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);",
    'dedicated stream boot diagnostics'
);

// Keep long-running browser streams alive and avoid reverse-proxy buffering.
patch_once(
    $code,
    "    fd_log('starting concurrent downloadToBrowser', [",
    "    if (function_exists('set_time_limit')) { @set_time_limit(0); }\n    if (!headers_sent()) {\n        header('Cache-Control: no-store, no-cache, must-revalidate');\n        header('X-Accel-Buffering: no');\n        header('X-Stream-Slot: ' . (string) $streamSlot['slot']);\n    }\n    fd_log('starting concurrent downloadToBrowser', [",
    'stream response handling'
);

if ($code === $original) {
    throw new RuntimeException('No backend changes were made');
}

if (file_put_contents($file, $code, LOCK_EX) === false) {
    throw new RuntimeException('Could not write patched backend.php');
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

fwrite(STDOUT, "PencariMovie stream concurrency runtime patch applied.\n");
