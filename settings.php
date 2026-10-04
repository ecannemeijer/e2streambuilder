<?php

require_once __DIR__ . '/epg.lib.php';

$account = authUser();
if ($account === null) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        authCsrfCheck();
        authChangePassword((int) $account['id'], (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
        $_SESSION['app_notice'] = authEmailOk((string) $account['username'])
            ? 'Password saved. A confirmation was sent to your email.'
            : 'Password saved.';
        header('Location: settings.php');
        exit;
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === 'The password was saved, but the email could not be sent.') {
            $_SESSION['app_notice'] = 'Password saved.';
            $_SESSION['app_error'] = $e->getMessage();
            header('Location: settings.php');
            exit;
        }
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = authHiddenError($e);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    try {
        authCsrfCheck();
        if ((string) ($_POST['confirm_delete'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm that this account should be removed.');
        }
        $stmt = epgDb()->prepare('SELECT username, password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $account['id']]);
        $row = $stmt->fetch();
        if (!is_array($row) || !password_verify((string) ($_POST['current_password'] ?? ''), (string) $row['password_hash'])) {
            throw new InvalidArgumentException('The current password is wrong.');
        }
        $username = (string) $row['username'];
        $role = epgDb()->prepare('SELECT role FROM users WHERE id = ?');
        $role->execute([(int) $account['id']]);
        if ((string) $role->fetchColumn() === 'admin') {
            $admins = (int) epgDb()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($admins < 2) {
                throw new InvalidArgumentException('Keep at least one admin.');
            }
        }
        if (authEmailOk($username)) {
            try {
                authSendMail(
                    $username,
                    'Your account was removed',
                    "This account was removed.\n\nIts houses, playlists, and guides were removed as well.\n"
                );
            } catch (Throwable $e) {
                error_log('e2sb: ' . $e->getMessage());
                throw new InvalidArgumentException('The email could not be sent, so the account was not removed.');
            }
        }
        authDeleteUser((int) $account['id']);
        authLogout();
        header('Location: index.php?removed=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = authHiddenError($e);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings · E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=31">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <?php appMenubar('settings'); ?>
    <header class="pagehead">
        <h1>Settings</h1>
        <p class="lede"><?= h((string) $account['username']) ?></p>
    </header>
    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <section class="panel stack">
        <h2>Change password</h2>
        <p class="note">Use a password of at least 8 characters. A confirmation is sent to your email.</p>
        <form class="stack" method="post" action="settings.php">
            <?= authCsrfField() ?>
            <input type="hidden" name="change_password" value="1">
            <label class="field"><span>Current password</span>
                <input name="current_password" type="password" maxlength="200" autocomplete="current-password" required>
            </label>
            <label class="field"><span>New password</span>
                <input name="new_password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required>
            </label>
            <button class="btn primary" type="submit">Save password</button>
        </form>
    </section>

    <section class="panel stack">
        <h2>Remove account</h2>
        <p class="note">This removes the account, every house, and the playlist and guide stored for each house. A confirmation is sent to your email.</p>
        <form class="stack" method="post" action="settings.php" onsubmit="return confirm('Remove this account and all of its houses?');">
            <?= authCsrfField() ?>
            <input type="hidden" name="delete_account" value="1">
            <label class="field"><span>Current password</span>
                <input name="current_password" type="password" maxlength="200" autocomplete="current-password" required>
            </label>
            <label class="checkline"><input type="checkbox" name="confirm_delete" value="1" required> Remove this account and all of its houses.</label>
            <button class="btn" type="submit">Remove account</button>
        </form>
    </section>
    <?php appFooter(); ?>
</div>
</body>
</html>
