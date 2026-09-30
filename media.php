<?php

require_once __DIR__ . '/lib.php';

set_time_limit(0);

function mediaFail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function isPrivateIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function assertPlayableUrl(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        mediaFail(400, 'Invalid stream address.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        mediaFail(400, 'Only http and https streams can be tested in the browser.');
    }

    $host = (string) $parts['host'];
    $receiver = receiverSettings()['host'];
    $ip = $host;
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $resolved = gethostbyname($host);
        if ($resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP)) {
            mediaFail(502, 'The stream address could not be resolved.');
        }
        $ip = $resolved;
    }
    if (isPrivateIp($ip) && $host !== $receiver && $ip !== $receiver) {
        mediaFail(403, 'This address points to a private network.');
    }

    return $parts;
}

function resolveMediaUrl(string $base, string $ref): string
{
    $ref = trim($ref);
    if ($ref === '') {
        return $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref)) {
        return $ref;
    }

    $parts = parse_url($base);
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    if (str_starts_with($ref, '/')) {
        return $origin . $ref;
    }

    $path = $parts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

    return $origin . $dir . $ref;
}

function proxyMediaUrl(string $absolute): string
{
    return 'media.php?u=' . rawurlencode($absolute);
}

function rewritePlaylist(string $body, string $baseUrl): string
{
    $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
    $out = [];
    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            $out[] = preg_replace_callback(
                '/URI="([^"]*)"/',
                static function (array $match) use ($baseUrl): string {
                    $target = resolveMediaUrl($baseUrl, $match[1]);

                    return 'URI="' . proxyMediaUrl($target) . '"';
                },
                $line
            );
            continue;
        }
        $out[] = proxyMediaUrl(resolveMediaUrl($baseUrl, trim($line)));
    }

    return implode("\n", $out) . "\n";
}

function fetchMedia(string $url, int $redirects = 0): void
{
    if ($redirects > 3) {
        mediaFail(502, 'Too many redirects.');
    }
    assertPlayableUrl($url);

    $status = 0;
    $contentType = '';
    $location = '';
    $headersSent = false;
    $buffer = '';
    $playlist = false;
    $decided = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$status, &$contentType, &$location): int {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match)) {
                $status = (int) $match[1];
            } elseif (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }

            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$headersSent, &$buffer, &$playlist, &$decided, &$status, &$contentType, &$location, $url, $redirects): int {
            if ($status >= 300 && $status < 400 && $location !== '') {
                return strlen($data);
            }
            if ($status >= 400) {
                return strlen($data);
            }
            if (!$decided) {
                $buffer .= $data;
                $looksPlaylist = stripos($contentType, 'mpegurl') !== false
                    || stripos($contentType, 'mpegURL') !== false
                    || str_starts_with(ltrim($buffer), '#EXTM3U');
                if ($looksPlaylist) {
                    $playlist = true;
                    $decided = true;

                    return strlen($data);
                }
                if (strlen($buffer) < 64 && !str_contains($buffer, "\n") && strlen($data) < 64) {
                    return strlen($data);
                }
                $decided = true;
                if (!headers_sent()) {
                    http_response_code($status > 0 ? $status : 200);
                    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'video/mp2t'));
                    header('Cache-Control: no-cache');
                    header('X-Accel-Buffering: no');
                }
                $headersSent = true;
                echo $buffer;
                $buffer = '';
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();

                return strlen($data);
            }
            if ($playlist) {
                $buffer .= $data;

                return strlen($data);
            }
            echo $data;
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();

            return strlen($data);
        },
    ]);

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($ok === false) {
        mediaFail(502, 'Stream unreachable: ' . $error);
    }
    if ($status >= 300 && $status < 400 && $location !== '') {
        fetchMedia(resolveMediaUrl($url, $location), $redirects + 1);

        return;
    }
    if ($status >= 400) {
        mediaFail($status, 'The stream responded with HTTP ' . $status);
    }
    if ($playlist) {
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-cache');
        echo rewritePlaylist($buffer, $url);

        return;
    }
    if (!$headersSent && $buffer !== '') {
        header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/octet-stream'));
        header('Cache-Control: no-cache');
        echo $buffer;
    }
}

$url = $_GET['u'] ?? '';
if (!is_string($url) || $url === '' || strlen($url) > 4000) {
    mediaFail(400, 'No stream address.');
}

while (ob_get_level() > 0) {
    ob_end_flush();
}

fetchMedia($url);
