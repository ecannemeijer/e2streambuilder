<?php

require_once __DIR__ . '/epg.lib.php';

$gz = isset($_GET['gz']);
$path = $gz ? epgGzPath() : epgXmlPath();
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The EPG has not been generated yet.\n";
    epgKickUpdateIfDue();
    exit;
}

header('Content-Type: ' . ($gz ? 'application/octet-stream' : 'application/xml; charset=utf-8'));
header('Content-Disposition: inline; filename="' . ($gz ? 'epg.xml.gz' : 'epg.xml') . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
epgKickUpdateIfDue();
readfile($path);
