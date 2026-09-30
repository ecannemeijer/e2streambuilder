<?php

require_once __DIR__ . '/xtream.lib.php';

$username = isset($_GET['username']) ? (string) $_GET['username'] : '';
$password = isset($_GET['password']) ? (string) $_GET['password'] : '';
if (!xtreamAuthOk($username, $password)) {
    xtreamJson(['user_info' => ['auth' => 0, 'status' => 'Disabled']]);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($action === '' || $action === 'user' || $action === 'user_info') {
    xtreamJson([
        'user_info' => xtreamUserInfo(),
        'server_info' => xtreamServerInfo(),
    ]);
    exit;
}
if ($action === 'get_live_categories') {
    xtreamJson(xtreamListCategories());
    exit;
}
if ($action === 'get_live_streams') {
    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    xtreamJson(xtreamListStreams($categoryId));
    exit;
}

xtreamJson([]);
