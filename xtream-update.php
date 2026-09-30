<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Command line only.\n";
    exit(1);
}

require_once __DIR__ . '/xtream.lib.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');

try {
    $epg = epgRunUpdate('cron', true);
    if (!empty($epg['skipped'])) {
        fwrite(STDERR, "EPG update skipped: an update is already running.\n");
        exit(1);
    }
    echo 'EPG updated. Programmes: ' . (int) ($epg['programmes'] ?? 0) . "\n";

    $built = xtreamBuildCatalog();
    if (($built['epg_error'] ?? '') !== '') {
        $message = 'Xtream guide failed: ' . $built['epg_error'];
        epgLog($message);
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    echo 'Xtream catalog built. Categories: ' . (int) ($built['categories'] ?? 0)
        . ' Channels: ' . (int) ($built['channels'] ?? 0) . "\n";
} catch (Throwable $e) {
    try {
        epgStateSet('xtream_build_error', $e->getMessage());
    } catch (Throwable $ignored) {
    }
    epgLog('Xtream update failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
