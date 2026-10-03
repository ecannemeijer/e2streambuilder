<?php

require_once __DIR__ . '/epg.lib.php';

$key = (string) ($_GET['token'] ?? '');
$home = homeByToken($key);
if ($home === null) {
    $home = homeBySlug($key);
}
$path = $home === null ? '' : homeEpgGzPath((string) $home['token']);
if ($home === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This house guide has not been published yet.\n";
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: inline; filename="epg.xml.gz"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
readfile($path);
