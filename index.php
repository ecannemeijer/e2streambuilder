<?php

require_once __DIR__ . '/epg.lib.php';

$formError = null;
$homeError = null;
$saved = isset($_GET['saved']);
$homeSaved = isset($_GET['home']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        saveReceiverSettings($_POST);
        header('Location: index.php?saved=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $formError = $e->getMessage();
    } catch (Throwable $e) {
        $formError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_home'])) {
    try {
        homeCreate((string) ($_POST['house_name'] ?? ''), (int) ($_POST['house_stream_port'] ?? 8001));
        header('Location: index.php?home=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $homeError = $e->getMessage();
    } catch (Throwable $e) {
        $homeError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_home'])) {
    homeDelete((string) ($_POST['token'] ?? ''));
    header('Location: index.php');
    exit;
}

$settings = receiverSettings();
$error = null;
$bouquets = [];

try {
    $bouquets = fetchBouquets();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$base = appBaseUrl();
$links = [
    'All channels' => $base . '/playlist.php',
    'Streams only' => $base . '/playlist.php?type=stream',
    'Satellite only' => $base . '/playlist.php?type=tv',
];
$media = mediaUrls();
$homes = [];
try {
    $homes = homeList();
} catch (Throwable $e) {
    if ($homeError === null) {
        $homeError = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=8">
    <?php appShellStyle(); ?>
</head>
<body>
<?php appChrome(); ?>
<div class="app homes">
    <header class="top">
        <div class="brand">
            <div class="mark" aria-hidden="true"></div>
            <div>
                <p class="eyebrow">Enigma2 channel list</p>
                <h1>E2 Stream Builder</h1>
                <p class="lede">Stream channels keep their own address in the playlist. Satellite channels stay a TS stream from the receiver. Click a stream to play it here.</p>
            </div>
        </div>
        <div class="status" id="status"><i></i><span>Checking connection…</span></div>
    </header>
    <?php appNav('playlist'); ?>

    <form class="toolbar" method="post" action="index.php">
        <input type="hidden" name="save_settings" value="1">
        <label class="field"><span>IP address</span>
            <input name="host" value="<?= h($settings['host']) ?>" inputmode="decimal" autocomplete="off" required>
        </label>
        <label class="field port"><span>WebIF port</span>
            <input name="webif_port" type="number" min="1" max="65535" value="<?= (int) $settings['webif_port'] ?>" required>
        </label>
        <label class="field port"><span>Stream port</span>
            <input name="stream_port" type="number" min="1" max="65535" value="<?= (int) $settings['stream_port'] ?>" required>
        </label>
        <div class="actions">
            <button class="btn primary" type="submit">Save</button>
            <button class="btn" type="button" id="test">Test connection</button>
        </div>
        <p class="<?= $formError !== null ? 'error' : ($saved ? 'oknote' : 'note') ?>" id="test-result">
            <?php if ($formError !== null): ?>
                <?= h($formError) ?>
            <?php elseif ($saved): ?>
                Settings saved.
            <?php else: ?>
                The fixed playlists use this address.
            <?php endif; ?>
        </p>
    </form>

    <section class="panel stack">
        <h2>Houses</h2>
        <p class="note">Each house gets its own playlist. Build it at home: open the receiver web page, then use that house’s bookmark. The stream addresses in the file use the receiver you opened. TiviMate at home loads the playlist and the shared guide from this site.</p>
        <?php if ($homeError !== null): ?>
            <p class="error"><?= h($homeError) ?></p>
        <?php elseif ($homeSaved): ?>
            <p class="oknote">House added. Drag its bookmark onto the bookmark bar, then open it on the receiver web page.</p>
        <?php endif; ?>
        <form class="toolbar" method="post" action="index.php">
            <input type="hidden" name="create_home" value="1">
            <label class="field"><span>House name</span>
                <input name="house_name" maxlength="80" required>
            </label>
            <label class="field port"><span>Stream port</span>
                <input name="house_stream_port" type="number" min="1" max="65535" value="8001" required>
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Add house</button>
            </div>
        </form>
        <?php foreach ($homes as $house): ?>
            <?php
            $token = (string) $house['token'];
            $playlistUrl = $base . '/u/' . $token . '/channels.m3u8';
            $bookmark = homeBookmarklet($token);
            ?>
            <div class="urlbox">
                <p class="meta"><?= h((string) $house['name']) ?> · stream port <?= (int) $house['stream_port'] ?><?= $house['host'] !== '' ? ' · ' . h((string) $house['host']) : '' ?><?= $house['built_at'] !== null && $house['built_at'] !== '' ? ' · built ' . h((string) $house['built_at']) : '' ?></p>
                <p class="meta">Playlist</p>
                <p class="url"><?= h($playlistUrl) ?></p>
                <button class="btn" type="button" data-copy="<?= h($playlistUrl) ?>">Copy</button>
                <p class="meta">Guide</p>
                <p class="url"><?= h($media['epg']) ?></p>
                <button class="btn" type="button" data-copy="<?= h($media['epg']) ?>">Copy</button>
                <p class="meta">Bookmark for the receiver web page</p>
                <p><a class="btn" href="<?= h($bookmark) ?>">Publish playlist</a></p>
                <button class="btn" type="button" data-copy="<?= h($bookmark) ?>">Copy bookmark</button>
                <form method="post" action="index.php">
                    <input type="hidden" name="delete_home" value="1">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <button class="btn" type="submit">Remove house</button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="workspace">
        <section class="panel">
            <h2>Bouquets</h2>
            <?php if ($error !== null): ?>
                <p class="error"><?= h($error) ?></p>
            <?php else: ?>
                <form id="export" method="post" action="playlist.php">
                    <input type="hidden" name="filter" value="1">
                    <input type="hidden" name="download" value="1">
                    <div class="tools">
                        <input class="search" id="q" type="search" placeholder="Search bouquets" autocomplete="off">
                        <div class="filters">
                            <button class="btn" type="button" id="all">All</button>
                            <button class="btn" type="button" id="none">None</button>
                            <button class="btn" type="button" id="streams">Streams only</button>
                            <button class="btn" type="button" id="other">Other only</button>
                        </div>
                    </div>
                    <p class="meta" id="bq-count"></p>
                    <div class="list" id="bouquet-list">
                        <?php foreach ($bouquets as $bouquet): ?>
                            <div class="bq" data-name="<?= h(mb_strtolower($bouquet['name'], 'UTF-8')) ?>" data-stream="<?= $bouquet['stream'] ? '1' : '0' ?>">
                                <input type="checkbox" name="bouquet[]" value="<?= h($bouquet['ref']) ?>" checked>
                                <button class="bq-open" type="button" data-ref="<?= h($bouquet['ref']) ?>"><?= h($bouquet['name']) ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="export">
                        <select class="select" name="type">
                            <option value="all">Selection: streams and satellite</option>
                            <option value="stream">Selection: streams only</option>
                            <option value="tv">Selection: satellite only</option>
                        </select>
                        <button class="btn primary" type="submit">Download e2.m3u8</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Channels</h2>
            <div class="tools">
                <input class="search" id="channel-q" type="search" placeholder="Search channels" autocomplete="off">
                <label class="check"><input type="checkbox" id="only-streams"> Streams only</label>
            </div>
            <p class="meta" id="channel-meta">Choose a bouquet on the left.</p>
            <div class="list" id="channel-list">
                <p class="empty">No bouquet selected yet.</p>
            </div>
        </section>

        <section class="panel player">
            <h2>Playback</h2>
            <div class="stage">
                <video id="screen" controls playsinline></video>
                <div class="placeholder" id="placeholder">Choose a stream channel to test.</div>
            </div>
            <div class="now">
                <h3 id="now-name">No channel</h3>
                <p class="note" id="now-state">Streams play here in the browser. Open RTMP and RTSP in VLC.</p>
                <p class="url" id="now-url" hidden></p>
            </div>
            <div class="urlbox">
                <p class="meta">M3U URL</p>
                <p class="url"><?= h($media['m3u']) ?></p>
                <button class="btn" type="button" data-copy="<?= h($media['m3u']) ?>">Copy</button>
                <p class="meta">EPG URL</p>
                <p class="url"><?= h($media['epg']) ?></p>
                <button class="btn" type="button" data-copy="<?= h($media['epg']) ?>">Copy</button>
            </div>
            <ul class="links">
                <?php foreach ($links as $label => $url): ?>
                    <li><?= h($label) ?>: <a href="<?= h($url) ?>"><?= h($url) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
<script src="assets/app.js?v=4"></script>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
