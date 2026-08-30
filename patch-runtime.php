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

patch_once(
    $code,
    "define('FD_STREAM_POOL_SIZE', 2);",
    "define('FD_STREAM_POOL_SIZE', 4);",
    'stream pool size'
);

// Log the real session path selected by fd_boot_madeline(), not only the
// shared main session path.
patch_once(
    $code,
    "        'session_exists' => is_dir(FD_SESSION_PATH) || is_file(FD_SESSION_PATH),",
    "        'session_path' => $sessionPathOverride ?: FD_SESSION_PATH,\n        'session_exists' => is_dir($sessionPathOverride ?: FD_SESSION_PATH) || is_file($sessionPathOverride ?: FD_SESSION_PATH),",
    'actual session diagnostics'
);

// Each request keeps its slot lock, but uses a unique session filename so two
// HTTP requests can never make MadelineProto open the same session at once.
$slotNeedle = "    $streamSlot = fd_acquire_stream_slot();";
$slotReplace = "    $streamSlot = fd_acquire_stream_slot();\n    $baseStreamSession = $streamSlot['session'];\n    $streamSlot['session'] = FD_STREAM_POOL_DIR . DIRECTORY_SEPARATOR\n        . 'active-' . $streamSlot['slot'] . '-' . getmypid() . '-' . bin2hex(random_bytes(8)) . '.madeline';";
patch_once($code, $slotNeedle, $slotReplace, 'unique stream session path');

patch_once(
    $code,
    "    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);",
    "    fd_log('stream slot acquired', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n        'pid' => getmypid(),\n    ]);\n    fd_log('booting dedicated stream session', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n        'exists' => is_dir($streamSlot['session']) || is_file($streamSlot['session']),\n    ]);\n    $streamBotToken = trim((string) (getenv('PENCARIMOVIE_BOT_TOKEN') ?: ($_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? $_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? '')));\n    [$madeline, $error] = fd_boot_madeline(\n        $streamBotToken !== '' ? $streamBotToken : null,\n        [],\n        $streamSlot['session']\n    );",
    'dedicated stream boot'
);

patch_once(
    $code,
    "    fd_log('starting concurrent downloadToBrowser', [",
    "    if (function_exists('set_time_limit')) { @set_time_limit(0); }\n    if (!headers_sent()) {\n        header('Cache-Control: no-store, no-cache, must-revalidate');\n        header('X-Accel-Buffering: no');\n        header('X-Stream-Slot: ' . (string) $streamSlot['slot']);\n    }\n    fd_log('starting concurrent downloadToBrowser', [",
    'stream response handling'
);

// Delete the unique request session after the stream ends. The slot lock is
// released first only after MadelineProto is fully destroyed.
patch_once(
    $code,
    "} finally {\n    unset($madeline);\n    fd_release_stream_slot($streamSlot);\n}",
    "} finally {\n    unset($madeline);\n    $activeSession = (string) ($streamSlot['session'] ?? '');\n    fd_release_stream_slot($streamSlot);\n    if ($activeSession !== '') {\n        try {\n            if (is_file($activeSession)) {\n                @unlink($activeSession);\n            } elseif (is_dir($activeSession)) {\n                $it = new RecursiveIteratorIterator(\n                    new RecursiveDirectoryIterator($activeSession, RecursiveDirectoryIterator::SKIP_DOTS),\n                    RecursiveIteratorIterator::CHILD_FIRST\n                );\n                foreach ($it as $info) {\n                    $info->isDir() ? @rmdir($info->getRealPath()) : @unlink($info->getRealPath());\n                }\n                @rmdir($activeSession);\n            }\n        } catch (Throwable $cleanupError) {\n            fd_log('stream session cleanup failed', [\n                'slot' => $streamSlot['slot'] ?? null,\n                'session' => $activeSession,\n                'error' => $cleanupError->getMessage(),\n            ]);\n        }\n    }\n}",
    'stream session cleanup'
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
