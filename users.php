<?php

require_once __DIR__ . '/epg.lib.php';

authRequireAdmin();

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    try {
        authCsrfCheck();
        authUpdateUser(
            (int) ($_POST['user_id'] ?? 0),
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['role'] ?? 'user')
        );
        header('Location: users.php?saved=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = authHiddenError($e);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    try {
        authCsrfCheck();
        $removeId = (int) ($_POST['user_id'] ?? 0);
        $actor = authUser();
        authDeleteUser($removeId);
        if (is_array($actor) && (int) $actor['id'] === $removeId) {
            authLogout();
            header('Location: index.php');
            exit;
        }
        header('Location: users.php?removed=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = authHiddenError($e);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unblock_user'])) {
    try {
        authCsrfCheck();
        $unblockId = (int) ($_POST['user_id'] ?? 0);
        $exists = epgDb()->prepare('SELECT id FROM users WHERE id = ?');
        $exists->execute([$unblockId]);
        if (!$exists->fetchColumn()) {
            throw new InvalidArgumentException('This account was not found.');
        }
        authClearLock($unblockId);
        header('Location: users.php?unblocked=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = authHiddenError($e);
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Account saved.';
}
if (isset($_GET['removed'])) {
    $notice = 'Account removed.';
}
if (isset($_GET['unblocked'])) {
    $notice = 'Account unblocked.';
}

$users = authUserList();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users · E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=32">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <?php appMenubar('users'); ?>
    <header class="pagehead">
        <h1>Users</h1>
        <p class="lede">Change an email address, set a new password, make an account an admin, or remove it. A blocked account tried the wrong password five times. Unblock lets that person log in again. Removing an account also removes its houses.</p>
    </header>

    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>
    <?php if ($notice !== null): ?>
        <p class="oknote"><?= h($notice) ?></p>
    <?php endif; ?>

    <section class="panel stack">
        <div class="table-wrap">
            <table class="grid users-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>New password</th>
                        <th>Role</th>
                        <th>Registered</th>
                        <th>Last login</th>
                        <th>Status</th>
                        <th>House</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="8">No accounts yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $person): ?>
                    <?php
                    $id = (int) $person['id'];
                    $blocked = trim((string) $person['locked_at']) !== '';
                    $registeredIp = trim((string) $person['registered_ip']);
                    $lastAt = trim((string) $person['last_login_at']);
                    $lastIp = trim((string) $person['last_login_ip']);
                    ?>
                    <tr>
                        <td>
                            <form id="save-user-<?= $id ?>" method="post" action="users.php">
                                <?= authCsrfField() ?>
                                <input type="hidden" name="save_user" value="1">
                                <input type="hidden" name="user_id" value="<?= $id ?>">
                            </form>
                            <input form="save-user-<?= $id ?>" name="username" maxlength="254" value="<?= h($person['username']) ?>" required>
                        </td>
                        <td>
                            <input form="save-user-<?= $id ?>" name="password" type="password" maxlength="200" autocomplete="new-password" placeholder="Keep">
                        </td>
                        <td>
                            <select form="save-user-<?= $id ?>" class="select" name="role">
                                <option value="user"<?= $person['role'] === 'user' ? ' selected' : '' ?>>User</option>
                                <option value="admin"<?= $person['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                            </select>
                        </td>
                        <td>
                            <span class="when"><?= h((string) $person['created_at']) ?></span>
                            <?php if ($registeredIp !== ''): ?>
                                <span class="when"><?= h($registeredIp) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($lastAt === ''): ?>
                                <span class="when">Not yet</span>
                            <?php else: ?>
                                <span class="when"><?= h($lastAt) ?></span>
                                <?php if ($lastIp !== ''): ?>
                                    <span class="when"><?= h($lastIp) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($blocked): ?>
                                <span class="badge error">Blocked</span>
                                <span class="when"><?= (int) $person['failed_logins'] ?> wrong passwords</span>
                            <?php else: ?>
                                <span class="badge ok">Open</span>
                                <?php if ((int) $person['failed_logins'] > 0): ?>
                                    <span class="when"><?= (int) $person['failed_logins'] ?> wrong passwords</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="house-pick">
                            <?php $houses = homeList($id); ?>
                            <?php if ($houses === []): ?>
                                <span class="when">No house</span>
                            <?php else: ?>
                                <?php
                                $readyToken = '';
                                foreach ($houses as $house) {
                                    if ($readyToken === '' && homePlaylistChannelCount((string) $house['token']) !== null) {
                                        $readyToken = (string) $house['token'];
                                    }
                                }
                                ?>
                                <select class="select user-house-pick" data-edit="edit-m3u8-<?= $id ?>">
                                    <?php foreach ($houses as $house): ?>
                                        <?php $ready = homePlaylistChannelCount((string) $house['token']) !== null; ?>
                                        <option value="<?= h((string) $house['token']) ?>" data-ready="<?= $ready ? '1' : '0' ?>"<?= (string) $house['token'] === $readyToken ? ' selected' : '' ?>><?= h((string) $house['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($readyToken !== ''): ?>
                                    <a class="btn" id="edit-m3u8-<?= $id ?>" href="playlist-edit.php?house=<?= h($readyToken) ?>">Edit m3u8</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <button class="btn primary" type="submit" form="save-user-<?= $id ?>">Save</button>
                            <button class="btn" type="submit" form="remove-user-<?= $id ?>">Remove</button>
                            <?php if ($blocked): ?>
                                <button class="btn" type="submit" form="unblock-user-<?= $id ?>">Unblock</button>
                            <?php endif; ?>
                            <form id="remove-user-<?= $id ?>" method="post" action="users.php" onsubmit="return confirm('Remove this account and its houses?');">
                                <?= authCsrfField() ?>
                                <input type="hidden" name="delete_user" value="1">
                                <input type="hidden" name="user_id" value="<?= $id ?>">
                            </form>
                            <?php if ($blocked): ?>
                                <form id="unblock-user-<?= $id ?>" method="post" action="users.php">
                                    <?= authCsrfField() ?>
                                    <input type="hidden" name="unblock_user" value="1">
                                    <input type="hidden" name="user_id" value="<?= $id ?>">
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php appFooter(); ?>
</div>
<script>
document.querySelectorAll('.user-house-pick').forEach(function (pick) {
    var link = document.getElementById(pick.getAttribute('data-edit'));
    function sync() {
        var option = pick.options[pick.selectedIndex];
        var ready = option && option.getAttribute('data-ready') === '1';
        if (!link) return;
        link.href = 'playlist-edit.php?house=' + encodeURIComponent(option ? option.value : '');
        link.hidden = !ready;
    }
    pick.addEventListener('change', sync);
    sync();
});
</script>
</body>
</html>
