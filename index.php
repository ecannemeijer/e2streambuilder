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
        $savedSettings = saveReceiverSettings($_POST);
        $actor = authUser();
        $savedToken = (string) ($_POST['token'] ?? '');
        if ($actor !== null && homeTokenOk($savedToken)) {
            epgDb()->prepare('UPDATE homes SET host = ?, stream_port = ? WHERE token = ? AND user_id = ?')
                ->execute([$savedSettings['host'], $savedSettings['stream_port'], $savedToken, $actor['id']]);
        }
        $back = 'index.php?saved=1';
        if (homeTokenOk($savedToken)) {
            $back .= '&house=' . rawurlencode($savedToken);
        }
        header('Location: ' . $back);
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
$mailNote = null;
if (isset($_SESSION['mail_note'])) {
    $mailNote = (string) $_SESSION['mail_note'];
    unset($_SESSION['mail_note']);
}

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

$settings = $account === null ? null : receiverSettings();
$error = null;
$bouquets = [];

if ($account !== null) {
    try {
        $bouquets = fetchBouquets();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
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
    <link rel="stylesheet" href="assets/app.css?v=17">
    <?php appShellStyle(); ?>
</head>
<body>
<?php appChrome(); ?>
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
            <label class="field"><span>Email</span>
                <input name="username" maxlength="254" autocomplete="username" value="<?= h($authDialog === 'login' ? $authUsername : '') ?>" required>
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
        <p>Use your email address and a password of at least 8 characters. A welcome message is sent to that address.</p>
        <?php if ($authDialog === 'register' && $authError !== null): ?>
            <p class="error"><?= h($authError) ?></p>
        <?php endif; ?>
        <form class="stack" method="post" action="index.php">
            <?= authCsrfField() ?>
            <input type="hidden" name="register" value="1">
            <label class="field"><span>Email</span>
                <input name="username" type="email" maxlength="254" autocomplete="email" value="<?= h($authDialog === 'register' ? $authUsername : '') ?>" required>
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
<div id="add-house" class="<?= ($homeError !== null || ($account !== null && $homes === [])) ? 'is-open' : '' ?><?= ($account !== null && $homes === []) ? ' is-required' : '' ?>">
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
        <?php if ($account === null || $homes !== []): ?>
            <button class="btn" type="button" data-close>Close</button>
        <?php endif; ?>
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
    </header>

    <div class="housebars">
        <?php if ($authError !== null): ?>
            <p class="error"><?= h($authError) ?></p>
        <?php elseif ($account !== null && $homes === []): ?>
            <p class="note">Add a house. The menu then shows that name next to “You are”.</p>
        <?php endif; ?>
        <?php if ($mailNote !== null): ?>
            <p class="<?= str_contains($mailNote, 'could not') ? 'error' : 'oknote' ?>"><?= h($mailNote) ?></p>
        <?php endif; ?>
        <?php if ($formError !== null): ?>
            <p class="error"><?= h($formError) ?></p>
        <?php elseif ($saved): ?>
            <p class="oknote">Receiver saved.</p>
        <?php endif; ?>
        <?php if ($publishError !== null): ?>
            <p class="error"><?= h($publishError) ?></p>
        <?php elseif ($publishedCount !== null): ?>
            <p class="oknote">Playlist and guide published. <?= (int) $publishedCount ?> channels.</p>
        <?php endif; ?>
        <?php foreach ($homes as $house): ?>
            <?php
            $token = (string) $house['token'];
            $slug = (string) $house['slug'];
            $playlistUrl = homeChannelsUrl($slug);
            $guideUrl = homeGuideUrl($slug);
            $receiverHost = (string) $house['host'] !== '' ? (string) $house['host'] : (string) $settings['host'];
            ?>
            <div class="housebar" data-house="<?= h($token) ?>"<?= $token === $activeHouse ? '' : ' hidden' ?>>
                <p class="you">You are <?= h((string) $house['name']) ?></p>
                <div class="house-links">
                    <div class="house-link">
                        <span>Channels</span>
                        <p class="url slim" title="<?= h($playlistUrl) ?>"><?= h($playlistUrl) ?></p>
                        <button class="btn" type="button" data-copy="<?= h($playlistUrl) ?>">Copy</button>
                    </div>
                    <div class="house-link">
                        <span>Guide</span>
                        <p class="url slim" title="<?= h($guideUrl) ?>"><?= h($guideUrl) ?></p>
                        <button class="btn" type="button" data-copy="<?= h($guideUrl) ?>">Copy</button>
                    </div>
                </div>
                <div class="house-actions">
                    <form class="receiver-inline" method="post" action="index.php">
                        <input type="hidden" name="save_settings" value="1">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <label class="field"><span>Receiver</span>
                            <input name="host" value="<?= h($receiverHost) ?>" inputmode="decimal" autocomplete="off" required>
                        </label>
                        <label class="field port"><span>WebIF</span>
                            <input name="webif_port" type="number" min="1" max="65535" value="<?= (int) $settings['webif_port'] ?>" required>
                        </label>
                        <label class="field port"><span>Stream</span>
                            <input name="stream_port" type="number" min="1" max="65535" value="<?= (int) $house['stream_port'] ?>" required>
                        </label>
                        <button class="btn" type="submit">Save</button>
                    </form>
                    <div class="status"><i></i><span>Checking connection…</span></div>
                    <form method="post" action="index.php" class="slow">
                        <?= authCsrfField() ?>
                        <input type="hidden" name="publish_home" value="1">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <button class="btn" type="submit">Publish playlist</button>
                    </form>
                    <form method="post" action="index.php" onsubmit="return confirm('Remove this house?');">
                        <?= authCsrfField() ?>
                        <input type="hidden" name="delete_home" value="1">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <button class="btn" type="submit">Remove</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($account === null): ?>
    <section class="intro">
        <div class="intro-hero">
            <p class="eyebrow">Enigma2 for IPTV</p>
            <h1>Your channels, ready for an IPTV player.</h1>
            <p class="lead">E2 Stream Builder connects to your Enigma2 receiver through OpenWebIF and reads all the bouquets and channels. It turns them into IPTV categories. For each house it places a personal channels.m3u8 on this server, ready to open in an IPTV player. Playback goes straight to the receiver, with a programme guide for the same list.</p>
            <div class="intro-actions">
                <button class="btn primary" type="button" data-open="register">Create account</button>
                <button class="btn" type="button" data-open="login">Log in</button>
            </div>
        </div>
        <div class="intro-grid">
            <article>
                <span class="step">1</span>
                <h2>Account</h2>
                <p>Create an account with your email address. You log in with it, and the links are sent there.</p>
            </article>
            <article>
                <span class="step">2</span>
                <h2>Categories</h2>
                <p>OpenWebIF supplies every bouquet and channel. Each bouquet becomes an IPTV category, with its channels kept together in the same order as on the receiver.</p>
            </article>
            <article>
                <span class="step">3</span>
                <h2>Your file</h2>
                <p>Publish playlist writes a personal channels.m3u8 on this server and keeps it with your house. The guide is stored beside it.</p>
            </article>
            <article>
                <span class="step">4</span>
                <h2>Watch</h2>
                <p>Paste the channel address into an IPTV player. The categories and the programme guide come with that playlist.</p>
            </article>
        </div>
    </section>
    <?php else: ?>
    <div class="workspace<?= authIsAdmin($account) ? '' : ' two' ?>">
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

        <?php if (authIsAdmin($account)): ?>
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
        <?php endif; ?>
    </div>
    <?php endif; ?>
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
<script src="assets/app.js?v=7"></script>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
