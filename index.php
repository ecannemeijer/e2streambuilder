<?php

require_once __DIR__ . '/epg.lib.php';

authStart();

$formError = null;
$homeError = null;
$authError = null;
$authDialog = '';
$saved = isset($_GET['saved']);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    try {
        authCsrfCheck();
        authLogout();
        header('Location: index.php');
        exit;
    } catch (InvalidArgumentException $e) {
        authFail('login', $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    try {
        authCsrfCheck();
        authLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        session_write_close();
        header('Location: index.php');
        exit;
    } catch (InvalidArgumentException $e) {
        authFail('login', $e->getMessage(), (string) ($_POST['username'] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    try {
        authCsrfCheck();
        authRegister((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        session_write_close();
        header('Location: index.php');
        exit;
    } catch (InvalidArgumentException $e) {
        authFail('register', $e->getMessage(), (string) ($_POST['username'] ?? ''));
    }
}

$flash = authFlashTake();
if ($flash['message'] !== '') {
    $authError = $flash['message'];
    $authDialog = $flash['dialog'];
}
$authUsername = $flash['username'];

$account = authUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_home'])) {
    try {
        authCsrfCheck();
        if ($account === null) {
            throw new InvalidArgumentException('Log in to add a house.');
        }
        $created = homeCreate((string) ($_POST['house_name'] ?? ''), (int) ($_POST['house_stream_port'] ?? 8001), $account['id']);
        header('Location: index.php?house=' . rawurlencode((string) $created['token']));
        exit;
    } catch (InvalidArgumentException $e) {
        $homeError = $e->getMessage();
    } catch (Throwable $e) {
        $homeError = $e->getMessage();
    }
}

$publishError = null;
$publishedCount = null;
if (isset($_GET['published']) && ctype_digit((string) $_GET['published'])) {
    $publishedCount = (int) $_GET['published'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_home'])) {
    try {
        authCsrfCheck();
        if ($account === null) {
            throw new InvalidArgumentException('Log in to publish a house playlist.');
        }
        @set_time_limit(0);
        $published = homePublishFromReceiver((string) ($_POST['token'] ?? ''), $account['id']);
        header('Location: index.php?house=' . rawurlencode($published['token']) . '&published=' . $published['channels']);
        exit;
    } catch (InvalidArgumentException $e) {
        $publishError = $e->getMessage();
    } catch (Throwable $e) {
        $publishError = $e->getMessage();
    } finally {
        unset($GLOBALS['receiver_override']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_home'])) {
    try {
        authCsrfCheck();
        if ($account !== null) {
            homeDelete((string) ($_POST['token'] ?? ''), $account['id']);
        }
        header('Location: index.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $homeError = $e->getMessage();
    }
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
    $homes = $account === null ? [] : homeList($account['id']);
} catch (Throwable $e) {
    if ($homeError === null) {
        $homeError = $e->getMessage();
    }
}
$requestedHouse = (string) ($_GET['house'] ?? '');
$activeHouse = '';
foreach ($homes as $house) {
    if ((string) $house['token'] === $requestedHouse) {
        $activeHouse = $requestedHouse;
        break;
    }
}
if ($activeHouse === '' && $homes !== []) {
    $activeHouse = (string) $homes[0]['token'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=11">
    <?php appShellStyle(); ?>
</head>
<body>
<?php appChrome(); ?>
<div id="receiver" class="<?= $formError !== null || $saved ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="receiver-title">
        <h2 id="receiver-title">Receiver</h2>
        <p>The fixed playlists on this server use this address.</p>
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
                    Save, then test the connection.
                <?php endif; ?>
            </p>
        </form>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<div id="login" class="<?= $authDialog === 'login' ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="login-title">
        <h2 id="login-title">Log in</h2>
        <p>Your houses stay on this account. Other accounts cannot see them.</p>
        <?php if ($authDialog === 'login' && $authError !== null): ?>
            <p class="error"><?= h($authError) ?></p>
        <?php endif; ?>
        <form class="stack" method="post" action="index.php">
            <?= authCsrfField() ?>
            <input type="hidden" name="login" value="1">
            <label class="field"><span>Username</span>
                <input name="username" maxlength="40" autocomplete="username" value="<?= h($authDialog === 'login' ? $authUsername : '') ?>" required>
            </label>
            <label class="field"><span>Password</span>
                <input name="password" type="password" maxlength="200" autocomplete="current-password" required<?= $authDialog === 'login' && $authError !== null ? ' autofocus' : '' ?>>
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Log in</button>
                <button class="btn" type="button" id="login-to-register">Create account</button>
            </div>
        </form>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<div id="register" class="<?= $authDialog === 'register' ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="register-title">
        <h2 id="register-title">Create account</h2>
        <p>Choose a username and a password of at least 8 characters.</p>
        <?php if ($authDialog === 'register' && $authError !== null): ?>
            <p class="error"><?= h($authError) ?></p>
        <?php endif; ?>
        <form class="stack" method="post" action="index.php">
            <?= authCsrfField() ?>
            <input type="hidden" name="register" value="1">
            <label class="field"><span>Username</span>
                <input name="username" maxlength="40" autocomplete="username" value="<?= h($authDialog === 'register' ? $authUsername : '') ?>" required>
            </label>
            <label class="field"><span>Password</span>
                <input name="password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required>
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Create account</button>
            </div>
        </form>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<div id="add-house" class="<?= $homeError !== null ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="add-house-title">
        <h2 id="add-house-title">Add house</h2>
        <p>This house is saved on your account. Only you see its playlist address.</p>
        <?php if ($homeError !== null): ?>
            <p class="error"><?= h($homeError) ?></p>
        <?php endif; ?>
        <form class="toolbar" method="post" action="index.php">
            <?= authCsrfField() ?>
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
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<div class="app homes">
    <header class="menubar">
        <a class="brand compact" href="index.php">
            <span class="mark" aria-hidden="true"></span>
            <strong>E2 Stream Builder</strong>
        </a>
        <?php appNav('playlist'); ?>
        <?php if ($account === null): ?>
            <button class="nav-btn" type="button" id="login-open">Log in</button>
            <button class="nav-btn" type="button" id="register-open">Create account</button>
        <?php else: ?>
            <label class="house-switch">
                <span>You are</span>
                <select id="house-pick">
                    <?php if ($homes === []): ?>
                        <option value="">No house</option>
                    <?php endif; ?>
                    <?php foreach ($homes as $house): ?>
                        <option value="<?= h((string) $house['token']) ?>"<?= (string) $house['token'] === $activeHouse ? ' selected' : '' ?>><?= h((string) $house['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="nav-btn" type="button" id="add-house-open">Add house</button>
            <form method="post" action="index.php">
                <?= authCsrfField() ?>
                <input type="hidden" name="logout" value="1">
                <button class="nav-btn" type="submit">Log out</button>
            </form>
        <?php endif; ?>
        <button class="nav-btn" type="button" id="receiver-open">Receiver</button>
        <div class="status" id="status"><i></i><span>Checking connection…</span></div>
    </header>

    <div class="housebars">
        <?php if ($authError !== null): ?>
            <p class="error"><?= h($authError) ?></p>
        <?php elseif ($account === null): ?>
            <p class="note">Log in to see your houses. Create an account if you do not have one.</p>
        <?php elseif ($homes === []): ?>
            <p class="note">Add a house. The menu then shows that name next to “You are”.</p>
        <?php endif; ?>
        <?php if ($publishError !== null): ?>
            <p class="error"><?= h($publishError) ?></p>
        <?php elseif ($publishedCount !== null): ?>
            <p class="oknote">Playlist published. <?= (int) $publishedCount ?> channels.</p>
        <?php endif; ?>
        <?php foreach ($homes as $house): ?>
            <?php
            $token = (string) $house['token'];
            $playlistUrl = $base . '/u/' . rawurlencode((string) $house['slug']) . '/channels.m3u8';
            $bookmark = homeBookmarklet($token);
            ?>
            <div class="housebar" data-house="<?= h($token) ?>"<?= $token === $activeHouse ? '' : ' hidden' ?>>
                <p class="you">You are <?= h((string) $house['name']) ?></p>
                <p class="meta">port <?= (int) $house['stream_port'] ?><?= $house['host'] !== '' ? ' · ' . h((string) $house['host']) : '' ?></p>
                <p class="url slim" title="<?= h($playlistUrl) ?>"><?= h($playlistUrl) ?></p>
                <button class="btn" type="button" data-copy="<?= h($playlistUrl) ?>">Copy playlist</button>
                <button class="btn" type="button" data-copy="<?= h($media['epg']) ?>">Copy guide</button>
                <form method="post" action="index.php" class="slow">
                    <?= authCsrfField() ?>
                    <input type="hidden" name="publish_home" value="1">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <button class="btn" type="submit">Publish playlist</button>
                </form>
                <button class="btn" type="button" data-copy="<?= h($bookmark) ?>">Copy bookmark</button>
                <form method="post" action="index.php" onsubmit="return confirm('Remove this house?');">
                    <?= authCsrfField() ?>
                    <input type="hidden" name="delete_home" value="1">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <button class="btn" type="submit">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

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
<script>
(function () {
    var pick = document.getElementById('house-pick');
    var bars = document.querySelectorAll('.housebar');
    if (!pick || !bars.length) return;
    var key = 'e2-house';
    var params = new URLSearchParams(location.search);
    var stored = '';
    try { stored = localStorage.getItem(key) || ''; } catch (e) {}
    function show(token) {
        var found = false;
        bars.forEach(function (bar) {
            var on = bar.getAttribute('data-house') === token;
            bar.hidden = !on;
            if (on) found = true;
        });
        if (!found && pick.options.length) {
            token = pick.options[0].value;
            bars.forEach(function (bar) {
                bar.hidden = bar.getAttribute('data-house') !== token;
            });
        }
        if (token) pick.value = token;
        try { if (token) localStorage.setItem(key, token); } catch (e) {}
    }
    show(params.get('house') || stored || pick.value);
    pick.addEventListener('change', function () { show(pick.value); });
})();
</script>
<script src="assets/app.js?v=4"></script>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
