<?php

declare(strict_types=1);

$path = '/app/backend.php';
if (!is_file($path)) {
    fwrite(STDERR, "backend.php not found at {$path}\n");
    exit(1);
}

$code = (string) file_get_contents($path);
$original = $code;

// FrankenPHP is an HTTP worker. Do not let MadelineProto enable its web
// self-restart/IPC mechanism. The packaged backend sets this in two places.
$code = preg_replace(
    "~if\s*\(!isset\(\$_GET\['MadelineSelfRestart'\]\)\)\s*\{\s*\$_GET\['MadelineSelfRestart'\]\s*=\s*'1';\s*\}~",
    "unset(\$_GET['MadelineSelfRestart']);",
    $code
) ?? $code;
$code = str_replace(
    "\$_GET['MadelineSelfRestart'] = '1';",
    "unset(\$_GET['MadelineSelfRestart']);",
    $code
);

// Login should not create/warm stream sessions. Apart from being unnecessary,
// doing so starts multiple MadelineProto instances during one HTTP request.
$code = str_replace(
    "        fd_clear_stream_pool();\n        fd_clear_session();",
    "        fd_clear_session();",
    $code
);
$code = str_replace(
    "            $pool = fd_warm_stream_pool($botToken);\n            fd_log('stream pool warmup complete', $pool);",
    "            fd_log('stream pool warmup skipped; streams are lazy', []);",
    $code
);

// Every HTTP stream gets its own MTProto session directory. Reusing
// session-1/session-2 can make two requests contend for the same MadelineProto
// session and fall back to IPC.
$needle = "    $streamSlot = fd_acquire_stream_slot();\n";
$replacement = "    $streamSlot = fd_acquire_stream_slot();\n    $streamSlot['session'] = FD_STREAM_POOL_DIR . DIRECTORY_SEPARATOR . 'active-' . $streamSlot['slot'] . '-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.madeline';\n";
if (strpos($code, $replacement) === false && strpos($code, $needle) !== false) {
    $code = str_replace($needle, $replacement, $code);
}

// A fresh per-stream session has no existing login, so pass the Render secret
// to MadelineProto when booting it. Never expose this token to the browser.
$oldBoot = "    [$madeline, $error] = fd_boot_madeline(null, [], $streamSlot['session']);";
$newBoot = "    $streamBotToken = trim((string) (getenv('PENCARIMOVIE_BOT_TOKEN') ?: ($_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? $_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? '')));\n    fd_log('stream slot acquired', ['slot' => $streamSlot['slot'], 'session' => $streamSlot['session']]);\n    [$madeline, $error] = fd_boot_madeline($streamBotToken !== '' ? $streamBotToken : null, [], $streamSlot['session']);";
$code = str_replace($oldBoot, $newBoot, $code);

if ($code === $original) {
    fwrite(STDERR, "Runtime patch made no changes. Release layout may have changed.\n");
    exit(2);
}

file_put_contents($path, $code, LOCK_EX);

// Hard checks: fail the image build instead of deploying a broken patch.
if (str_contains($code, "\$_GET['MadelineSelfRestart'] = '1';")) {
    fwrite(STDERR, "MadelineSelfRestart assignment still present.\n");
    exit(3);
}
if (str_contains($code, 'fd_warm_stream_pool($botToken)')) {
    fwrite(STDERR, "Stream pool warmup still present.\n");
    exit(4);
}
if (str_contains($code, "fd_boot_madeline(null, [], \$streamSlot['session'])")) {
    fwrite(STDERR, "Stream boot still uses a null bot token.\n");
    exit(5);
}
if (!str_contains($code, "streamBotToken") || !str_contains($code, "active-' . \$streamSlot['slot']")) {
    fwrite(STDERR, "Concurrent stream patch was not installed.\n");
    exit(6);
}

echo "Runtime patch applied successfully.\n";
