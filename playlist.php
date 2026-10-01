<?php

require_once __DIR__ . '/lib.php';

set_time_limit(120);
ini_set('memory_limit', '256M');

function requestWantsPublishedPlaylist(): bool
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    return is_string($path) && preg_match('#/channels\.m3u8$#', $path) === 1;
}

$publishedPath = publishedPlaylistPath();
if (requestWantsPublishedPlaylist() && !isset($_REQUEST['filter']) && is_file($publishedPath)) {
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: inline; filename="e2.m3u8"');
    header('Content-Length: ' . (string) filesize($publishedPath));
    header('Cache-Control: no-cache');
    readfile($publishedPath);
    exit;
}

$type = $_REQUEST['type'] ?? 'all';
if (!in_array($type, ['all', 'stream', 'tv'], true)) {
    $type = 'all';
}

$selected = null;
if (isset($_REQUEST['filter'])) {
    $selected = [];
    $raw = $_REQUEST['bouquet'] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    foreach ($raw as $ref) {
        if (is_string($ref) && $ref !== '' && strlen($ref) < 500) {
            $selected[$ref] = true;
        }
    }
}

try {
    $services = fetchAllServices();
} catch (Throwable $e) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
if (isset($_REQUEST['download'])) {
    header('Content-Disposition: attachment; filename="e2.m3u8"');
} else {
    header('Content-Disposition: inline; filename="e2.m3u8"');
}

ob_start();
writePlaylist($services, $selected, $type);
$body = ob_get_clean();
if ($body === false) {
    $body = '';
}
if (isset($_REQUEST['download']) || requestWantsPublishedPlaylist()) {
    savePublishedPlaylist($body);
}
header('Content-Length: ' . (string) strlen($body));
echo $body;
try {
    if (is_file(__DIR__ . '/epg.lib.php')) {
        require_once __DIR__ . '/epg.lib.php';
        epgKickUpdateIfDue();
    }
} catch (Throwable $e) {
    // The playlist is already sent. An EPG error must not stop it.
}
