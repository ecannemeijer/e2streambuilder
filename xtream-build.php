<?php

require_once __DIR__ . '/xtream.lib.php';

$return = basename((string) ($_POST['return'] ?? 'index.php'));
if (!in_array($return, ['index.php', 'epg.php', 'epg-mapping.php'], true)) {
    $return = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['build_xtream'])) {
    header('Location: ' . $return);
    exit;
}

@set_time_limit(0);
$progress = (string) ($_POST['progress'] ?? '') === '1';
if ($progress) {
    epgProgressBegin();
}
try {
    saveXtreamSettings($_POST, true);
    xtreamBuildCatalog();
    if ($progress) {
        epgProgressEmit('Xtream catalog built.', true, false, $return . '?xtream=1');
        exit;
    }
    header('Location: ' . $return . '?xtream=1');
} catch (Throwable $e) {
    try {
        epgStateSet('xtream_build_error', $e->getMessage());
    } catch (Throwable $ignored) {
    }
    if ($progress) {
        epgProgressEmit($e->getMessage(), true, true, $return . '?xtream=1');
        exit;
    }
    header('Location: ' . $return . '?xtream=1');
}
exit;
