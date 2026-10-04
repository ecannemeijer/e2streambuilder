<?php

require_once __DIR__ . '/epg.lib.php';

authStart();

$formError = null;
$homeError = null;
$authError = null;
$authOk = false;
$authBlocked = false;
$authDialog = '';
$openDialog = (string) ($_GET['open'] ?? '');
$saved = isset($_GET['saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        authCsrfCheck();
        $actor = authUser();
        if (!authIsAdmin($actor)) {
            throw new InvalidArgumentException('Only an admin can save the receiver.');
        }
        $savedSettings = saveReceiverSettings($_POST);
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
        $formError = authHiddenError($e);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    try {
        authCsrfCheck();
        authRequestPasswordReset((string) ($_POST['username'] ?? ''));
        authFail('forgot', 'If that account exists, a message was sent with a link to choose a new password.', (string) ($_POST['username'] ?? ''), true);
    } catch (InvalidArgumentException $e) {
        authFail('forgot', $e->getMessage(), (string) ($_POST['username'] ?? ''));
    } catch (Throwable $e) {
        authFail('forgot', authHiddenError($e), (string) ($_POST['username'] ?? ''));
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
    } catch (Throwable $e) {
        authFail('login', authHiddenError($e), (string) ($_POST['username'] ?? ''));
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
    $authOk = $flash['ok'];
    $authBlocked = str_contains($authError, 'blocked');
    if ($authBlocked) {
        $authDialog = '';
    }
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
        $streamPort = authIsAdmin($account) ? (int) ($_POST['house_stream_port'] ?? 8001) : 8001;
        $created = homeCreate((string) ($_POST['house_name'] ?? ''), $streamPort, $account['id']);
        header('Location: index.php?house=' . rawurlencode((string) $created['token']));
        exit;
    } catch (InvalidArgumentException $e) {
        $homeError = $e->getMessage();
    } catch (Throwable $e) {
        $homeError = authHiddenError($e);
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
        if (!authIsAdmin($account)) {
            throw new InvalidArgumentException('Only an admin can publish from this server.');
        }
        @set_time_limit(0);
        $published = homePublishFromReceiver((string) ($_POST['token'] ?? ''), $account['id']);
        header('Location: index.php?house=' . rawurlencode($published['token']) . '&published=' . $published['channels']);
        exit;
    } catch (InvalidArgumentException $e) {
        $publishError = $e->getMessage();
    } catch (Throwable $e) {
        $publishError = authHiddenError($e);
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

$houseError = null;
$houseErrorToken = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_house'])) {
    $houseErrorToken = (string) ($_POST['token'] ?? '');
    try {
        authCsrfCheck();
        if ($account === null) {
            throw new InvalidArgumentException('Log in to change this house.');
        }
        homeSetStreamPort($houseErrorToken, $account['id'], (int) ($_POST['stream_port'] ?? 0));
        homeRename($houseErrorToken, $account['id'], (string) ($_POST['house_name'] ?? ''));
        header('Location: index.php?house=' . rawurlencode($houseErrorToken) . '&house_saved=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $houseError = $e->getMessage();
    } catch (Throwable $e) {
        $houseError = authHiddenError($e);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_links'])) {
    $resendToken = (string) ($_POST['token'] ?? '');
    try {
        authCsrfCheck();
        if ($account === null || authIsAdmin($account)) {
            throw new InvalidArgumentException('Log in to email these links.');
        }
        $owned = epgDb()->prepare('SELECT 1 FROM homes WHERE token = ? AND user_id = ?');
        $owned->execute([$resendToken, $account['id']]);
        if (!$owned->fetchColumn()) {
            throw new InvalidArgumentException('This house was not found.');
        }
        if (!homeMailPublished($resendToken)) {
            throw new InvalidArgumentException('The links could not be sent. This account has no email address.');
        }
        $_SESSION['mail_note'] = 'The links were sent to your email.';
        header('Location: index.php?house=' . rawurlencode($resendToken));
        exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['mail_note'] = $e->getMessage();
        header('Location: index.php?house=' . rawurlencode($resendToken));
        exit;
    } catch (Throwable $e) {
        $_SESSION['mail_note'] = 'The links could not be sent.';
        header('Location: index.php?house=' . rawurlencode($resendToken));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        authCsrfCheck();
        if ($account === null) {
            throw new InvalidArgumentException('Log in to change your password.');
        }
        authChangePassword($account['id'], (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
        header('Location: index.php?password=1');
        exit;
    } catch (InvalidArgumentException $e) {
        authFail('password', $e->getMessage());
    } catch (Throwable $e) {
        authFail('password', authHiddenError($e));
    }
}

$houseSaved = isset($_GET['house_saved']);
$passwordSaved = isset($_GET['password']);

$isAdmin = authIsAdmin($account);
$settings = $isAdmin ? receiverSettings() : null;
$error = null;
$bouquets = [];

if ($isAdmin) {
    try {
        $bouquets = fetchBouquets();
    } catch (Throwable $e) {
        $error = authHiddenError($e);
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
        $homeError = authHiddenError($e);
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
    <link rel="stylesheet" href="assets/app.css?v=28">
    <?php appShellStyle(); ?>
</head>
<body>
<?php appChrome(); ?>
<div id="login" class="<?= ($authDialog === 'login' || $openDialog === 'login') ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="login-title">
        <h2 id="login-title"><?= $authDialog === 'login' && $authError !== null ? 'Wrong password' : 'Log in' ?></h2>
        <p>Your houses stay on this account. Other accounts cannot see them.</p>
        <?php if ($authDialog === 'login' && $authError !== null): ?>
            <p class="login-alert"><?= h($authError) ?></p>
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
                <button class="btn" type="button" id="forgot-open">Forgot password</button>
            </div>
        </form>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<div id="forgot" class="<?= $authDialog === 'forgot' ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="forgot-title">
        <h2 id="forgot-title">Forgot password</h2>
        <p>Enter the email address of the account. If it exists, a link to choose a new password is sent there. The link works for one hour.</p>
        <?php if ($authDialog === 'forgot' && $authError !== null): ?>
            <p class="<?= $authOk ? 'oknote' : 'error' ?>"><?= h($authError) ?></p>
        <?php endif; ?>
        <form class="stack" method="post" action="index.php">
            <?= authCsrfField() ?>
            <input type="hidden" name="forgot_password" value="1">
            <label class="field"><span>Email</span>
                <input name="username" type="email" maxlength="254" autocomplete="email" value="<?= h($authDialog === 'forgot' ? $authUsername : '') ?>" required>
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Send link</button>
            </div>
        </form>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<div id="register" class="<?= ($authDialog === 'register' || $openDialog === 'register') ? 'is-open' : '' ?>">
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
<?php if ($account !== null): ?>
<div id="password" class="<?= ($authDialog === 'password' || $openDialog === 'password') ? 'is-open' : '' ?>">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="password-title">
        <h2 id="password-title">Change password</h2>
        <p>Use a password of at least 8 characters.</p>
        <?php if ($authDialog === 'password' && $authError !== null): ?>
            <p class="error"><?= h($authError) ?></p>
        <?php endif; ?>
        <form class="stack" method="post" action="index.php">
            <?= authCsrfField() ?>
            <input type="hidden" name="change_password" value="1">
            <label class="field"><span>Current password</span>
                <input name="current_password" type="password" maxlength="200" autocomplete="current-password" required>
            </label>
            <label class="field"><span>New password</span>
                <input name="new_password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required>
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Save password</button>
            </div>
        </form>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<?php endif; ?>
<div id="add-house" class="<?= ($homeError !== null || ($account !== null && $homes === []) || $openDialog === 'add-house') ? 'is-open' : '' ?><?= ($account !== null && $homes === []) ? ' is-required' : '' ?>">
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
            <?php if ($isAdmin): ?>
            <label class="field port"><span>Stream port</span>
                <input name="house_stream_port" type="number" min="1" max="65535" value="8001" required>
            </label>
            <?php else: ?>
            <input type="hidden" name="house_stream_port" value="8001">
            <?php endif; ?>
            <div class="actions">
                <button class="btn primary" type="submit">Add house</button>
            </div>
        </form>
        <?php if ($account === null || $homes !== []): ?>
            <button class="btn" type="button" data-close>Close</button>
        <?php endif; ?>
    </div>
</div>
<?php if ($isAdmin): ?>
<div id="remote">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="remote-title">
        <h2 id="remote-title">Receiver is somewhere else</h2>
        <p>Use this when the receiver is not on the same network as this server. Publish playlist is for a receiver this server can reach.</p>
        <div class="guide-steps">
            <article>
                <span class="step">1</span>
                <div>
                    <h3>Save the bookmark</h3>
                    <p>Drag <strong>Publish house</strong> onto the bookmarks bar. Do not click it on this page. Press Ctrl+Shift+B if that bar is hidden.</p>
                    <p><a class="btn primary" id="remote-drag" href="#">Publish house</a></p>
                    <p class="error" id="remote-click-note" hidden>This button is only for dragging. Open the receiver web page, then click the bookmark there.</p>
                    <p>If you cannot drag it, press Copy. In Chrome press Ctrl+D, then More, replace URL with the copied text, name it Publish house, and press Done. The address starts with <code>javascript:</code>.</p>
                    <p><button class="btn" type="button" id="remote-copy" data-copy="">Copy</button></p>
                </div>
            </article>
            <article>
                <span class="step">2</span>
                <div>
                    <h3>Open the receiver</h3>
                    <p>On the receiver’s network, open its web page in this browser, for example <code>http://192.168.1.10</code>.</p>
                </div>
            </article>
            <article>
                <span class="step">3</span>
                <div>
                    <h3>Click the bookmark</h3>
                    <p>Click <strong>Publish house</strong> on that page. The browser reads the channels through OpenWebIF and sends them here. A message appears when the list is stored.</p>
                </div>
            </article>
        </div>
        <button class="btn" type="button" data-close>Close</button>
    </div>
</div>
<?php endif; ?>
<div class="app homes">
    <?php appMenubar('playlist', $activeHouse); ?>

    <div class="housebars">
        <?php if ($authBlocked): ?>
            <section class="login-alert">
                <h2>Account blocked</h2>
                <p><?= h((string) $authError) ?></p>
            </section>
        <?php elseif ($authError !== null): ?>
            <p class="login-alert"><?= h($authError) ?></p>
        <?php elseif ($isAdmin && $homes === []): ?>
            <p class="note">Add a house. The menu then shows that name next to “You are”.</p>
        <?php endif; ?>
        <?php if ($mailNote !== null): ?>
            <p class="<?= str_contains($mailNote, 'could not') ? 'error' : 'oknote' ?>"><?= h($mailNote) ?></p>
        <?php endif; ?>
        <?php if ($houseSaved): ?>
            <p class="oknote">House saved. The playlist address is unchanged. A new stream port is used the next time you publish.</p>
        <?php endif; ?>
        <?php if ($passwordSaved): ?>
            <p class="oknote">Password changed.</p>
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
        <?php if ($isAdmin): ?>
        <?php foreach ($homes as $house): ?>
            <?php
            $token = (string) $house['token'];
            $slug = (string) $house['slug'];
            $playlistUrl = homeChannelsUrl($slug);
            $guideUrl = homeGuideUrl($slug);
            $bookmark = homeBookmarklet($token);
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
                        <?= authCsrfField() ?>
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
                        <?php if (homePlaylistChannelCount($token) !== null): ?>
                            <a class="btn" href="playlist-edit.php?house=<?= h($token) ?>">Edit m3u8</a>
                        <?php endif; ?>
                    </form>
                    <div class="status"><i></i><span>Checking connection…</span></div>
                    <form method="post" action="index.php" class="slow">
                        <?= authCsrfField() ?>
                        <input type="hidden" name="publish_home" value="1">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <button class="btn" type="submit">Publish playlist</button>
                    </form>
                    <button class="btn" type="button" data-open="remote" data-bookmark="<?= h($bookmark) ?>">Receiver is somewhere else</button>
                    <form method="post" action="index.php" onsubmit="return confirm('Remove this house?');">
                        <?= authCsrfField() ?>
                        <input type="hidden" name="delete_home" value="1">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <button class="btn" type="submit">Remove</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
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
    <?php elseif ($isAdmin): ?>
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
    <?php else: ?>
    <div class="user-page">
        <?php if ($homes === []): ?>
            <section class="user-empty">
                <p class="eyebrow">Your house</p>
                <h1>Add a house to publish your channels.</h1>
                <p>The playlist and the guide for that house stay on this server. Only you can see them.</p>
            </section>
        <?php endif; ?>
        <?php foreach ($homes as $house): ?>
            <?php
            $token = (string) $house['token'];
            $slug = (string) $house['slug'];
            $playlistUrl = homeChannelsUrl($slug);
            $guideUrl = homeGuideUrl($slug);
            $bookmark = homeBookmarklet($token);
            $built = trim((string) ($house['built_at'] ?? ''));
            $channelCount = homePlaylistChannelCount($token);
            $publishedHost = trim((string) ($house['host'] ?? ''));
            if ($channelCount === null) {
                $publishSummary = 'Not published yet.';
            } else {
                $publishSummary = ($built !== '' ? 'Last published ' . $built . '. ' : 'Published. ')
                    . (int) $channelCount . ' channels'
                    . ($publishedHost !== '' ? ' from ' . $publishedHost : '')
                    . '.';
            }
            ?>
            <article class="user-house" data-house="<?= h($token) ?>"<?= $token === $activeHouse ? '' : ' hidden' ?>>
                <header class="user-house-head">
                    <p class="eyebrow"><?= h((string) $house['name']) ?></p>
                    <h1>Your playlist</h1>
                    <p class="note"><?= h($publishSummary) ?></p>
                </header>
                <div class="user-stack">
                    <section class="user-card">
                        <h2>Player addresses</h2>
                        <p>Paste both addresses into your IPTV player. The player has to be on the same network as the receiver. The guide refreshes each night. The channel list changes only when you publish again.</p>
                        <div class="house-links">
                            <div class="house-link">
                                <span>Channels</span>
                                <p class="url slim" title="<?= h($playlistUrl) ?>"><?= h($playlistUrl) ?></p>
                                <span class="link-actions">
                                    <button class="btn" type="button" data-copy="<?= h($playlistUrl) ?>">Copy</button>
                                    <?php if ($channelCount !== null): ?>
                                        <a class="btn" href="<?= h($playlistUrl) ?>" download="<?= h($slug) ?>.m3u8">Download</a>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="house-link">
                                <span>Guide</span>
                                <p class="url slim" title="<?= h($guideUrl) ?>"><?= h($guideUrl) ?></p>
                                <span class="link-actions">
                                    <button class="btn" type="button" data-copy="<?= h($guideUrl) ?>">Copy</button>
                                    <form method="post" action="index.php">
                                        <?= authCsrfField() ?>
                                        <input type="hidden" name="resend_links" value="1">
                                        <input type="hidden" name="token" value="<?= h($token) ?>">
                                        <button class="btn" type="submit">Email these links</button>
                                    </form>
                                </span>
                            </div>
                        </div>
                    </section>
                    <section class="user-card">
                        <h2>This house</h2>
                        <?php if ($houseError !== null && $houseErrorToken === $token): ?>
                            <p class="error"><?= h($houseError) ?></p>
                        <?php endif; ?>
                        <form class="house-edit" method="post" action="index.php">
                            <?= authCsrfField() ?>
                            <input type="hidden" name="update_house" value="1">
                            <input type="hidden" name="token" value="<?= h($token) ?>">
                            <label class="field"><span>House name</span>
                                <input name="house_name" maxlength="80" value="<?= h((string) $house['name']) ?>" required>
                            </label>
                            <label class="field port"><span>Stream port</span>
                                <input name="stream_port" type="number" min="1" max="65535" value="<?= (int) $house['stream_port'] ?>" required>
                            </label>
                            <button class="btn" type="submit">Save</button>
                            <?php if ($channelCount !== null): ?>
                                <a class="btn" href="playlist-edit.php?house=<?= h($token) ?>">Edit m3u8</a>
                            <?php endif; ?>
                            <p class="hint">The playlist address stays the same when you rename the house. A new stream port is used the next time you publish.</p>
                        </form>
                    </section>
                    <section class="user-card">
                        <h2>Publish the channels</h2>
                        <div class="guide-steps">
                            <article>
                                <span class="step">1</span>
                                <div>
                                    <h3>Save the bookmark</h3>
                                    <p>Drag <strong>Publish house</strong> onto the bookmarks bar. Do not click it on this page. Press Ctrl+Shift+B if that bar is hidden.</p>
                                    <p><a class="btn primary bookmark-drag" href="<?= h($bookmark) ?>">Publish house</a></p>
                                    <p class="error bookmark-note" hidden>This button is only for dragging. Open the receiver web page, then click the bookmark there.</p>
                                    <p>If you cannot drag it, press Copy. In Chrome press Ctrl+D, then More, replace URL with the copied text, name it Publish house, and press Done. The address starts with <code>javascript:</code>.</p>
                                    <p><button class="btn" type="button" data-copy="<?= h($bookmark) ?>">Copy</button></p>
                                </div>
                            </article>
                            <article>
                                <span class="step">2</span>
                                <div>
                                    <h3>Open the receiver</h3>
                                    <p>On the receiver’s network, open its web page in this browser, for example <code>http://192.168.1.10</code>.</p>
                                </div>
                            </article>
                            <article>
                                <span class="step">3</span>
                                <div>
                                    <h3>Click the bookmark</h3>
                                    <p>Click <strong>Publish house</strong> on that page. The browser reads the channels through OpenWebIF and sends them here. A message appears when the list is stored.</p>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var pick = document.getElementById('house-pick');
    var bars = document.querySelectorAll('.housebar, .user-house');
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
        var removeToken = document.getElementById('remove-house-token');
        if (removeToken && token) removeToken.value = token;
        try { if (token) localStorage.setItem(key, token); } catch (e) {}
    }
    show(params.get('house') || stored || pick.value);
    pick.addEventListener('change', function () { show(pick.value); });
})();
(function () {
    var drag = document.getElementById('remote-drag');
    var copy = document.getElementById('remote-copy');
    var note = document.getElementById('remote-click-note');
    if (drag) drag.addEventListener('click', function (event) {
        event.preventDefault();
        if (note) note.hidden = false;
        return false;
    });
    document.querySelectorAll('[data-open="remote"]').forEach(function (button) {
        button.addEventListener('click', function () {
            var bookmark = button.getAttribute('data-bookmark') || '';
            if (drag) drag.setAttribute('href', bookmark);
            if (copy) copy.setAttribute('data-copy', bookmark);
        });
    });
    document.querySelectorAll('.bookmark-drag').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var card = link.closest('.user-house');
            var clickNote = card ? card.querySelector('.bookmark-note') : null;
            if (clickNote) clickNote.hidden = false;
            return false;
        });
    });
})();
</script>
<script src="assets/app.js?v=8"></script>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
