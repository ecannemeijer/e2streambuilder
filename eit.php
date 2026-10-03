<?php

require_once __DIR__ . '/epg.lib.php';

$notice = null;
$error = null;
$progress = $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['progress'] ?? '') === '1';
if ($progress) {
    epgProgressBegin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'build_eit') {
    @set_time_limit(0);
    try {
        $result = epgWithLock(static function (): array {
            return eitGenerateToday();
        });
        $notice = 'EIT guide created. Channels: ' . epgFormatNumber((int) $result['channels'])
            . '. Programmes: ' . epgFormatNumber((int) $result['programmes']) . '.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($progress) {
    epgStateSet('ui_notice', (string) ($notice ?? ''));
    epgStateSet('ui_error', (string) ($error ?? ''));
    epgProgressEmit(
        $error !== null && $error !== '' ? $error : (string) ($notice ?? 'Finished.'),
        true,
        $error !== null && $error !== '',
        'eit.php'
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    header('Location: eit.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $flash = epgTakeFlash();
    if ($notice === null && $flash['notice'] !== null) {
        $notice = $flash['notice'];
    }
    if ($error === null && $flash['error'] !== null) {
        $error = $flash['error'];
    }
}

$media = mediaUrls();
$built = epgStateGet('eit_generated_at') ?? '';
$channels = epgStateGet('eit_channels');
$programmes = epgStateGet('eit_programmes');
$eitError = epgStateGet('eit_error') ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EIT · E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=11">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <header class="top">
        <div class="brand">
            <div class="mark" aria-hidden="true"></div>
            <div>
                <p class="eyebrow">E2 Stream Builder</p>
                <h1>EIT</h1>
                <p class="lede">Build a guide for today from the programme cache on the receiver. The Rytec guide stays available on its own address.</p>
            </div>
        </div>
    </header>
    <?php appNav('eit'); ?>

    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>
    <?php if ($notice !== null): ?>
        <p class="oknote"><?= h($notice) ?></p>
    <?php endif; ?>
    <?php if ($eitError !== ''): ?>
        <p class="error"><?= h($eitError) ?></p>
    <?php endif; ?>

    <section class="panel stack">
        <h2>Today from the receiver</h2>
        <p class="note">OpenWebif reads the EIT cache. One tuned channel fills that frequency for the other channels on it. SDT only names the channel, and TDT/TOT only sets the clock. A channel whose frequency was never tuned stays without programmes. Matched channels use the same id as the playlist, for example NPO1.nl.</p>
        <div class="stats">
            <div><span>Channels</span><strong><?= $channels === null || $channels === '' ? '—' : epgFormatNumber((int) $channels) ?></strong></div>
            <div><span>Programmes</span><strong><?= $programmes === null || $programmes === '' ? '—' : epgFormatNumber((int) $programmes) ?></strong></div>
            <div><span>Last build</span><strong><?= $built === '' ? '—' : h($built) ?></strong></div>
        </div>
        <div class="urlbox">
            <p class="meta">EIT guide</p>
            <p class="url"><?= h($media['eit']) ?></p>
            <button class="btn" type="button" data-copy="<?= h($media['eit']) ?>">Copy</button>
            <p class="meta">Rytec guide</p>
            <p class="url"><?= h($media['epg']) ?></p>
            <button class="btn" type="button" data-copy="<?= h($media['epg']) ?>">Copy</button>
        </div>
        <form method="post" class="actions">
            <button class="btn primary" name="action" value="build_eit">Build EIT guide</button>
        </form>
    </section>
</div>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
