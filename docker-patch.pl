#!/usr/bin/perl
use strict;
use warnings;

my $path = shift @ARGV or die "Usage: docker-patch.pl /app/backend.php\n";
open my $fh, '<', $path or die "Cannot read $path: $!\n";
local $/;
my $s = <$fh>;
close $fh;

# Never delete the stream pool when the dashboard logs in. Active streams have
# their own sessions and must survive a token refresh/login request.
$s =~ s/\n\s*fd_clear_stream_pool\(\);\n\s*fd_clear_session\(\);/\n        fd_clear_session();/s
    or die "Could not patch botlogin session cleanup\n";

# Do not construct all stream sessions during login. Each stream creates its
# own session lazily, avoiding startup/IPC contention and making login fast.
$s =~ s/\n\s*\$pool = fd_warm_stream_pool\(\$botToken\);\n\s*fd_log\('stream pool warmup complete', \$pool\);/\n            fd_log('stream pool warmup skipped; streams are lazy', []);/s
    or die "Could not patch stream pool warmup\n";

# Give every active browser stream a unique MadelineProto session directory.
# The slot still limits concurrency to the configured pool size, but requests
# never share a MadelineProto session/IPC lock.
$s =~ s{\n\s*// Use an independent session for every active stream\.\ntry \{\n\s*\$streamSlot = fd_acquire_stream_slot\(\);\n\} catch \(Throwable \$slotError\) \{}{\n        // Use a unique, per-request session for every active stream.\ntry {\n    $streamSlot = fd_acquire_stream_slot();\n    $streamSlot['session'] = FD_STREAM_POOL_DIR . DIRECTORY_SEPARATOR\n        . 'active-' . $streamSlot['slot'] . '-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.madeline';\n    fd_log('stream slot acquired', [\n        'slot' => $streamSlot['slot'],\n        'session' => $streamSlot['session'],\n    ]);\n} catch (Throwable \$slotError) {}s
    or die "Could not patch stream slot acquisition\n";

# Initialize a fresh stream session with the server-side Render token. The
# token is never exposed to the browser.
$s =~ s{\n\s*\$madeline = null;\ntry \{\n\s*\[\$madeline, \$error\] = fd_boot_madeline\(null, \[\], \$streamSlot\['session'\]\);}{\n\$madeline = null;\ntry {\n    \$streamBotToken = trim((string) (\$_SERVER['PENCARIMOVIE_BOT_TOKEN'] ?? \$_ENV['PENCARIMOVIE_BOT_TOKEN'] ?? ''));\n    [\$madeline, \$error] = fd_boot_madeline(\n        \$streamBotToken !== '' ? \$streamBotToken : null,\n        [],\n        \$streamSlot['session']\n    );}s
    or die "Could not patch stream session boot\n";

# Release the Madeline instance before deleting the unique request session.
$s =~ s{\n\} finally \{\n\s*unset\(\$madeline\);\n\s*fd_release_stream_slot\(\$streamSlot\);\n\}{}{\n} finally {\n    unset(\$madeline);\n    fd_release_stream_slot(\$streamSlot);\n\n    \$activeSession = (string) (\$streamSlot['session'] ?? '');\n    if (\$activeSession !== '') {\n        try {\n            if (is_dir(\$activeSession)) {\n                \$files = new RecursiveIteratorIterator(\n                    new RecursiveDirectoryIterator(\$activeSession, RecursiveDirectoryIterator::SKIP_DOTS),\n                    RecursiveIteratorIterator::CHILD_FIRST\n                );\n                foreach (\$files as \$fileinfo) {\n                    if (\$fileinfo->isDir()) {\n                        @rmdir(\$fileinfo->getRealPath());\n                    } else {\n                        @unlink(\$fileinfo->getRealPath());\n                    }\n                }\n                @rmdir(\$activeSession);\n            } elseif (is_file(\$activeSession)) {\n                @unlink(\$activeSession);\n            }\n        } catch (Throwable \$cleanupError) {\n            fd_log('active stream session cleanup skipped', [\n                'slot' => \$streamSlot['slot'] ?? null,\n                'error' => \$cleanupError->getMessage(),\n            ]);\n        }\n    }\n} }s
    or die "Could not patch stream session cleanup\n";

open my $out, '>', $path or die "Cannot write $path: $!\n";
print {$out} $s;
close $out;

print "Patched $path successfully\n";
