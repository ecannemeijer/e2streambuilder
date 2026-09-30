<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Command line only.\n";
    exit(1);
}

require_once __DIR__ . '/epg.lib.php';

$force = in_array('--force', $argv ?? [], true);

try {
    $result = epgRunUpdate($force ? 'manual' : 'cron', $force);
    if (!empty($result['skipped'])) {
        echo "No update needed.\n";
        exit(0);
    }
    echo 'Updated. Programmes: ' . (int) ($result['programmes'] ?? 0) . "\n";
} catch (Throwable $e) {
    epgLog('EPG update failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
