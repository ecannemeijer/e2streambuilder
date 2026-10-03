<?php

require_once __DIR__ . '/epg.lib.php';

authRequireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['create_all'])) {
    header('Location: index.php');
    exit;
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');
$progress = (string) ($_POST['progress'] ?? '') === '1';
if ($progress) {
    epgProgressBegin();
}

try {
    epgWithLock(static function (): void {
        epgLog('Reading channels from the receiver');
        $services = fetchAllServices();
        epgRematchAll();
        epgGenerateXml();
        epgResetTvgCache();
        epgLog('Writing channels.m3u8');
        ob_start();
        $count = writePlaylist($services, null, 'all');
        $body = ob_get_clean();
        if ($body === false || $body === '') {
            throw new RuntimeException('The playlist could not be written.');
        }
        savePublishedPlaylist($body);
        epgLog('Playlist written: ' . $count . ' channels');
    });
    if ($progress) {
        epgProgressEmit('Playlist and guide created.', true, false);
        exit;
    }
    header('Location: index.php');
} catch (Throwable $e) {
    if ($progress) {
        epgProgressEmit($e->getMessage(), true, true);
        exit;
    }
    header('Location: index.php');
}
exit;
