<?php

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$ref = $_GET['ref'] ?? '';
if (!is_string($ref) || $ref === '' || strlen($ref) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a bouquet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $channels = channelsForBouquet($ref);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['channels' => $channels], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
