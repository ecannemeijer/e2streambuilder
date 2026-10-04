<?php

require_once __DIR__ . '/epg.lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$return = appSafeReturn((string) ($_POST['return'] ?? 'index.php'));

try {
    authCsrfCheck();
    authThrottleGate('contact');
    $recent = epgDb()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE address = ? AND action = ? AND failed_at >= ?');
    $recent->execute([authClientAddress(), 'contact', date('Y-m-d H:i:s', time() - 900)]);
    if ((int) $recent->fetchColumn() >= 5) {
        throw new InvalidArgumentException('Wait a few minutes before sending another message.');
    }
    $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['subject'] ?? '')));
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($subject === '' || strlen($subject) > 120) {
        throw new InvalidArgumentException('Enter a subject of up to 120 characters.');
    }
    if ($message === '' || strlen($message) > 4000) {
        throw new InvalidArgumentException('Enter a message of up to 4000 characters.');
    }
    $to = envValue('CONTACT_EMAIL', 'e.cannemeijer@gmail.com');
    if (!authEmailOk($to)) {
        throw new RuntimeException('The contact address is not valid.');
    }
    authThrottleNote('contact');
    $account = authUser();
    $who = is_array($account) ? (string) $account['username'] : 'not logged in';
    authSendMail($to, $subject, "Account: " . $who . "\n\n" . $message);
    $_SESSION['app_notice'] = 'Message sent.';
} catch (InvalidArgumentException $e) {
    $_SESSION['contact_error'] = $e->getMessage();
    $_SESSION['contact_form'] = [
        'subject' => (string) ($_POST['subject'] ?? ''),
        'message' => (string) ($_POST['message'] ?? ''),
    ];
    $_SESSION['app_open'] = 'contact';
} catch (Throwable $e) {
    $_SESSION['contact_error'] = authHiddenError($e);
    $_SESSION['contact_form'] = [
        'subject' => (string) ($_POST['subject'] ?? ''),
        'message' => (string) ($_POST['message'] ?? ''),
    ];
    $_SESSION['app_open'] = 'contact';
}

header('Location: ' . $return);
exit;
