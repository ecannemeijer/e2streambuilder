<?php

require_once __DIR__ . '/xtream.lib.php';

$username = isset($_GET['username']) ? (string) $_GET['username'] : '';
$password = isset($_GET['password']) ? (string) $_GET['password'] : '';
if (!xtreamAuthOk($username, $password)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit;
}

$path = epgXmlPath();
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The EPG has not been generated yet.\n";
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
readfile($path);
