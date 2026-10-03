<?php

require_once __DIR__ . '/epg.lib.php';

header('Content-Type: application/json; charset=utf-8');

authStart();
if (!authIsAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only an admin can see the receiver.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['host'])) {
    try {
        $GLOBALS['receiver_override'] = normalizeReceiverSettings([
            'host' => $_GET['host'],
            'webif_port' => $_GET['webif_port'] ?? 80,
            'stream_port' => $_GET['stream_port'] ?? 8001,
        ]);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $info = webifJson('/api/statusinfo');
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => authHiddenError($e)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'station' => (string) ($info['currservice_station'] ?? ''),
    'standby' => ($info['inStandby'] ?? 'false') === 'true' || ($info['inStandby'] ?? false) === true,
], JSON_UNESCAPED_UNICODE);
