<?php

require_once __DIR__ . '/epg.lib.php';

$gz = isset($_GET['gz']);
$eit = isset($_GET['eit']);
$path = $eit ? ($gz ? eitGzPath() : eitXmlPath()) : ($gz ? epgGzPath() : epgXmlPath());
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $eit ? "The EIT guide has not been generated yet.\n" : "The EPG has not been generated yet.\n";
    if (!$eit) {
        epgKickUpdateIfDue();
    }
    exit;
}

$file = ($eit ? 'epg-eit.xml' : 'epg.xml') . ($gz ? '.gz' : '');
header('Content-Type: ' . ($gz ? 'application/octet-stream' : 'application/xml; charset=utf-8'));
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
if (!$eit) {
    epgKickUpdateIfDue();
}
readfile($path);
