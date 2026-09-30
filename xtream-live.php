<?php

require_once __DIR__ . '/xtream.lib.php';

$username = isset($_GET['user']) ? rawurldecode((string) $_GET['user']) : '';
$password = isset($_GET['pass']) ? rawurldecode((string) $_GET['pass']) : '';
if (!xtreamAuthOk($username, $password)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit;
}

$url = xtreamStreamUrl((int) ($_GET['id'] ?? 0));
if ($url === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Stream not found.\n";
    exit;
}

$ext = strtolower((string) ($_GET['ext'] ?? 'ts'));
$path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
if ($ext === 'm3u8' && preg_match('/\.m3u8$/i', $path) === 1) {
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}

@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_flush();
}

$failed = false;
$started = false;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$started, &$failed): int {
        if (!$started) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 400 || $code === 0) {
                $failed = true;

                return 0;
            }
            header('Content-Type: video/mp2t');
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no');
            $started = true;
        }
        echo $data;
        flush();
        if (connection_aborted()) {
            return 0;
        }

        return strlen($data);
    },
]);
curl_exec($ch);
if (!$started) {
    $failed = true;
}
curl_close($ch);

if ($failed && !$started && !headers_sent()) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The stream could not be opened.\n";
}
