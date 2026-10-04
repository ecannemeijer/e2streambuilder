<?php

require_once __DIR__ . '/epg.lib.php';

authStart();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        authCsrfCheck();
        $next = (string) ($_POST['password'] ?? '');
        $again = (string) ($_POST['password_again'] ?? '');
        if ($next !== $again) {
            throw new InvalidArgumentException('Type the same password in both fields.');
        }
        authResetPassword($token, $next);
        $saved = true;
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
    <title>New password · E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=31">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <?php appMenubar('playlist'); ?>
    <header class="pagehead">
        <h1>New password</h1>
        <p class="lede">Choose a password of at least 8 characters. This link works once.</p>
    </header>
    <section class="panel" style="padding:16px;max-width:28rem">
        <?php if ($saved): ?>
            <p class="oknote">Password saved. You can log in with it.</p>
            <p><a class="btn primary" href="index.php">Back to the site</a></p>
        <?php else: ?>
            <?php if ($error !== null): ?>
                <p class="error"><?= h($error) ?></p>
            <?php endif; ?>
            <form class="stack" method="post" action="reset.php">
                <?= authCsrfField() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <label class="field"><span>New password</span>
                    <input name="password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required>
                </label>
                <label class="field"><span>Repeat password</span>
                    <input name="password_again" type="password" minlength="8" maxlength="200" autocomplete="new-password" required>
                </label>
                <button class="btn primary" type="submit">Save password</button>
            </form>
        <?php endif; ?>
    </section>
    <?php appFooter(); ?>
</div>
<script src="assets/app.js?v=8"></script>
</body>
</html>
