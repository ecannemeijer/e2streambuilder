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
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Account saved.';
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
    <link rel="stylesheet" href="assets/app.css?v=12">
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
                <h1>Users</h1>
                <p class="lede">Change a username, set a new password, or make an account an admin. A new account is always a user.</p>
            </div>
        </div>
    </header>
    <?php appNav('users'); ?>

    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>
    <?php if ($notice !== null): ?>
        <p class="oknote"><?= h($notice) ?></p>
    <?php endif; ?>

    <section class="panel stack">
        <?php foreach ($users as $person): ?>
            <form class="toolbar" method="post" action="users.php">
                <?= authCsrfField() ?>
                <input type="hidden" name="save_user" value="1">
                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                <label class="field"><span>Username</span>
                    <input name="username" maxlength="40" value="<?= h($person['username']) ?>" required>
                </label>
                <label class="field"><span>New password</span>
                    <input name="password" type="password" maxlength="200" autocomplete="new-password" placeholder="Leave blank to keep">
                </label>
                <label class="field"><span>Role</span>
                    <select class="select" name="role">
                        <option value="user"<?= $person['role'] === 'user' ? ' selected' : '' ?>>User</option>
                        <option value="admin"<?= $person['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                    </select>
                </label>
                <div class="actions">
                    <button class="btn primary" type="submit">Save</button>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
</div>
</body>
</html>
