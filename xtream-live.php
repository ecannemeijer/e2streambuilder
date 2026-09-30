<?php

require_once __DIR__ . '/xtream.lib.php';

$username = isset($_GET['user']) ? rawurldecode((string) $_GET['user']) : '';
$password = isset($_GET['pass']) ? rawurldecode((string) $_GET['pass']) : '';
if (!xtreamAuthOk($username, $password)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit;
}

$url = xtreamStreamUrl((int) ($_GET['id'] ?? 0));
if ($url === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Stream not found.\n";
    exit;
}

header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
