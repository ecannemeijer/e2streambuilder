<?php

require_once __DIR__ . '/epg.lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Content-Encoding');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 32 * 1024 * 1024 + 1);
if (!is_string($raw) || strlen($raw) > 32 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'message' => 'The channel list is too large.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strtolower(trim((string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? ''))) === 'gzip') {
    $decoded = gzdecode($raw);
    if (!is_string($decoded) || strlen($decoded) > 32 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'The channel list could not be read.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = $decoded;
}

try {
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('The channel list could not be read.');
    }
    $token = (string) ($payload['token'] ?? '');
    $host = trim((string) ($payload['host'] ?? ''));
    $services = $payload['services'] ?? null;
    if (!is_array($services)) {
        throw new InvalidArgumentException('The channel list is missing.');
    }
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $count = homePublishPlaylist($token, $host, $services);
    echo json_encode([
        'ok' => true,
        'channels' => $count,
        'message' => 'Playlist published. ' . $count . ' channels.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => authHiddenError($e)], JSON_UNESCAPED_UNICODE);
}
