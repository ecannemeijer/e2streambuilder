<?php

require_once __DIR__ . '/epg.lib.php';

authRequireAdmin();

$notice = null;
$error = null;

$progress = $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['progress'] ?? '') === '1';
if ($progress) {
    epgProgressBegin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            saveEpgSettings($_POST);
            $notice = 'EPG settings saved.';
        } elseif ($action === 'add_source') {
            epgAddSource($_POST);
            $notice = 'Source added.';
        } elseif ($action === 'save_source') {
            epgUpdateSource((int) ($_POST['id'] ?? 0), $_POST);
            $notice = 'Source saved.';
        } elseif ($action === 'delete_source') {
            epgDeleteSource((int) ($_POST['id'] ?? 0));
            $notice = 'Source removed.';
        } elseif ($action === 'toggle_source') {
            $source = epgSourceById((int) ($_POST['id'] ?? 0));
            epgSetSourceEnabled((int) $source['id'], (int) $source['enabled'] !== 1);
            $notice = ((int) $source['enabled'] === 1) ? 'Source disabled.' : 'Source enabled.';
        } elseif ($action === 'download_source') {
            @set_time_limit(0);
            $id = (int) ($_POST['id'] ?? 0);
            $counts = epgWithLock(static function () use ($id): array {
                $counts = epgRefreshSource(epgSourceById($id));
                try {
                    epgRematchAll();
                    epgGenerateXml();
                } catch (Throwable $e) {
                    $counts['followup'] = $e->getMessage();
                }

                return $counts;
            });
            $notice = 'Download finished: ' . epgFormatNumber((int) $counts['channels']) . ' channels, ' . epgFormatNumber((int) $counts['programmes']) . ' programmes.';
            if (!empty($counts['followup'])) {
                $error = 'The source was saved, but the combined guide could not be built: ' . $counts['followup'];
            }
        } elseif ($action === 'test_source') {
            @set_time_limit(0);
            $counts = epgTestSource((int) ($_POST['id'] ?? 0));
            $notice = 'Test passed: ' . epgFormatNumber((int) $counts['channels']) . ' channels, ' . epgFormatNumber((int) $counts['programmes']) . ' programmes. The saved guide was not replaced.';
        } elseif ($action === 'refresh') {
            @set_time_limit(0);
            $result = epgRunUpdate('manual', true);
            if (!empty($result['skipped'])) {
                $notice = 'An update is already running.';
            } else {
                $notice = 'EPG updated. Automatic matches: ' . epgFormatNumber((int) ($result['matched'] ?? 0))
                    . '. Unmatched: ' . epgFormatNumber((int) ($result['unmatched'] ?? 0)) . '.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($progress) {
    epgStateSet('ui_notice', (string) ($notice ?? ''));
    epgStateSet('ui_error', (string) ($error ?? ''));
    epgProgressEmit($error !== null && $error !== '' ? $error : (string) ($notice ?? 'Finished.'), true, $error !== null && $error !== '');
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

$settings = epgSettings();
$sources = epgListSources();
$dashboard = epgDashboard();
$media = mediaUrls();
$log = epgTailLog(30);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    epgKickUpdateIfDue();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EPG · E2 Stream Builder</title>
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
                <h1>EPG</h1>
                <p class="lede">Download Rytec XMLTV, match it to your channels, and serve the guide to TiviMate.</p>
            </div>
        </div>
    </header>
    <?php appNav('epg'); ?>

    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>
    <?php if ($notice !== null): ?>
        <p class="oknote"><?= h($notice) ?></p>
    <?php endif; ?>

    <section class="panel stack">
        <h2>EPG status</h2>
        <?php if ($dashboard['warning'] !== null): ?>
            <p class="error"><?= h($dashboard['warning']) ?></p>
        <?php endif; ?>
        <div class="stats">
            <div><span>Channels in playlist</span><strong><?= $dashboard['playlist'] === null ? '—' : epgFormatNumber((int) $dashboard['playlist']) ?></strong></div>
            <div><span>Matched automatically</span><strong><?= epgFormatNumber((int) $dashboard['matched']) ?></strong></div>
            <div><span>Matched manually</span><strong><?= epgFormatNumber((int) $dashboard['manual']) ?></strong></div>
            <div><span>Unmatched</span><strong><a class="slow" href="epg-mapping.php?status=UNMATCHED"><?= epgFormatNumber((int) $dashboard['unmatched']) ?></a></strong></div>
            <div><span>Conflicts</span><strong><a class="slow" href="epg-mapping.php?status=CONFLICT"><?= epgFormatNumber((int) $dashboard['conflicts']) ?></a></strong></div>
            <div><span>EPG programmes</span><strong><?= epgFormatNumber((int) $dashboard['programmes']) ?></strong></div>
            <div><span>Last update</span><strong><?= h($dashboard['last_update']) ?></strong></div>
            <div><span>Next update</span><strong><?= h($dashboard['next_update']) ?></strong></div>
        </div>
        <p class="meta">Channel mapping file: <?= epgFormatNumber((int) $dashboard['channels_count']) ?> service references, updated <?= h($dashboard['channels_updated']) ?>.</p>
        <?php if ($dashboard['channels_error'] !== ''): ?>
            <p class="error"><?= h($dashboard['channels_error']) ?></p>
        <?php endif; ?>
        <?php if ($dashboard['generated_error'] !== ''): ?>
            <p class="error"><?= h($dashboard['generated_error']) ?></p>
        <?php endif; ?>
        <div class="urlbox">
            <p class="meta">M3U URL</p>
            <p class="url"><?= h($media['m3u']) ?></p>
            <button class="btn" type="button" data-copy="<?= h($media['m3u']) ?>">Copy</button>
            <p class="meta">EPG URL</p>
            <p class="url"><?= h($media['epg']) ?></p>
            <button class="btn" type="button" data-copy="<?= h($media['epg']) ?>">Copy</button>
        </div>
        <form method="post" class="actions">
            <button class="btn primary" name="action" value="refresh">Download all</button>
        </form>
        <p class="note">Download all fetches every enabled source, matches your channels, and rebuilds the guide. A button on one country fetches only that source. The guide also refreshes once per interval when the playlist or EPG is requested. For a fixed time, schedule <code>epg-update.bat</code>.</p>
    </section>

    <section class="panel stack">
        <h2>Settings</h2>
        <form method="post" class="toolbar">
            <input type="hidden" name="action" value="save_settings">
            <label class="field"><span>Update interval (hours)</span>
                <input name="epg_interval_hours" type="number" min="1" max="168" value="<?= (int) $settings['epg_interval_hours'] ?>" required>
            </label>
            <label class="field"><span>Fuzzy threshold</span>
                <input name="epg_fuzzy_threshold" type="number" min="0.5" max="1" step="0.01" value="<?= h(number_format((float) $settings['epg_fuzzy_threshold'], 2, '.', '')) ?>" required>
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Save</button>
            </div>
        </form>
    </section>

    <section class="panel stack">
        <h2>Add a source</h2>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="add_source">
            <label class="field wide"><span>Name</span><input name="name" required maxlength="120"></label>
            <label class="field wide"><span>URL</span><input name="url" required inputmode="url" placeholder="https://example/epg.xml.gz"></label>
            <label class="field wide"><span>Fallback mirrors, one per line</span><textarea name="mirrors" rows="3"></textarea></label>
            <label class="field"><span>Priority</span><input name="priority" type="number" min="-1000" max="1000" value="100"></label>
            <label class="check"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
            <button class="btn primary" type="submit">Add</button>
        </form>
    </section>

    <section class="panel stack">
        <h2>Sources</h2>
        <p class="note"><strong>Download this source</strong> fetches only that file, then rebuilds the combined guide. <strong>Download all</strong> fetches every enabled source.</p>
        <form method="post" class="actions">
            <button class="btn primary" name="action" value="refresh">Download all</button>
        </form>
    </section>

    <?php foreach ($sources as $source): ?>
        <?php $mirrors = implode("\n", epgDecodeMirrors($source)); ?>
        <section class="panel stack">
            <form method="post" class="stack">
                <input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
                <div class="source-head">
                    <h2><?= h((string) $source['name']) ?></h2>
                    <span class="badge <?= h((string) $source['status']) ?>"><?= h((string) $source['status']) ?></span>
                </div>
                <label class="field wide"><span>Name</span><input name="name" required maxlength="120" value="<?= h((string) $source['name']) ?>"></label>
                <label class="field wide"><span>URL</span><input name="url" required value="<?= h((string) $source['url']) ?>"></label>
                <label class="field wide"><span>Fallback mirrors</span><textarea name="mirrors" rows="3"><?= h($mirrors) ?></textarea></label>
                <label class="field"><span>Priority</span><input name="priority" type="number" min="-1000" max="1000" value="<?= (int) $source['priority'] ?>"></label>
                <label class="check"><input type="checkbox" name="enabled" value="1"<?= (int) $source['enabled'] === 1 ? ' checked' : '' ?>> Enabled</label>
                <p class="meta">
                    Last success: <?= h(epgFormatTime($source['last_success_at'] !== null ? (string) $source['last_success_at'] : null)) ?>
                    · Size: <?= h(epgFormatBytes($source['file_size'] !== null ? (int) $source['file_size'] : null)) ?>
                    · Channels: <?= $source['channel_count'] === null ? '—' : epgFormatNumber((int) $source['channel_count']) ?>
                    · Programmes: <?= $source['programme_count'] === null ? '—' : epgFormatNumber((int) $source['programme_count']) ?>
                </p>
                <?php if (!empty($source['error'])): ?>
                    <p class="error"><?= h((string) $source['error']) ?></p>
                <?php endif; ?>
                <div class="actions">
                    <button class="btn primary" name="action" value="save_source">Save</button>
                    <button class="btn" name="action" value="download_source">Download this source</button>
                    <button class="btn" name="action" value="test_source">Test</button>
                    <button class="btn" name="action" value="toggle_source"><?= (int) $source['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
                    <button class="btn" name="action" value="delete_source" onclick="return confirm('Remove this source?')">Remove</button>
                </div>
            </form>
        </section>
    <?php endforeach; ?>

    <?php if ($log !== ''): ?>
        <section class="panel stack">
            <h2>Log</h2>
            <pre class="log"><?= h($log) ?></pre>
        </section>
    <?php endif; ?>
</div>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
