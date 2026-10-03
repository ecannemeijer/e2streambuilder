<?php

require_once __DIR__ . '/epg.lib.php';

$token = (string) ($_GET['token'] ?? '');
$home = homeByToken($token);
$path = $home === null ? '' : homePlaylistPath($token);
if ($home === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This house playlist has not been published yet.\n";
    exit;
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="channels.m3u8"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
readfile($path);
