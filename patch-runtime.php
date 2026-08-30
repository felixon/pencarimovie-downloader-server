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
    <<<'SEARCH'
define('FD_STREAM_POOL_SIZE', 2);
SEARCH,
    <<<'REPLACE'
define('FD_STREAM_POOL_SIZE', 4);
REPLACE,
    'stream pool size'
);

// Log the actual session path selected by fd_boot_madeline().
patch_once(
    $code,
    <<<'SEARCH'
        'session_exists' => is_dir(FD_SESSION_PATH) || is_file(FD_SESSION_PATH),
SEARCH,
    <<<'REPLACE'
        'session_path' => $sessionPathOverride ?: FD_SESSION_PATH,
        'session_exists' => is_dir($sessionPathOverride ?: FD_SESSION_PATH) || is_file($sessionPathOverride ?: FD_SESSION_PATH),
REPLACE,
    'actual session diagnostics'
);

// Give every active stream its own MadelineProto session path.
patch_once(
    $code,
    <<<'SEARCH'
    $streamSlot = fd_acquire_stream_slot();
SEARCH,
    <<<'REPLACE'
    $streamSlot = fd_acquire_stream_slot();
    $streamSlot['session'] = FD_STREAM_POOL_DIR . DIRECTORY_SEPARATOR
        . 'active-' . $streamSlot['slot'] . '-' . getmypid() . '-' . bin2hex(random_bytes(8)) . '.madeline';
REPLACE,
    'unique stream session path'
);

patch_once(
    $code,
    <<<'SEARCH'
    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);
SEARCH,
    <<<'REPLACE'
    fd_log('stream slot acquired', [
        'slot' => $streamSlot['slot'],
        'session' => $streamSlot['session'],
        'pid' => getmypid(),
    ]);
    fd_log('booting dedicated stream session', [
        'slot' => $streamSlot['slot'],
        'session' => $streamSlot['session'],
        'exists' => is_dir($streamSlot['session']) || is_file($streamSlot['session']),
    ]);
    $streamBotToken = trim((string) (getenv('PENCARIMOVIE_BOT_TOKEN') ?: ($_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? $_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? '')));
    [$madeline, $error] = fd_boot_madeline(
        $streamBotToken !== '' ? $streamBotToken : null,
        [],
        $streamSlot['session']
    );
REPLACE,
    'dedicated stream boot'
);

patch_once(
    $code,
    <<<'SEARCH'
    fd_log('starting concurrent downloadToBrowser', [
SEARCH,
    <<<'REPLACE'
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');
        header('X-Stream-Slot: ' . (string) $streamSlot['slot']);
    }
    fd_log('starting concurrent downloadToBrowser', [
REPLACE,
    'stream response handling'
);

// Delete the request-specific session only after MadelineProto is released.
patch_once(
    $code,
    <<<'SEARCH'
} finally {
    unset($madeline);
    fd_release_stream_slot($streamSlot);
}
SEARCH,
    <<<'REPLACE'
} finally {
    unset($madeline);
    $activeSession = (string) ($streamSlot['session'] ?? '');
    fd_release_stream_slot($streamSlot);
    if ($activeSession !== '') {
        try {
            if (is_file($activeSession)) {
                @unlink($activeSession);
            } elseif (is_dir($activeSession)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($activeSession, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $info) {
                    $info->isDir() ? @rmdir($info->getRealPath()) : @unlink($info->getRealPath());
                }
                @rmdir($activeSession);
            }
        } catch (Throwable $cleanupError) {
            fd_log('stream session cleanup failed', [
                'slot' => $streamSlot['slot'] ?? null,
                'session' => $activeSession,
                'error' => $cleanupError->getMessage(),
            ]);
        }
    }
}
REPLACE,
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
