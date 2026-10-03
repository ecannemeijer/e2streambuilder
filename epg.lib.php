<?php

require_once __DIR__ . '/lib.php';

function epgNow(): string
{
    return date('Y-m-d H:i:s');
}

function epgDir(): string
{
    return __DIR__ . '/data/epg';
}

function epgEnsureDirs(): void
{
    foreach ([epgDir(), epgDir() . '/sources', epgDir() . '/tmp', epgDir() . '/out'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The EPG folder could not be created.');
        }
    }
}

function epgMaxDownloadBytes(): int
{
    return 128 * 1024 * 1024;
}

function epgMaxXmlBytes(): int
{
    return 512 * 1024 * 1024;
}

function epgSourceXmlPath(int $id): string
{
    if ($id < 1) {
        throw new InvalidArgumentException('Invalid source.');
    }

    return epgDir() . '/sources/' . $id . '.xml';
}

function epgChannelsXmlPath(): string
{
    return epgDir() . '/rytec.channels.xml';
}

function epgXmlPath(): string
{
    return epgDir() . '/out/epg.xml';
}

function epgGzPath(): string
{
    return epgDir() . '/out/epg.xml.gz';
}

function eitXmlPath(): string
{
    return epgDir() . '/out/epg-eit.xml';
}

function eitGzPath(): string
{
    return epgDir() . '/out/epg-eit.xml.gz';
}

function epgLog(string $message): void
{
    try {
        epgEnsureDirs();
        $path = epgDir() . '/epg.log';
        if (is_file($path) && filesize($path) > 2000000) {
            @unlink($path . '.1');
            @rename($path, $path . '.1');
        }
        file_put_contents($path, epgNow() . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
        epgProgressEmit($message);
    } catch (Throwable $e) {
        // Logging must not stop the application.
    }
}

function epgProgressBegin(): void
{
    $GLOBALS['epg_progress'] = true;
    @ignore_user_abort(true);
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    echo str_repeat(' ', 4096) . "\n";
    flush();
}

function epgProgressEmit(string $message, bool $done = false, bool $error = false, ?string $redirect = null): void
{
    if (empty($GLOBALS['epg_progress'])) {
        return;
    }
    $payload = ['text' => $message, 'done' => $done, 'error' => $error];
    if ($redirect !== null && $redirect !== '') {
        $payload['redirect'] = $redirect;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo str_repeat(' ', 8192) . "\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

function epgTakeFlash(): array
{
    $notice = epgStateGet('ui_notice');
    $error = epgStateGet('ui_error');
    if ($notice !== null && $notice !== '') {
        epgStateSet('ui_notice', '');
    } else {
        $notice = null;
    }
    if ($error !== null && $error !== '') {
        epgStateSet('ui_error', '');
    } else {
        $error = null;
    }

    return ['notice' => $notice, 'error' => $error];
}

function epgTailLog(int $lines = 40): string
{
    $path = epgDir() . '/epg.log';
    if (!is_file($path)) {
        return '';
    }
    $rows = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($rows)) {
        return '';
    }

    return implode("\n", array_slice($rows, -$lines));
}

function epgDb(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    epgEnsureDirs();
    $db = new PDO('sqlite:' . __DIR__ . '/data/epg.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    epgMigrate($db);

    return $db;
}

function epgMigrate(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS epg_schema (version INTEGER NOT NULL)');
    $version = (int) $db->query('SELECT COALESCE(MAX(version), 0) FROM epg_schema')->fetchColumn();
    if ($version < 1) {
        $db->beginTransaction();
        $db->exec('CREATE TABLE epg_sources (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            mirrors TEXT NOT NULL DEFAULT \'[]\',
            enabled INTEGER NOT NULL DEFAULT 1,
            priority INTEGER NOT NULL DEFAULT 100,
            status TEXT NOT NULL DEFAULT \'idle\',
            last_success_at TEXT,
            last_attempt_at TEXT,
            file_size INTEGER,
            channel_count INTEGER,
            programme_count INTEGER,
            error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE epg_channels (
            id INTEGER PRIMARY KEY,
            source_id INTEGER NOT NULL,
            xmltv_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            alt_names TEXT NOT NULL DEFAULT \'[]\',
            norm_name TEXT NOT NULL DEFAULT \'\',
            icon TEXT,
            UNIQUE(source_id, xmltv_id)
        )');
        $db->exec('CREATE INDEX epg_channels_xmltv ON epg_channels(xmltv_id)');
        $db->exec('CREATE INDEX epg_channels_norm ON epg_channels(norm_name)');
        $db->exec('CREATE TABLE epg_service_map (
            service_ref TEXT PRIMARY KEY,
            service_key TEXT NOT NULL,
            xmltv_id TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX epg_service_map_key ON epg_service_map(service_key)');
        $db->exec('CREATE TABLE epg_mappings (
            service_ref TEXT PRIMARY KEY,
            service_name TEXT NOT NULL,
            bouquet TEXT,
            satellite TEXT,
            xmltv_id TEXT,
            source_id INTEGER,
            source_name TEXT,
            match_type TEXT NOT NULL,
            confidence REAL,
            status TEXT NOT NULL,
            candidates TEXT,
            updated_at TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE epg_update_history (
            id INTEGER PRIMARY KEY,
            started_at TEXT NOT NULL,
            finished_at TEXT,
            trigger TEXT NOT NULL,
            status TEXT NOT NULL,
            message TEXT,
            channels INTEGER,
            programmes INTEGER,
            matched INTEGER,
            manual_count INTEGER,
            unmatched INTEGER,
            conflicts INTEGER
        )');
        $db->exec('CREATE TABLE epg_state (key TEXT PRIMARY KEY, value TEXT)');
        $db->exec('INSERT INTO epg_schema (version) VALUES (1)');
        $db->commit();
    }
    if ($version < 2) {
        $db->exec('CREATE TABLE IF NOT EXISTS xtream_categories (
            category_id INTEGER PRIMARY KEY,
            bouquet_ref TEXT NOT NULL UNIQUE,
            category_name TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS xtream_streams (
            stream_id INTEGER PRIMARY KEY,
            category_id INTEGER NOT NULL,
            service_ref TEXT NOT NULL,
            name TEXT NOT NULL,
            stream_url TEXT NOT NULL,
            epg_channel_id TEXT NOT NULL DEFAULT \'\',
            added_at INTEGER NOT NULL,
            UNIQUE(category_id, service_ref)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS xtream_streams_category ON xtream_streams(category_id)');
        $db->exec('INSERT INTO epg_schema (version) VALUES (2)');
    }
    if ($version < 3) {
        foreach (['xtream_categories', 'xtream_streams'] as $table) {
            $exists = false;
            foreach ($db->query('PRAGMA table_info(' . $table . ')') as $info) {
                if ((string) $info['name'] === 'sort_order') {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
            }
        }
        $db->exec('INSERT INTO epg_schema (version) VALUES (3)');
    }
    if ($version < 4) {
        $db->exec('CREATE TABLE IF NOT EXISTS homes (
            token TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            stream_port INTEGER NOT NULL DEFAULT 8001,
            host TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            built_at TEXT
        )');
        $db->exec('INSERT INTO epg_schema (version) VALUES (4)');
    }
    if ($version < 5) {
        $db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $hasUser = false;
        foreach ($db->query('PRAGMA table_info(homes)') as $info) {
            if ((string) $info['name'] === 'user_id') {
                $hasUser = true;
                break;
            }
        }
        if (!$hasUser) {
            $db->exec('ALTER TABLE homes ADD COLUMN user_id INTEGER');
        }
        $db->exec('CREATE INDEX IF NOT EXISTS homes_user ON homes(user_id)');
        $db->exec('INSERT INTO epg_schema (version) VALUES (5)');
    }
    if ($version < 6) {
        $hasSlug = false;
        foreach ($db->query('PRAGMA table_info(homes)') as $info) {
            if ((string) $info['name'] === 'slug') {
                $hasSlug = true;
                break;
            }
        }
        if (!$hasSlug) {
            $db->exec('ALTER TABLE homes ADD COLUMN slug TEXT');
        }
        $used = [];
        $pending = [];
        foreach ($db->query('SELECT token, name, slug FROM homes') as $row) {
            $current = (string) ($row['slug'] ?? '');
            if (homeSlugOk($current)) {
                $used[$current] = true;
                continue;
            }
            $pending[] = $row;
        }
        $update = $db->prepare('UPDATE homes SET slug = ? WHERE token = ?');
        foreach ($pending as $row) {
            $base = homeSlugFromName((string) $row['name']);
            if ($base === '') {
                $base = 'house';
            }
            $slug = $base;
            $n = 2;
            while (isset($used[$slug])) {
                $suffix = '-' . $n;
                $slug = rtrim(substr($base, 0, 40 - strlen($suffix)), '-') . $suffix;
                $n++;
            }
            $update->execute([$slug, $row['token']]);
            $used[$slug] = true;
        }
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS homes_slug ON homes(slug)');
        $db->exec('INSERT INTO epg_schema (version) VALUES (6)');
    }
    epgSeedSources();
}

function epgStateGet(string $key): ?string
{
    $stmt = epgDb()->prepare('SELECT value FROM epg_state WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string) $value;
}

function epgStateSet(string $key, string $value): void
{
    epgDb()->prepare('INSERT INTO epg_state (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

function homeTokenOk(string $token): bool
{
    return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
}

function homeSlugOk(string $slug): bool
{
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug) === 1;
}

function homeSlugFromName(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if (strlen($slug) > 40) {
        $slug = rtrim(substr($slug, 0, 40), '-');
    }

    return $slug;
}

function homeHostOk(string $host): bool
{
    if ($host === '' || strlen($host) > 253 || strpbrk($host, "/\\ \t\r\n") !== false) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return true;
    }

    return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1;
}

function homePlaylistPath(string $token): string
{
    return __DIR__ . '/data/users/' . $token . '.m3u8';
}

function homeCreate(string $name, int $streamPort, int $userId): array
{
    $name = trim($name);
    if ($userId < 1) {
        throw new InvalidArgumentException('Log in to add a house.');
    }
    if ($name === '' || strlen($name) > 80) {
        throw new InvalidArgumentException('Enter a house name of up to 80 characters.');
    }
    if ($streamPort < 1 || $streamPort > 65535) {
        throw new InvalidArgumentException('The stream port must be between 1 and 65535.');
    }
    $slug = homeSlugFromName($name);
    if (!homeSlugOk($slug)) {
        throw new InvalidArgumentException('Use a house name with a letter or number. The playlist address uses that name.');
    }
    $taken = epgDb()->prepare('SELECT 1 FROM homes WHERE slug = ?');
    $taken->execute([$slug]);
    if ($taken->fetchColumn()) {
        throw new InvalidArgumentException('That house name is already used. Choose another.');
    }
    $token = bin2hex(random_bytes(16));
    $now = epgNow();
    try {
        epgDb()->prepare('INSERT INTO homes (token, name, slug, stream_port, host, created_at, user_id) VALUES (?, ?, ?, ?, \'\', ?, ?)')
            ->execute([$token, $name, $slug, $streamPort, $now, $userId]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new InvalidArgumentException('That house name is already used. Choose another.');
        }
        throw $e;
    }
    $home = homeByToken($token);
    if ($home === null) {
        throw new RuntimeException('The house could not be saved.');
    }

    return $home;
}

function homeDelete(string $token, int $userId): void
{
    if (!homeTokenOk($token) || $userId < 1) {
        return;
    }
    $stmt = epgDb()->prepare('DELETE FROM homes WHERE token = ? AND user_id = ?');
    $stmt->execute([$token, $userId]);
    if ($stmt->rowCount() !== 1) {
        return;
    }
    $path = homePlaylistPath($token);
    if (is_file($path)) {
        @unlink($path);
    }
}

function homeByToken(string $token): ?array
{
    if (!homeTokenOk($token)) {
        return null;
    }
    $stmt = epgDb()->prepare('SELECT token, name, slug, stream_port, host, created_at, built_at FROM homes WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function homeBySlug(string $slug): ?array
{
    $slug = strtolower($slug);
    if (!homeSlugOk($slug)) {
        return null;
    }
    $stmt = epgDb()->prepare('SELECT token, name, slug, stream_port, host, created_at, built_at FROM homes WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/** @return list<array<string, mixed>> */
function homeList(int $userId): array
{
    if ($userId < 1) {
        return [];
    }
    $stmt = epgDb()->prepare('SELECT token, name, slug, stream_port, host, created_at, built_at FROM homes WHERE user_id = ? ORDER BY created_at ASC, name ASC');
    $stmt->execute([$userId]);
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = $row;
    }

    return $rows;
}

function authStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $path = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $path = rtrim($path, '/');
    if ($path === '' || $path === '.' || $path === '/') {
        $path = '/';
    }
    session_name('e2sb');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function authCsrf(): string
{
    authStart();
    $token = $_SESSION['csrf'] ?? '';
    if (!is_string($token) || strlen($token) < 16) {
        $token = bin2hex(random_bytes(16));
        $_SESSION['csrf'] = $token;
    }

    return $token;
}

function authCsrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h(authCsrf()) . '">';
}

function authCsrfCheck(): void
{
    authStart();
    $sent = (string) ($_POST['csrf'] ?? '');
    $have = (string) ($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        throw new InvalidArgumentException('The form expired. Reload the page and try again.');
    }
}

function authFail(string $dialog, string $message, string $username = ''): void
{
    authStart();
    $_SESSION['auth_flash'] = [
        'dialog' => $dialog,
        'message' => $message,
        'username' => trim($username),
    ];
    session_write_close();
    header('Location: index.php');
    exit;
}

/** @return array{dialog: string, message: string, username: string} */
function authFlashTake(): array
{
    authStart();
    $flash = $_SESSION['auth_flash'] ?? null;
    unset($_SESSION['auth_flash']);
    if (!is_array($flash)) {
        return ['dialog' => '', 'message' => '', 'username' => ''];
    }

    return [
        'dialog' => (string) ($flash['dialog'] ?? ''),
        'message' => (string) ($flash['message'] ?? ''),
        'username' => (string) ($flash['username'] ?? ''),
    ];
}

function authUsernameOk(string $name): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{1,39}$/', $name) === 1;
}

/** @return array{id: int, username: string}|null */
function authUser(): ?array
{
    authStart();
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $stmt = epgDb()->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        unset($_SESSION['user_id']);

        return null;
    }

    return ['id' => (int) $row['id'], 'username' => (string) $row['username']];
}

function authRemember(int $userId): void
{
    authStart();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function authRegister(string $username, string $password): array
{
    $username = trim($username);
    if (!authUsernameOk($username)) {
        throw new InvalidArgumentException('Use a username of 2 to 40 letters, numbers, spaces, dots, hyphens or underscores.');
    }
    if (strlen($password) < 8 || strlen($password) > 200) {
        throw new InvalidArgumentException('Use a password of at least 8 characters.');
    }
    $db = epgDb();
    $db->beginTransaction();
    try {
        $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $db->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), epgNow()]);
        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('The account could not be saved.');
        }
        if ($count === 0) {
            $db->prepare('UPDATE homes SET user_id = ? WHERE user_id IS NULL')->execute([$id]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE')) {
            throw new InvalidArgumentException('That username is already in use.');
        }
        throw $e;
    }
    authRemember($id);
    $user = authUser();
    if ($user === null) {
        throw new RuntimeException('The account could not be saved.');
    }

    return $user;
}

function authLogin(string $username, string $password): array
{
    $username = trim($username);
    $stmt = epgDb()->prepare('SELECT id, password_hash FROM users WHERE username = ? COLLATE NOCASE');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    $hash = is_array($row) ? (string) $row['password_hash'] : '';
    if ($hash === '' || !password_verify($password, $hash)) {
        throw new InvalidArgumentException('The username or password is wrong. Create an account first if you do not have one.');
    }
    authRemember((int) $row['id']);
    $user = authUser();
    if ($user === null) {
        throw new RuntimeException('The account could not be opened.');
    }

    return $user;
}

function authLogout(): void
{
    authStart();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string) $params['path'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function homeMarkBuilt(string $token, string $host): void
{
    epgDb()->prepare('UPDATE homes SET host = ?, built_at = ? WHERE token = ?')
        ->execute([$host, epgNow(), $token]);
}

function homeBookmarklet(string $token): string
{
    $base = appBaseUrl();
    $script = '(function(){var token=' . json_encode($token) . ';var base=' . json_encode($base) . ';'
        . 'fetch("/api/getallservices",{headers:{Accept:"application/json"}}).then(function(r){if(!r.ok)throw new Error("The receiver did not return the channel list.");return r.json();})'
        . '.then(function(data){return fetch(base+"/publish.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({token:token,host:location.hostname,services:data})});})'
        . '.then(function(r){return r.json().then(function(body){if(!r.ok)throw new Error(body.message||"Publish failed.");return body;});})'
        . '.then(function(body){alert(body.message||"Playlist published.");})'
        . '.catch(function(e){alert(e&&e.message?e.message:"Publish failed.");});})();';

    return 'javascript:' . rawurlencode($script);
}

function homePublishPlaylist(string $token, string $host, array $services): int
{
    $home = homeByToken($token);
    if ($home === null) {
        throw new InvalidArgumentException('This house was not found.');
    }
    if (!homeHostOk($host)) {
        throw new InvalidArgumentException('The receiver address is not valid.');
    }
    if (!isset($services['services']) || !is_array($services['services'])) {
        throw new InvalidArgumentException('The channel list is missing.');
    }
    $GLOBALS['receiver_override'] = [
        'host' => $host,
        'webif_port' => 80,
        'stream_port' => (int) $home['stream_port'],
    ];
    ob_start();
    $count = writePlaylist($services, null, 'all');
    $body = ob_get_clean();
    if ($body === false || $body === '') {
        throw new RuntimeException('The playlist could not be written.');
    }
    $path = homePlaylistPath($token);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The house folder could not be created.');
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        throw new RuntimeException('The playlist could not be saved.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('The playlist could not be saved.');
    }
    homeMarkBuilt($token, $host);

    return $count;
}

function epgRytecUrls(string $file): array
{
    return [
        'http://www.xmltvepg.nl/' . $file,
        'http://rytecepg.wanwizard.eu/' . $file,
        'http://epg.vuplus-community.net/' . $file,
    ];
}

function epgChannelsUrls(): array
{
    return epgRytecUrls('rytec.channels.xml.xz');
}

function epgLocalizeSourceNames(): void
{
    $names = [
        'Nederland basis' => 'Netherlands basic',
        'Vlaanderen basis' => 'Flanders basic',
        'Nederland/Vlaanderen gemeenschappelijk' => 'Netherlands/Flanders common',
        'Wallonië basis' => 'Wallonia basic',
        'Duitsland basis' => 'Germany basic',
        'Italië basis' => 'Italy basic',
    ];
    $stmt = epgDb()->prepare('UPDATE epg_sources SET name = ? WHERE name = ?');
    foreach ($names as $from => $to) {
        $stmt->execute([$to, $from]);
    }
}

function epgSeedSources(): void
{
    epgLocalizeSourceNames();
    if (epgStateGet('seeded') === '1') {
        return;
    }
    $count = (int) epgDb()->query('SELECT COUNT(*) FROM epg_sources')->fetchColumn();
    if ($count > 0) {
        epgStateSet('seeded', '1');

        return;
    }
    $files = [
        ['Netherlands basic', 'rytecNL_Basic.xz'],
        ['Flanders basic', 'rytecBE_VL_Basic.xz'],
        ['Netherlands/Flanders common', 'rytecBE_NL_Common.xz'],
        ['Wallonia basic', 'rytecBE_FR_Basic.xz'],
        ['Germany basic', 'rytecDE_Basic.xz'],
        ['UK/Ireland FreeSat', 'rytecUK_Basic.xz'],
        ['Italy basic', 'rytecIT_Basic.xz'],
    ];
    $priority = 10;
    foreach ($files as $file) {
        $urls = epgRytecUrls($file[1]);
        epgInsertSource($file[0], $urls[0], array_slice($urls, 1), true, $priority);
        $priority += 10;
    }
    epgStateSet('seeded', '1');
    epgLog('Default Rytec sources added');
}

function epgInsertSource(string $name, string $url, array $mirrors, bool $enabled, int $priority): int
{
    $now = epgNow();
    $stmt = epgDb()->prepare('INSERT INTO epg_sources
        (name, url, mirrors, enabled, priority, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, \'idle\', ?, ?)');
    $stmt->execute([
        $name,
        $url,
        json_encode(array_values($mirrors), JSON_UNESCAPED_SLASHES),
        $enabled ? 1 : 0,
        $priority,
        $now,
        $now,
    ]);

    return (int) epgDb()->lastInsertId();
}

function epgListSources(): array
{
    return epgDb()->query('SELECT * FROM epg_sources ORDER BY priority ASC, id ASC')->fetchAll();
}

function epgEnabledSources(): array
{
    return epgDb()->query('SELECT * FROM epg_sources WHERE enabled = 1 ORDER BY priority ASC, id ASC')->fetchAll();
}

function epgSourceById(int $id): array
{
    $stmt = epgDb()->prepare('SELECT * FROM epg_sources WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new InvalidArgumentException('This EPG source does not exist.');
    }

    return $row;
}

function epgDecodeMirrors(array $source): array
{
    $mirrors = json_decode((string) ($source['mirrors'] ?? '[]'), true);

    return is_array($mirrors) ? array_values(array_filter($mirrors, 'is_string')) : [];
}

function epgSourceUrls(array $source): array
{
    $urls = [trim((string) $source['url'])];
    foreach (epgDecodeMirrors($source) as $mirror) {
        $mirror = trim($mirror);
        if ($mirror !== '' && !in_array($mirror, $urls, true)) {
            $urls[] = $mirror;
        }
    }

    return $urls;
}

function epgValidateUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000 || preg_match('/[\r\n\0]/', $url)) {
        throw new InvalidArgumentException('Enter a valid http or https URL.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Enter a valid http or https URL.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Only http and https addresses are allowed.');
    }
    epgAssertPublicHost((string) $parts['host']);

    return $url;
}

function epgAssertPublicHost(string $host): void
{
    $host = trim($host);
    if ($host === '' || strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.local')) {
        throw new InvalidArgumentException('This address is not allowed.');
    }
    $ip = $host;
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $ip = gethostbyname($host);
        if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('The EPG address could not be resolved.');
        }
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw new InvalidArgumentException('Addresses on a private network are not allowed.');
    }
}

function epgResolveUrl(string $base, string $location): string
{
    $location = trim($location);
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $location;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $path = $parts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

    return $origin . $dir . $location;
}

function epgParseMirrorText(string $text): array
{
    $mirrors = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $mirrors[] = epgValidateUrl($line);
    }

    return $mirrors;
}

function epgAddSource(array $input): int
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || strlen($name) > 120) {
        throw new InvalidArgumentException('Enter a name of at most 120 characters.');
    }
    $url = epgValidateUrl((string) ($input['url'] ?? ''));
    $mirrors = epgParseMirrorText((string) ($input['mirrors'] ?? ''));
    $priority = (int) ($input['priority'] ?? 100);
    if ($priority < -1000 || $priority > 1000) {
        throw new InvalidArgumentException('Priority must be between -1000 and 1000.');
    }

    return epgInsertSource($name, $url, $mirrors, !empty($input['enabled']), $priority);
}

function epgUpdateSource(int $id, array $input): void
{
    epgSourceById($id);
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || strlen($name) > 120) {
        throw new InvalidArgumentException('Enter a name of at most 120 characters.');
    }
    $url = epgValidateUrl((string) ($input['url'] ?? ''));
    $mirrors = epgParseMirrorText((string) ($input['mirrors'] ?? ''));
    $priority = (int) ($input['priority'] ?? 100);
    if ($priority < -1000 || $priority > 1000) {
        throw new InvalidArgumentException('Priority must be between -1000 and 1000.');
    }
    epgDb()->prepare('UPDATE epg_sources
        SET name = ?, url = ?, mirrors = ?, enabled = ?, priority = ?, updated_at = ?
        WHERE id = ?')->execute([
        $name,
        $url,
        json_encode(array_values($mirrors), JSON_UNESCAPED_SLASHES),
        !empty($input['enabled']) ? 1 : 0,
        $priority,
        epgNow(),
        $id,
    ]);
}

function epgDeleteSource(int $id): void
{
    epgSourceById($id);
    epgDb()->prepare('DELETE FROM epg_channels WHERE source_id = ?')->execute([$id]);
    epgDb()->prepare('DELETE FROM epg_sources WHERE id = ?')->execute([$id]);
    @unlink(epgSourceXmlPath($id));
    epgLog('EPG source removed: ' . $id);
}

function epgSetSourceEnabled(int $id, bool $enabled): void
{
    epgSourceById($id);
    epgDb()->prepare('UPDATE epg_sources SET enabled = ?, updated_at = ? WHERE id = ?')
        ->execute([$enabled ? 1 : 0, epgNow(), $id]);
}

function epgAcquireLock(bool $blocking = false)
{
    epgEnsureDirs();
    $handle = fopen(epgDir() . '/update.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('The update lock could not be opened.');
    }
    if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
        fclose($handle);

        return null;
    }

    return $handle;
}

function epgReleaseLock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function epgWithLock(callable $action)
{
    $lock = epgAcquireLock(false);
    if ($lock === null) {
        throw new RuntimeException('An EPG update is already running.');
    }
    try {
        return $action();
    } finally {
        epgReleaseLock($lock);
    }
}

function epgDownloadToFile(string $url, string $dest, int $redirects = 0): void
{
    if ($redirects > 3) {
        throw new RuntimeException('Too many redirects.');
    }
    $url = epgValidateUrl($url);
    $handle = fopen($dest, 'wb');
    if ($handle === false) {
        throw new RuntimeException('The temporary download file could not be created.');
    }
    $location = '';
    $tooLarge = false;
    $label = basename((string) (parse_url($url, PHP_URL_PATH) ?: $url));
    $lastProgress = 0.0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'E2-naar-M3U8 EPG',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }

            return strlen($header);
        },
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($ch, $downloadSize, $downloaded) use (&$tooLarge, $label, &$lastProgress): int {
            $size = (int) $downloaded;
            if ((int) $downloadSize > epgMaxDownloadBytes() || $size > epgMaxDownloadBytes()) {
                $tooLarge = true;

                return 1;
            }
            if (!empty($GLOBALS['epg_progress'])) {
                $now = microtime(true);
                if ($size > 0 && $now - $lastProgress >= 1.0) {
                    $lastProgress = $now;
                    $text = 'Downloading ' . ($label !== '' ? $label : 'file');
                    if ((int) $downloadSize > 0) {
                        $pct = (int) min(100, round($size / (int) $downloadSize * 100));
                        $text .= ' — ' . $pct . '% (' . number_format($size / 1048576, 1) . ' MB)';
                    } else {
                        $text .= ' — ' . number_format($size / 1048576, 1) . ' MB';
                    }
                    epgProgressEmit($text);
                }
            }

            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($handle);

    if ($tooLarge) {
        @unlink($dest);
        throw new RuntimeException('The file is larger than the allowed download size.');
    }
    if ($code >= 300 && $code < 400 && $location !== '') {
        @unlink($dest);
        epgDownloadToFile(epgResolveUrl($url, $location), $dest, $redirects + 1);

        return;
    }
    if ($ok === false || $code !== 200 || !is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        $detail = $error !== '' ? $error : ('HTTP ' . $code);
        throw new RuntimeException('Download failed: ' . $detail);
    }
}

function epgDownloadFirst(array $urls, string $dest): string
{
    $errors = [];
    foreach ($urls as $url) {
        try {
            $file = basename((string) (parse_url($url, PHP_URL_PATH) ?: $url));
            epgLog('Downloading ' . ($file !== '' ? $file : $url));
            epgDownloadToFile($url, $dest);

            return $url;
        } catch (Throwable $e) {
            @unlink($dest);
            $errors[] = $e->getMessage();
        }
    }
    throw new RuntimeException($errors === [] ? 'No download address.' : implode(' | ', $errors));
}

function epgXzBinary(): ?string
{
    $candidates = [
        __DIR__ . '/bin/xz.exe',
        __DIR__ . '/bin/xz',
        'C:\\Program Files\\Git\\mingw64\\bin\\xz.exe',
        'C:\\Program Files\\Git\\usr\\bin\\xz.exe',
        '/usr/bin/xz',
        '/bin/xz',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    $lookup = PHP_OS_FAMILY === 'Windows' ? 'where xz 2>NUL' : 'command -v xz 2>/dev/null';
    $found = shell_exec($lookup);
    if (is_string($found)) {
        $first = strtok(trim($found), "\r\n");
        if (is_string($first) && $first !== '' && is_file($first)) {
            return $first;
        }
    }

    return null;
}

function epgFileKind(string $path): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return 'unknown';
    }
    $head = fread($handle, 16) ?: '';
    fclose($handle);
    if (str_starts_with($head, "\x1f\x8b")) {
        return 'gz';
    }
    if (str_starts_with($head, "\xfd7zXZ\x00")) {
        return 'xz';
    }
    $trim = ltrim($head, "\xEF\xBB\xBF \t\r\n");
    if (str_starts_with($trim, '<')) {
        return 'xml';
    }

    return 'unknown';
}

function epgGunzip(string $src, string $dest): void
{
    $in = gzopen($src, 'rb');
    if ($in === false) {
        throw new RuntimeException('Could not open gzip.');
    }
    $out = fopen($dest, 'wb');
    if ($out === false) {
        gzclose($in);
        throw new RuntimeException('The temporary XML file could not be created.');
    }
    $total = 0;
    try {
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Could not read gzip.');
            }
            if ($chunk === '') {
                break;
            }
            $total += strlen($chunk);
            if ($total > epgMaxXmlBytes()) {
                throw new RuntimeException('The unpacked XML file is too large.');
            }
            if (fwrite($out, $chunk) === false) {
                throw new RuntimeException('Could not write XML.');
            }
        }
    } catch (Throwable $e) {
        gzclose($in);
        fclose($out);
        @unlink($dest);
        throw $e;
    }
    gzclose($in);
    fclose($out);
}

function epgUnpackXz(string $src, string $dest): void
{
    $xz = epgXzBinary();
    if ($xz === null) {
        $hint = PHP_OS_FAMILY === 'Windows'
            ? 'Install Git or place xz.exe in the bin folder.'
            : 'Install the xz package.';
        throw new RuntimeException('No xz program found. ' . $hint);
    }
    $out = fopen($dest, 'wb');
    if ($out === false) {
        throw new RuntimeException('The temporary XML file could not be created.');
    }
    $pipes = [];
    $process = proc_open(
        [$xz, '-dc', '--', $src],
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $out, 2 => ['pipe', 'w']],
        $pipes,
        dirname($xz)
    );
    if (!is_resource($process)) {
        fclose($out);
        @unlink($dest);
        throw new RuntimeException('xz could not be started.');
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    fclose($out);
    if ($code !== 0 || !is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        $detail = trim((string) $stderr);
        throw new RuntimeException('xz decompression failed.' . ($detail !== '' ? ' ' . $detail : ''));
    }
    if (filesize($dest) > epgMaxXmlBytes()) {
        @unlink($dest);
        throw new RuntimeException('The unpacked XML file is too large.');
    }
}

function epgCopyXml(string $src, string $dest): void
{
    $in = fopen($src, 'rb');
    $out = fopen($dest, 'wb');
    if ($in === false || $out === false) {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        throw new RuntimeException('Could not copy XML.');
    }
    $total = 0;
    try {
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Could not read XML.');
            }
            if ($chunk === '') {
                break;
            }
            $total += strlen($chunk);
            if ($total > epgMaxXmlBytes()) {
                throw new RuntimeException('The XML file is too large.');
            }
            fwrite($out, $chunk);
        }
    } catch (Throwable $e) {
        fclose($in);
        fclose($out);
        @unlink($dest);
        throw $e;
    }
    fclose($in);
    fclose($out);
}

function epgDecompressToXml(string $src, string $dest): void
{
    @unlink($dest);
    $kind = epgFileKind($src);
    if ($kind === 'gz' || str_ends_with(strtolower($src), '.gz')) {
        if ($kind === 'gz') {
            epgGunzip($src, $dest);

            return;
        }
    }
    if ($kind === 'gz') {
        epgGunzip($src, $dest);

        return;
    }
    if ($kind === 'xz') {
        epgUnpackXz($src, $dest);

        return;
    }
    if ($kind === 'xml') {
        epgCopyXml($src, $dest);

        return;
    }
    throw new RuntimeException('The file is not XML, gzip or xz.');
}

function epgXmlRoot(string $path): string
{
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Could not open the XML.');
    }
    $name = '';
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT) {
            $name = $reader->name;
            break;
        }
    }
    $reader->close();
    if ($name === '') {
        throw new RuntimeException('The XML file is empty.');
    }

    return $name;
}

function epgAtomicReplace(string $tmp, string $dest): void
{
    if (!is_file($tmp)) {
        throw new RuntimeException('The new file is missing.');
    }
    if (!is_file($dest)) {
        if (!rename($tmp, $dest)) {
            throw new RuntimeException('The file could not be saved.');
        }

        return;
    }
    $bak = $dest . '.bak';
    @unlink($bak);
    if (!rename($dest, $bak)) {
        throw new RuntimeException('The existing file could not be replaced.');
    }
    if (!rename($tmp, $dest)) {
        @rename($bak, $dest);
        throw new RuntimeException('The new file could not be moved into place.');
    }
    @unlink($bak);
}

function epgTemp(string $suffix): string
{
    epgEnsureDirs();

    return epgDir() . '/tmp/' . bin2hex(random_bytes(8)) . $suffix;
}

function epgWalkElements(string $path, callable $onElement): void
{
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Could not open the XML.');
    }
    try {
        $root = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $root = $reader->name;
                break;
            }
        }
        if ($root === '' || $reader->isEmptyElement) {
            return;
        }
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === $root) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                continue;
            }
            $name = $reader->name;
            $attributes = [];
            if ($reader->hasAttributes) {
                while ($reader->moveToNextAttribute()) {
                    $attributes[$reader->name] = $reader->value;
                }
                $reader->moveToElement();
            }
            $outer = ($name === 'channel' || $name === 'programme') ? $reader->readOuterXml() : '';
            $onElement($name, $attributes, $outer);
        }
    } finally {
        $reader->close();
    }
}

function epgChannelFromFragment(string $xml): ?array
{
    $element = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if ($element === false) {
        return null;
    }
    $id = trim((string) $element['id']);
    if ($id === '' || strlen($id) > 512) {
        return null;
    }
    $names = [];
    foreach ($element->{'display-name'} as $name) {
        $text = trim((string) $name);
        if ($text !== '' && strlen($text) <= 300) {
            $names[] = $text;
        }
    }
    if ($names === []) {
        $names[] = $id;
    }
    $icon = trim((string) ($element->icon['src'] ?? ''));

    return [
        'xmltv_id' => $id,
        'display_name' => $names[0],
        'alt_names' => array_values(array_slice($names, 1, 12)),
        'icon' => $icon !== '' ? $icon : null,
        'norm_name' => epgNormalizeName($names[0]),
    ];
}

function epgParseXmltv(int $sourceId, string $path): array
{
    $channels = [];
    $programmes = 0;
    epgWalkElements($path, static function (string $name, array $attributes, string $outer) use (&$channels, &$programmes): void {
        if ($name === 'channel') {
            $channel = epgChannelFromFragment($outer);
            if ($channel !== null) {
                $channels[$channel['xmltv_id']] = $channel;
            }

            return;
        }
        if ($name === 'programme') {
            $programmes++;
        }
    });
    if ($channels === []) {
        throw new RuntimeException('No XMLTV channels found.');
    }
    $db = epgDb();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM epg_channels WHERE source_id = ?')->execute([$sourceId]);
        $insert = $db->prepare('INSERT INTO epg_channels
            (source_id, xmltv_id, display_name, alt_names, norm_name, icon)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($channels as $channel) {
            $insert->execute([
                $sourceId,
                $channel['xmltv_id'],
                $channel['display_name'],
                json_encode($channel['alt_names'], JSON_UNESCAPED_UNICODE),
                $channel['norm_name'],
                $channel['icon'],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return ['channels' => count($channels), 'programmes' => $programmes];
}

function epgServiceKey(string $sref): string
{
    $parts = explode(':', canonicalServiceRef($sref));
    if (($parts[3] ?? '') === '' || strtoupper((string) $parts[3]) === '0') {
        return '';
    }

    return strtoupper($parts[3] . ':' . $parts[4] . ':' . $parts[5] . ':' . $parts[6]);
}

function epgRefFromText(string $text): string
{
    $text = trim($text);
    if (!preg_match('/^\d+:\d+:[0-9A-Fa-f]+:/', $text)) {
        return '';
    }

    return canonicalServiceRef($text);
}

function epgParseChannelsFile(string $path): int
{
    $rows = [];
    epgWalkElements($path, static function (string $name, array $attributes, string $outer) use (&$rows): void {
        if ($name !== 'channel') {
            return;
        }
        $element = @simplexml_load_string($outer, 'SimpleXMLElement', LIBXML_NONET);
        if ($element === false) {
            return;
        }
        $id = trim((string) $element['id']);
        $ref = epgRefFromText(trim((string) $element));
        if ($ref === '' && preg_match('/^\d+:\d+:[0-9A-Fa-f]+:/', $id)) {
            $ref = epgRefFromText($id);
            $id = trim((string) $element);
        }
        if ($id === '' || $ref === '' || strlen($id) > 512) {
            return;
        }
        if (!isset($rows[$ref])) {
            $rows[$ref] = ['ref' => $ref, 'key' => epgServiceKey($ref), 'id' => $id];
        }
    });
    if ($rows === []) {
        throw new RuntimeException('No service-reference mappings found.');
    }
    $db = epgDb();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM epg_service_map');
        $insert = $db->prepare('INSERT INTO epg_service_map (service_ref, service_key, xmltv_id) VALUES (?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute([$row['ref'], $row['key'], $row['id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return count($rows);
}

function epgRefreshChannelsFile(): int
{
    epgLog('EPG download started: channel mapping');
    $download = epgTemp('.download');
    $incoming = epgTemp('.xml');
    try {
        $used = epgDownloadFirst(epgChannelsUrls(), $download);
        epgDecompressToXml($download, $incoming);
        @unlink($download);
        if (epgXmlRoot($incoming) !== 'channels') {
            throw new RuntimeException('The mapping file is not a channels XML file.');
        }
        epgLog('Parsing started: channel mapping');
        $count = epgParseChannelsFile($incoming);
        epgAtomicReplace($incoming, epgChannelsXmlPath());
        epgStateSet('channels_updated_at', epgNow());
        epgStateSet('channels_error', '');
        epgStateSet('channels_size', (string) filesize(epgChannelsXmlPath()));
        epgStateSet('channels_count', (string) $count);
        epgLog('Parsing finished: channel mapping, ' . $count . ' service references via ' . $used);

        return $count;
    } catch (Throwable $e) {
        @unlink($download);
        @unlink($incoming);
        epgStateSet('channels_error', $e->getMessage());
        epgLog('Download failed: channel mapping: ' . $e->getMessage());
        throw $e;
    }
}

function epgRefreshSource(array $source, bool $replace = true): array
{
    $id = (int) $source['id'];
    $name = (string) $source['name'];
    epgLog('EPG download started: ' . $name);
    $now = epgNow();
    epgDb()->prepare('UPDATE epg_sources SET status = \'downloading\', last_attempt_at = ?, updated_at = ?, error = NULL WHERE id = ?')
        ->execute([$now, $now, $id]);
    $download = epgTemp('.download');
    $incoming = epgTemp('.xml');
    try {
        $used = epgDownloadFirst(epgSourceUrls($source), $download);
        epgDecompressToXml($download, $incoming);
        @unlink($download);
        $root = epgXmlRoot($incoming);
        if ($root !== 'tv') {
            throw new RuntimeException('Unexpected XML root tag: ' . $root);
        }
        epgLog('Parsing started: ' . $name);
        if ($replace) {
            $counts = epgParseXmltv($id, $incoming);
            epgAtomicReplace($incoming, epgSourceXmlPath($id));
        } else {
            $counts = epgCountXmltv($incoming);
            @unlink($incoming);
        }
        $size = $replace && is_file(epgSourceXmlPath($id)) ? (int) filesize(epgSourceXmlPath($id)) : 0;
        epgDb()->prepare('UPDATE epg_sources SET status = \'ok\', error = NULL, last_success_at = ?, last_attempt_at = ?,
            file_size = ?, channel_count = ?, programme_count = ?, updated_at = ? WHERE id = ?')->execute([
            epgNow(),
            epgNow(),
            $size,
            $counts['channels'],
            $counts['programmes'],
            epgNow(),
            $id,
        ]);
        epgLog('Download finished: ' . $name . ' via ' . $used);
        epgLog('Parsing finished: ' . $name);
        epgLog('XMLTV channels: ' . $counts['channels'] . ' (' . $name . ')');
        epgLog('Programmes: ' . $counts['programmes'] . ' (' . $name . ')');

        return $counts;
    } catch (Throwable $e) {
        @unlink($download);
        @unlink($incoming);
        epgDb()->prepare('UPDATE epg_sources SET status = \'error\', error = ?, last_attempt_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$e->getMessage(), epgNow(), epgNow(), $id]);
        epgLog('Download failed: ' . $name . ': ' . $e->getMessage());
        throw $e;
    }
}

function epgCountXmltv(string $path): array
{
    $channels = 0;
    $programmes = 0;
    epgWalkElements($path, static function (string $name) use (&$channels, &$programmes): void {
        if ($name === 'channel') {
            $channels++;
        } elseif ($name === 'programme') {
            $programmes++;
        }
    });
    if ($channels === 0) {
        throw new RuntimeException('No XMLTV channels found.');
    }

    return ['channels' => $channels, 'programmes' => $programmes];
}

function epgTestSource(int $id): array
{
    $source = epgSourceById($id);

    return epgWithLock(static function () use ($source): array {
        $download = epgTemp('.download');
        $incoming = epgTemp('.xml');
        try {
            epgDownloadFirst(epgSourceUrls($source), $download);
            epgDecompressToXml($download, $incoming);
            @unlink($download);
            if (epgXmlRoot($incoming) !== 'tv') {
                throw new RuntimeException('The file is not an XMLTV guide.');
            }
            $counts = epgCountXmltv($incoming);
            @unlink($incoming);
            epgLog('Test passed: ' . $source['name'] . ', ' . $counts['channels'] . ' channels');

            return $counts;
        } catch (Throwable $e) {
            @unlink($download);
            @unlink($incoming);
            epgLog('Test failed: ' . $source['name'] . ': ' . $e->getMessage());
            throw $e;
        }
    });
}

function epgNormalizeName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = strtr($name, ['&' => ' ', '+' => ' ', '/' => ' ']);
    $withoutRegion = $name;
    $previous = null;
    while ($previous !== $withoutRegion) {
        $previous = $withoutRegion;
        $withoutRegion = preg_replace('/[\s.\-_]*\b(uhd|fhd|hd|sd|4k|hevc|h265|hdr)\b\s*$/u', '', $withoutRegion) ?? $withoutRegion;
        $withoutRegion = preg_replace('/[\s.\-_]*\b(nederland|netherlands|vlaanderen|flanders|belgie|belgië|belgium|deutschland|germany|italia|italy)\b\s*$/u', '', $withoutRegion) ?? $withoutRegion;
    }
    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $withoutRegion) ?? '';
    if (mb_strlen($normalized) >= 3) {
        return $normalized;
    }
    $previous = null;
    while ($previous !== $name) {
        $previous = $name;
        $name = preg_replace('/[\s.\-_]*\b(uhd|fhd|hd|sd|4k|hevc|h265|hdr)\b\s*$/u', '', $name) ?? $name;
    }

    return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
}

function epgExactKey(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return $name;
}

function epgAliasKey(string $normalized): ?string
{
    static $aliases = [
        'bbcone' => 'bbc1',
        'bbc1' => 'bbc1',
        'bbctwo' => 'bbc2',
        'bbc2' => 'bbc2',
        'bbcthree' => 'bbc3',
        'bbc3' => 'bbc3',
        'bbcfour' => 'bbc4',
        'bbc4' => 'bbc4',
        'itv1' => 'itv',
        'itv' => 'itv',
        'channel4' => 'c4',
        'c4' => 'c4',
        'channel5' => 'five',
        'five' => 'five',
        'daserste' => 'ard',
        'ard' => 'ard',
        'zdf' => 'zdf',
        'prosieben' => 'pro7',
        'pro7' => 'pro7',
        'kabeleins' => 'kabel1',
        'kabel1' => 'kabel1',
        'sat1' => 'sat1',
        'npo1' => 'npo1',
        'npo2' => 'npo2',
        'npo3' => 'npo3',
        'rtl4' => 'rtl4',
        'rtl5' => 'rtl5',
        'rtl7' => 'rtl7',
        'rtl8' => 'rtl8',
        'sbs6' => 'sbs6',
        'sbs9' => 'sbs9',
        'net5' => 'net5',
        'een' => 'vrt1',
        'vrt1' => 'vrt1',
        'vrtcanvas' => 'canvas',
        'canvas' => 'canvas',
        'ketnet' => 'ketnet',
        'vrtketnet' => 'ketnet',
        'rai1' => 'rai1',
        'rai2' => 'rai2',
        'rai3' => 'rai3',
        'raiuno' => 'rai1',
        'raidue' => 'rai2',
        'raitre' => 'rai3',
        'canale5' => 'canale5',
        'italia1' => 'italia1',
        'rete4' => 'rete4',
        'la7' => 'la7',
    ];

    return $aliases[$normalized] ?? null;
}

function epgPlaylistByRef(): array
{
    $byRef = [];
    foreach (playlistRows(fetchAllServices(), null, 'all') as $row) {
        $ref = (string) ($row['sref'] ?? '');
        if ($ref === '' || isset($byRef[$ref])) {
            continue;
        }
        $byRef[$ref] = $row;
    }

    return $byRef;
}

function epgChannelIndex(): array
{
    $exact = [];
    $normal = [];
    $alias = [];
    $byId = [];
    $sql = 'SELECT c.xmltv_id, c.display_name, c.alt_names, c.norm_name, c.source_id, s.name AS source_name
        FROM epg_channels c
        JOIN epg_sources s ON s.id = c.source_id
        WHERE s.enabled = 1
        ORDER BY s.priority ASC, s.id ASC';
    foreach (epgDb()->query($sql) as $row) {
        $id = (string) $row['xmltv_id'];
        if (!isset($byId[$id])) {
            $byId[$id] = $row;
        }
        $names = [(string) $row['display_name']];
        $alts = json_decode((string) $row['alt_names'], true);
        if (is_array($alts)) {
            foreach ($alts as $alt) {
                if (is_string($alt) && $alt !== '') {
                    $names[] = $alt;
                }
            }
        }
        foreach ($names as $name) {
            $exact[epgExactKey($name)][$id] = $row;
            $norm = epgNormalizeName($name);
            if ($norm !== '') {
                $normal[$norm][$id] = $row;
                $aliasKey = epgAliasKey($norm);
                if ($aliasKey !== null) {
                    $alias[$aliasKey][$id] = $row;
                }
            }
        }
    }

    return ['exact' => $exact, 'normal' => $normal, 'alias' => $alias, 'by_id' => $byId];
}

function epgServiceIndexes(): array
{
    $byRef = [];
    $byKey = [];
    foreach (epgDb()->query('SELECT service_ref, service_key, xmltv_id FROM epg_service_map') as $row) {
        $byRef[(string) $row['service_ref']] = (string) $row['xmltv_id'];
        $key = (string) $row['service_key'];
        if ($key !== '') {
            $byKey[$key][(string) $row['xmltv_id']] = true;
        }
    }

    return ['ref' => $byRef, 'key' => $byKey];
}

function epgCandidateList(array $bucket): array
{
    $candidates = [];
    foreach ($bucket as $id => $row) {
        $candidates[] = [
            'xmltv_id' => (string) $id,
            'display_name' => (string) $row['display_name'],
        ];
        if (count($candidates) >= 5) {
            break;
        }
    }

    return $candidates;
}

function epgUniqueBucket(array $bucket): ?array
{
    if (count($bucket) === 1) {
        return reset($bucket) ?: null;
    }

    return null;
}

function epgFuzzyMatch(string $normalized, array $normal, float $threshold): array
{
    if (strlen($normalized) < 4) {
        return ['kind' => 'none'];
    }
    $bestScore = 0.0;
    $second = 0.0;
    $best = [];
    $prefix = $normalized[0];
    $length = strlen($normalized);
    foreach ($normal as $candidate => $bucket) {
        $candidate = (string) $candidate;
        if ($candidate === '' || $candidate[0] !== $prefix) {
            continue;
        }
        $candidateLength = strlen($candidate);
        if ($candidateLength < 4 || abs($candidateLength - $length) > max(3, (int) ($length * 0.35))) {
            continue;
        }
        similar_text($normalized, $candidate, $percent);
        $score = ((float) $percent) / 100;
        if ($score > $bestScore) {
            $second = $bestScore;
            $bestScore = $score;
            $best = $bucket;
        } elseif ($score > $second) {
            $second = $score;
        }
    }
    if ($bestScore < $threshold) {
        return ['kind' => 'none'];
    }
    $unique = epgUniqueBucket($best);
    if ($unique === null || ($bestScore - $second) < 0.03) {
        return [
            'kind' => 'conflict',
            'confidence' => $bestScore,
            'candidates' => epgCandidateList($best),
        ];
    }

    return ['kind' => 'match', 'type' => 'fuzzy', 'confidence' => $bestScore, 'row' => $unique];
}

function epgNamesDisagree(string $serviceName, string $epgName): bool
{
    $service = epgNormalizeName($serviceName);
    $epg = epgNormalizeName($epgName);
    if ($service === '' || $epg === '' || $service === $epg) {
        return false;
    }
    $service = epgAliasKey($service) ?? $service;
    $epg = epgAliasKey($epg) ?? $epg;
    if ($service === $epg || str_contains($service, $epg) || str_contains($epg, $service)) {
        return false;
    }

    return true;
}

function epgServiceRefDecision(string $xmltvId, string $serviceName, array $channels): array
{
    $row = $channels['by_id'][$xmltvId] ?? null;
    if (is_array($row) && epgNamesDisagree($serviceName, (string) $row['display_name'])) {
        return [
            'type' => 'service-ref',
            'status' => 'CONFLICT',
            'confidence' => 1,
            'candidates' => epgCandidateList([$xmltvId => $row]),
        ];
    }

    return ['type' => 'service-ref', 'status' => 'MATCHED', 'confidence' => 1, 'xmltv_id' => $xmltvId, 'row' => $row];
}

function epgDecideMatch(array $service, array $channels, array $serviceMap, float $threshold): array
{
    $ref = (string) $service['sref'];
    $name = (string) ($service['match_name'] ?? $service['name'] ?? '');
    if (isset($serviceMap['ref'][$ref])) {
        return epgServiceRefDecision((string) $serviceMap['ref'][$ref], $name, $channels);
    }
    $key = epgServiceKey($ref);
    if ($key !== '' && isset($serviceMap['key'][$key]) && count($serviceMap['key'][$key]) === 1) {
        return epgServiceRefDecision((string) array_key_first($serviceMap['key'][$key]), $name, $channels);
    }
    $exact = $channels['exact'][epgExactKey($name)] ?? [];
    $unique = epgUniqueBucket($exact);
    if ($unique !== null) {
        return ['type' => 'exact', 'status' => 'MATCHED', 'confidence' => 1, 'row' => $unique];
    }
    if (count($exact) > 1) {
        return ['type' => 'exact', 'status' => 'CONFLICT', 'confidence' => 1, 'candidates' => epgCandidateList($exact)];
    }
    $normalized = epgNormalizeName($name);
    $normal = $normalized === '' ? [] : ($channels['normal'][$normalized] ?? []);
    $unique = epgUniqueBucket($normal);
    if ($unique !== null) {
        return ['type' => 'normalized', 'status' => 'MATCHED', 'confidence' => 0.98, 'row' => $unique];
    }
    if (count($normal) > 1) {
        return ['type' => 'normalized', 'status' => 'CONFLICT', 'confidence' => 0.98, 'candidates' => epgCandidateList($normal)];
    }
    $aliasKey = $normalized === '' ? null : epgAliasKey($normalized);
    $alias = $aliasKey === null ? [] : ($channels['alias'][$aliasKey] ?? []);
    $unique = epgUniqueBucket($alias);
    if ($unique !== null) {
        return ['type' => 'alias', 'status' => 'MATCHED', 'confidence' => 0.95, 'row' => $unique];
    }
    if (count($alias) > 1) {
        return ['type' => 'alias', 'status' => 'CONFLICT', 'confidence' => 0.95, 'candidates' => epgCandidateList($alias)];
    }
    if ($normalized !== '') {
        $fuzzy = epgFuzzyMatch($normalized, $channels['normal'], $threshold);
        if ($fuzzy['kind'] === 'match') {
            return [
                'type' => 'fuzzy',
                'status' => 'MATCHED',
                'confidence' => $fuzzy['confidence'],
                'row' => $fuzzy['row'],
            ];
        }
        if ($fuzzy['kind'] === 'conflict') {
            return [
                'type' => 'fuzzy',
                'status' => 'CONFLICT',
                'confidence' => $fuzzy['confidence'],
                'candidates' => $fuzzy['candidates'],
            ];
        }
    }

    return ['type' => 'none', 'status' => 'UNMATCHED', 'confidence' => null];
}

function epgResetTvgCache(): void
{
    playlistTvgIdsReset();
}

function epgRematchAll(): array
{
    epgLog('Matching started');
    $playlist = epgPlaylistByRef();
    $channels = epgChannelIndex();
    $serviceMap = epgServiceIndexes();
    $threshold = (float) epgSettings()['epg_fuzzy_threshold'];
    $manual = [];
    foreach (epgDb()->query("SELECT * FROM epg_mappings WHERE status = 'MANUAL'") as $row) {
        $manual[(string) $row['service_ref']] = $row;
    }
    $stats = ['playlist' => count($playlist), 'matched' => 0, 'manual' => 0, 'unmatched' => 0, 'conflicts' => 0];
    $db = epgDb();
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM epg_mappings WHERE status != 'MANUAL'");
        $touch = $db->prepare('UPDATE epg_mappings SET service_name = ?, bouquet = ?, satellite = ?, updated_at = ? WHERE service_ref = ?');
        $insert = $db->prepare('INSERT INTO epg_mappings
            (service_ref, service_name, bouquet, satellite, xmltv_id, source_id, source_name, match_type, confidence, status, candidates, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = epgNow();
        foreach ($playlist as $ref => $service) {
            if (isset($manual[$ref])) {
                $touch->execute([
                    (string) ($service['match_name'] ?? $service['name']),
                    (string) ($service['group'] ?? ''),
                    (string) ($service['satellite'] ?? ''),
                    $now,
                    $ref,
                ]);
                $stats['manual']++;
                continue;
            }
            $decision = epgDecideMatch($service, $channels, $serviceMap, $threshold);
            $row = $decision['row'] ?? null;
            $xmltvId = (string) ($decision['xmltv_id'] ?? ($row['xmltv_id'] ?? ''));
            if ($xmltvId !== '' && $row === null && isset($channels['by_id'][$xmltvId])) {
                $row = $channels['by_id'][$xmltvId];
            }
            $status = (string) $decision['status'];
            if ($status === 'MATCHED') {
                $stats['matched']++;
            } elseif ($status === 'CONFLICT') {
                $stats['conflicts']++;
            } else {
                $stats['unmatched']++;
            }
            $insert->execute([
                $ref,
                (string) ($service['match_name'] ?? $service['name']),
                (string) ($service['group'] ?? ''),
                (string) ($service['satellite'] ?? ''),
                $xmltvId !== '' ? $xmltvId : null,
                isset($row['source_id']) ? (int) $row['source_id'] : null,
                isset($row['source_name']) ? (string) $row['source_name'] : null,
                (string) $decision['type'],
                $decision['confidence'],
                $status,
                isset($decision['candidates']) ? json_encode($decision['candidates'], JSON_UNESCAPED_UNICODE) : null,
                $now,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    epgResetTvgCache();
    epgLog('Automatic matches: ' . $stats['matched']);
    epgLog('Manual matches: ' . $stats['manual']);
    epgLog('Unmatched channels: ' . $stats['unmatched']);
    epgLog('Conflicts: ' . $stats['conflicts']);

    return $stats;
}

function epgSaveManual(string $sref, string $xmltvId): void
{
    $sref = canonicalServiceRef($sref);
    $xmltvId = trim($xmltvId);
    if ($sref === '' || $xmltvId === '' || strlen($xmltvId) > 512) {
        throw new InvalidArgumentException('Choose a channel and an XMLTV channel.');
    }
    $stmt = epgDb()->prepare('SELECT c.xmltv_id, c.source_id, s.name AS source_name, c.display_name
        FROM epg_channels c JOIN epg_sources s ON s.id = c.source_id
        WHERE c.xmltv_id = ? ORDER BY s.priority ASC, s.id ASC LIMIT 1');
    $stmt->execute([$xmltvId]);
    $channel = $stmt->fetch();
    if (!is_array($channel)) {
        throw new InvalidArgumentException('This XMLTV channel is not in the downloaded guide.');
    }
    $playlist = epgPlaylistByRef();
    $service = $playlist[$sref] ?? null;
    $name = is_array($service) ? (string) ($service['match_name'] ?? $service['name']) : (string) $channel['display_name'];
    $bouquet = is_array($service) ? (string) ($service['group'] ?? '') : '';
    $satellite = is_array($service) ? (string) ($service['satellite'] ?? '') : satelliteFromSref($sref);
    epgDb()->prepare('INSERT INTO epg_mappings
        (service_ref, service_name, bouquet, satellite, xmltv_id, source_id, source_name, match_type, confidence, status, candidates, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\', 1, \'MANUAL\', NULL, ?)
        ON CONFLICT(service_ref) DO UPDATE SET
            service_name = excluded.service_name,
            bouquet = excluded.bouquet,
            satellite = excluded.satellite,
            xmltv_id = excluded.xmltv_id,
            source_id = excluded.source_id,
            source_name = excluded.source_name,
            match_type = \'manual\',
            confidence = 1,
            status = \'MANUAL\',
            candidates = NULL,
            updated_at = excluded.updated_at')->execute([
        $sref,
        $name,
        $bouquet,
        $satellite,
        $xmltvId,
        (int) $channel['source_id'],
        (string) $channel['source_name'],
        epgNow(),
    ]);
    epgResetTvgCache();
    epgLog('Manual mapping saved: ' . $name . ' -> ' . $xmltvId);
}

function epgClearManual(string $sref): void
{
    $sref = canonicalServiceRef($sref);
    epgDb()->prepare('DELETE FROM epg_mappings WHERE service_ref = ?')->execute([$sref]);
    epgResetTvgCache();
}

function playlistTvgIdsReset(): void
{
    $GLOBALS['playlist_tvg_reset'] = true;
}

function epgTvgIdMap(): array
{
    $map = [];
    $stmt = epgDb()->query("SELECT service_ref, xmltv_id FROM epg_mappings
        WHERE xmltv_id IS NOT NULL AND xmltv_id != '' AND status IN ('MATCHED', 'MANUAL')");
    foreach ($stmt as $row) {
        $map[(string) $row['service_ref']] = (string) $row['xmltv_id'];
    }

    return $map;
}

function epgXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function epgGenerateXml(): array
{
    @ini_set('memory_limit', '512M');
    $playlist = epgPlaylistByRef();
    $wanted = [];
    $names = [];
    foreach (epgDb()->query("SELECT service_ref, xmltv_id FROM epg_mappings
        WHERE xmltv_id IS NOT NULL AND xmltv_id != '' AND status IN ('MATCHED', 'MANUAL')") as $row) {
        $service = $playlist[(string) $row['service_ref']] ?? null;
        if (!is_array($service)) {
            continue;
        }
        $id = (string) $row['xmltv_id'];
        $wanted[$id] = true;
        if (!isset($names[$id])) {
            $names[$id] = (string) ($service['match_name'] ?? $service['name'] ?? $id);
        }
    }
    $owners = [];
    $meta = [];
    if ($wanted !== []) {
        $sql = 'SELECT c.xmltv_id, c.display_name, c.alt_names, c.icon, c.source_id
            FROM epg_channels c
            JOIN epg_sources s ON s.id = c.source_id
            WHERE s.enabled = 1
            ORDER BY s.priority ASC, s.id ASC';
        foreach (epgDb()->query($sql) as $row) {
            $id = (string) $row['xmltv_id'];
            if (!isset($wanted[$id]) || isset($meta[$id])) {
                continue;
            }
            $meta[$id] = $row;
            $owners[$id] = (int) $row['source_id'];
        }
    }
    epgEnsureDirs();
    $tmp = epgXmlPath() . '.tmp';
    $handle = fopen($tmp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('The temporary EPG file could not be created.');
    }
    fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($handle, "<tv generator-info-name=\"E2 naar M3U8\">\n");
    foreach (array_keys($wanted) as $id) {
        fwrite($handle, '  <channel id="' . epgXmlEscape($id) . "\">\n");
        $display = isset($meta[$id]) ? (string) $meta[$id]['display_name'] : ($names[$id] ?? $id);
        fwrite($handle, '    <display-name>' . epgXmlEscape($display) . "</display-name>\n");
        $iconLabels = [$display];
        if (isset($names[$id])) {
            $iconLabels[] = $names[$id];
        }
        if (isset($meta[$id])) {
            $iconAlts = json_decode((string) $meta[$id]['alt_names'], true);
            if (is_array($iconAlts)) {
                foreach ($iconAlts as $iconAlt) {
                    if (is_string($iconAlt) && $iconAlt !== '') {
                        $iconLabels[] = $iconAlt;
                    }
                }
            }
        }
        $icon = logoUrlForXmltvId($id, $iconLabels);
        if ($icon === '' && isset($meta[$id])) {
            $icon = trim((string) ($meta[$id]['icon'] ?? ''));
        }
        if ($icon !== '') {
            fwrite($handle, '    <icon src="' . epgXmlEscape($icon) . "\"/>\n");
        }
        if (isset($meta[$id])) {
            $alts = json_decode((string) $meta[$id]['alt_names'], true);
            if (is_array($alts)) {
                foreach ($alts as $alt) {
                    if (is_string($alt) && $alt !== '') {
                        fwrite($handle, '    <display-name>' . epgXmlEscape($alt) . "</display-name>\n");
                    }
                }
            }
        }
        fwrite($handle, "  </channel>\n");
    }
    $programmes = 0;
    $seen = [];
    foreach (epgEnabledSources() as $source) {
        $path = epgSourceXmlPath((int) $source['id']);
        if (!is_file($path)) {
            continue;
        }
        $sourceId = (int) $source['id'];
        epgWalkElements($path, static function (string $name, array $attributes, string $outer) use ($handle, $owners, $sourceId, &$programmes, &$seen): void {
            if ($name !== 'programme') {
                return;
            }
            $channel = (string) ($attributes['channel'] ?? '');
            if ($channel === '' || ($owners[$channel] ?? null) !== $sourceId) {
                return;
            }
            $key = $channel . '|' . ($attributes['start'] ?? '') . '|' . ($attributes['stop'] ?? '');
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            fwrite($handle, $outer . "\n");
            $programmes++;
        });
    }
    fwrite($handle, "</tv>\n");
    fclose($handle);
    try {
        epgAtomicReplace($tmp, epgXmlPath());
        epgLog('Generating epg.xml');
        epgGzipFile(epgXmlPath(), epgGzPath());
        epgLog('Generating epg.xml.gz');
    } catch (Throwable $e) {
        @unlink($tmp);
        epgStateSet('generated_error', $e->getMessage());
        throw $e;
    }
    $channels = count($wanted);
    epgStateSet('generated_at', epgNow());
    epgStateSet('generated_channels', (string) $channels);
    epgStateSet('generated_programmes', (string) $programmes);
    epgStateSet('generated_error', '');
    epgLog('XMLTV channels: ' . $channels);
    epgLog('Programmes: ' . $programmes);

    return ['channels' => $channels, 'programmes' => $programmes];
}

function epgGzipFile(string $src, string $dest): void
{
    $in = fopen($src, 'rb');
    $tmp = $dest . '.tmp';
    $out = gzopen($tmp, 'wb6');
    if ($in === false || $out === false) {
        if (is_resource($in)) {
            fclose($in);
        }
        throw new RuntimeException('epg.xml.gz could not be created.');
    }
    while (!feof($in)) {
        $chunk = fread($in, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        if ($chunk !== '') {
            gzwrite($out, $chunk);
        }
    }
    fclose($in);
    gzclose($out);
    epgAtomicReplace($tmp, $dest);
}

function eitReceiverNow(): int
{
    $now = time();
    try {
        $clock = webifJson('/api/currenttime', 15);
    } catch (Throwable $e) {
        return $now;
    }
    $time = (string) ($clock['time'] ?? '');
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $match) !== 1) {
        return $now;
    }
    $box = ((int) $match[1]) * 3600 + ((int) $match[2]) * 60 + (int) $match[3];
    $here = ((int) date('G', $now)) * 3600 + ((int) date('i', $now)) * 60 + (int) date('s', $now);
    $delta = $box - $here;
    if ($delta > 12 * 3600) {
        $delta -= 86400;
    }
    if ($delta < -12 * 3600) {
        $delta += 86400;
    }

    return $now + $delta;
}

function eitGenerateToday(): array
{
    @ini_set('memory_limit', '512M');
    epgLog('Reading the receiver clock');
    $now = eitReceiverNow();
    $start = strtotime(date('Y-m-d 00:00:00', $now));
    if ($start === false) {
        $start = $now - ($now % 86400);
    }
    $end = $start + 86400;
    epgLog('Reading channels from the receiver');
    $rows = playlistRows(fetchAllServices(), null, 'all');
    $tvg = epgTvgIdMap();
    $bouquets = [];
    $names = [];
    foreach ($rows as $row) {
        $sref = (string) ($row['sref'] ?? '');
        $xmltv = $tvg[$sref] ?? '';
        if ($sref === '' || $xmltv === '') {
            continue;
        }
        $group = (string) ($row['group'] ?? 'Bouquet');
        $bouquets[$group][$sref] = $xmltv;
        if (!isset($names[$xmltv])) {
            $names[$xmltv] = (string) ($row['match_name'] ?? $row['name'] ?? $xmltv);
        }
    }
    $events = [];
    $seenRef = [];
    foreach ($bouquets as $group => $channels) {
        epgLog('Reading bouquet ' . $group);
        foreach ($channels as $sref => $xmltv) {
            if (isset($seenRef[$sref])) {
                continue;
            }
            $seenRef[$sref] = true;
            try {
                $data = webifJson(
                    '/api/epgservice?sRef=' . rawurlencode($sref) . '&time=' . $start . '&endTime=' . $end,
                    25
                );
            } catch (Throwable $e) {
                epgLog('Skipped ' . $names[$xmltv] . ': ' . $e->getMessage());
                continue;
            }
            foreach ($data['events'] ?? [] as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $begin = (int) ($event['begin_timestamp'] ?? 0);
                $duration = (int) ($event['duration_sec'] ?? 0);
                $title = trim((string) ($event['title'] ?? ''));
                if ($begin <= 0 || $duration <= 0 || $title === '' || strcasecmp($title, 'N/A') === 0) {
                    continue;
                }
                if ($begin >= $end || ($begin + $duration) <= $start) {
                    continue;
                }
                $desc = trim((string) ($event['longdesc'] ?? ''));
                if ($desc === '') {
                    $desc = trim((string) ($event['shortdesc'] ?? ''));
                }
                $key = $xmltv . '|' . $begin . '|' . $duration;
                $events[$key] = [
                    'channel' => $xmltv,
                    'begin' => $begin,
                    'stop' => $begin + $duration,
                    'title' => $title,
                    'desc' => $desc,
                ];
            }
        }
    }
    epgEnsureDirs();
    epgLog('Generating epg-eit.xml');
    $tmp = eitXmlPath() . '.tmp';
    $handle = fopen($tmp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('The temporary EIT file could not be created.');
    }
    fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($handle, "<tv generator-info-name=\"E2 naar M3U8\">\n");
    foreach ($names as $id => $display) {
        fwrite($handle, '  <channel id="' . epgXmlEscape($id) . "\">\n");
        fwrite($handle, '    <display-name>' . epgXmlEscape($display) . "</display-name>\n");
        fwrite($handle, "  </channel>\n");
    }
    foreach ($events as $event) {
        $startText = gmdate('YmdHis', $event['begin']) . ' +0000';
        $stopText = gmdate('YmdHis', $event['stop']) . ' +0000';
        fwrite($handle, '  <programme start="' . $startText . '" stop="' . $stopText . '" channel="' . epgXmlEscape($event['channel']) . "\">\n");
        fwrite($handle, '    <title>' . epgXmlEscape($event['title']) . "</title>\n");
        if ($event['desc'] !== '') {
            fwrite($handle, '    <desc>' . epgXmlEscape($event['desc']) . "</desc>\n");
        }
        fwrite($handle, "  </programme>\n");
    }
    fwrite($handle, "</tv>\n");
    fclose($handle);
    try {
        epgAtomicReplace($tmp, eitXmlPath());
        epgGzipFile(eitXmlPath(), eitGzPath());
        epgLog('Generating epg-eit.xml.gz');
    } catch (Throwable $e) {
        @unlink($tmp);
        epgStateSet('eit_error', $e->getMessage());
        throw $e;
    }
    $channelCount = count($names);
    $programmeCount = count($events);
    epgStateSet('eit_generated_at', epgNow());
    epgStateSet('eit_channels', (string) $channelCount);
    epgStateSet('eit_programmes', (string) $programmeCount);
    epgStateSet('eit_error', '');
    epgLog('EIT channels: ' . $channelCount);
    epgLog('EIT programmes: ' . $programmeCount);

    return ['channels' => $channelCount, 'programmes' => $programmeCount];
}

function epgHistoryStart(string $trigger): int
{
    epgDb()->prepare('INSERT INTO epg_update_history (started_at, trigger, status) VALUES (?, ?, \'running\')')
        ->execute([epgNow(), $trigger]);

    return (int) epgDb()->lastInsertId();
}

function epgHistoryFinish(int $id, string $status, string $message, array $stats): void
{
    epgDb()->prepare('UPDATE epg_update_history
        SET finished_at = ?, status = ?, message = ?, channels = ?, programmes = ?, matched = ?, manual_count = ?, unmatched = ?, conflicts = ?
        WHERE id = ?')->execute([
        epgNow(),
        $status,
        $message,
        $stats['channels'] ?? null,
        $stats['programmes'] ?? null,
        $stats['matched'] ?? null,
        $stats['manual'] ?? null,
        $stats['unmatched'] ?? null,
        $stats['conflicts'] ?? null,
        $id,
    ]);
}

function epgLastHistory(string $status): ?array
{
    $stmt = epgDb()->prepare('SELECT * FROM epg_update_history WHERE status = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$status]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function epgUpdateIsDue(): bool
{
    $interval = ((int) epgSettings()['epg_interval_hours']) * 3600;
    $ok = epgLastHistory('ok');
    $running = epgLastHistory('running');
    if (is_array($running) && $running['finished_at'] === null) {
        $started = strtotime((string) $running['started_at']) ?: 0;
        if ($started > time() - 7200) {
            return false;
        }
    }
    if (!is_array($ok) || $ok['finished_at'] === null) {
        $error = epgLastHistory('error');
        if (is_array($error) && $error['finished_at'] !== null) {
            $tried = strtotime((string) $error['finished_at']) ?: 0;
            if (time() - $tried < 1800) {
                return false;
            }
        }

        return true;
    }
    $finished = strtotime((string) $ok['finished_at']) ?: 0;

    return time() - $finished >= $interval;
}

function epgRunUpdate(string $trigger, bool $force): array
{
    if (!$force && !epgUpdateIsDue()) {
        return ['skipped' => true];
    }
    $lock = epgAcquireLock(false);
    if ($lock === null) {
        epgLog('EPG download skipped: an update is already running');

        return ['skipped' => true, 'reason' => 'bezig'];
    }
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $historyId = 0;
    try {
        epgDb()->exec("UPDATE epg_sources SET status = 'error', error = 'Download interrupted.' WHERE status = 'downloading'");
        $historyId = epgHistoryStart($trigger);
        epgLog('EPG download started');
        $sourceErrors = [];
        try {
            epgRefreshChannelsFile();
        } catch (Throwable $e) {
            $sourceErrors[] = 'Channel mapping: ' . $e->getMessage();
        }
        foreach (epgEnabledSources() as $source) {
            try {
                epgRefreshSource($source);
            } catch (Throwable $e) {
                $sourceErrors[] = $source['name'] . ': ' . $e->getMessage();
            }
        }
        $stats = ['matched' => 0, 'manual' => 0, 'unmatched' => 0, 'conflicts' => 0, 'channels' => 0, 'programmes' => 0];
        try {
            $match = epgRematchAll();
            $stats = array_merge($stats, $match);
        } catch (Throwable $e) {
            $sourceErrors[] = 'Koppelen: ' . $e->getMessage();
            epgLog('Matching failed: ' . $e->getMessage());
        }
        try {
            $generated = epgGenerateXml();
            $stats['channels'] = $generated['channels'];
            $stats['programmes'] = $generated['programmes'];
        } catch (Throwable $e) {
            $sourceErrors[] = 'XML: ' . $e->getMessage();
            epgLog('Generating epg.xml failed: ' . $e->getMessage());
            epgHistoryFinish($historyId, 'error', implode("\n", $sourceErrors), $stats);
            throw $e;
        }
        $published = (($stats['channels'] ?? 0) > 0) || (($stats['programmes'] ?? 0) > 0);
        $message = $sourceErrors === [] ? 'Updated.' : implode("\n", $sourceErrors);
        epgHistoryFinish($historyId, ($sourceErrors === [] || $published) ? 'ok' : 'error', $message, $stats);
        if ($sourceErrors !== [] && !$published) {
            throw new RuntimeException($message);
        }

        return $stats;
    } catch (Throwable $e) {
        if ($historyId > 0) {
            $open = epgDb()->prepare('SELECT finished_at FROM epg_update_history WHERE id = ?');
            $open->execute([$historyId]);
            $finished = $open->fetchColumn();
            if ($finished === null || $finished === false) {
                epgHistoryFinish($historyId, 'error', $e->getMessage(), []);
            }
        }
        throw $e;
    } finally {
        epgReleaseLock($lock);
    }
}

function epgPhpBinary(): string
{
    $candidate = 'C:\\xampp\\php\\php.exe';
    if (is_file($candidate)) {
        return $candidate;
    }
    $binary = PHP_BINARY;
    if (is_string($binary) && is_file($binary) && stripos($binary, 'php') !== false) {
        return $binary;
    }

    return $candidate;
}

function epgSpawnUpdate(): void
{
    $php = epgPhpBinary();
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'epg-update.php';
    $command = 'cmd /c start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script);
    $handle = popen($command, 'r');
    if (is_resource($handle)) {
        pclose($handle);
    }
}

function epgKickUpdateIfDue(): void
{
    try {
        if (PHP_SAPI === 'cli' || !epgUpdateIsDue()) {
            return;
        }
        $lock = epgAcquireLock(false);
        if ($lock === null) {
            return;
        }
        epgReleaseLock($lock);
        epgSpawnUpdate();
    } catch (Throwable $e) {
        epgLog('Could not start the automatic update: ' . $e->getMessage());
    }
}

function epgFormatTime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }

    return date('j M Y H:i', $time);
}

function epgFormatBytes(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return '—';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, '.', ',') . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 1, '.', ',') . ' MB';
}

function epgFormatNumber(int $value): string
{
    return number_format($value, 0, '.', ',');
}

function epgNextUpdateText(): string
{
    $ok = epgLastHistory('ok');
    if (!is_array($ok) || $ok['finished_at'] === null) {
        return 'on the next playlist or EPG request';
    }
    $finished = strtotime((string) $ok['finished_at']);
    if ($finished === false) {
        return '—';
    }
    $next = $finished + ((int) epgSettings()['epg_interval_hours']) * 3600;

    return date('d-m-Y H:i', $next);
}

function epgDashboard(): array
{
    $warning = null;
    $playlistCount = null;
    $counts = ['MATCHED' => 0, 'MANUAL' => 0, 'UNMATCHED' => 0, 'CONFLICT' => 0];
    try {
        $playlist = epgPlaylistByRef();
        $playlistCount = count($playlist);
        $mappings = [];
        foreach (epgDb()->query('SELECT service_ref, status FROM epg_mappings') as $row) {
            $mappings[(string) $row['service_ref']] = (string) $row['status'];
        }
        foreach (array_keys($playlist) as $ref) {
            $status = $mappings[$ref] ?? 'UNMATCHED';
            if (!isset($counts[$status])) {
                $status = 'UNMATCHED';
            }
            $counts[$status]++;
        }
    } catch (Throwable $e) {
        $warning = $e->getMessage();
    }
    $ok = epgLastHistory('ok');

    return [
        'warning' => $warning,
        'playlist' => $playlistCount,
        'matched' => $counts['MATCHED'],
        'manual' => $counts['MANUAL'],
        'unmatched' => $counts['UNMATCHED'],
        'conflicts' => $counts['CONFLICT'],
        'programmes' => (int) (epgStateGet('generated_programmes') ?? 0),
        'generated_channels' => (int) (epgStateGet('generated_channels') ?? 0),
        'last_update' => epgFormatTime(is_array($ok) ? (string) $ok['finished_at'] : null),
        'next_update' => epgNextUpdateText(),
        'channels_count' => (int) (epgStateGet('channels_count') ?? 0),
        'channels_error' => (string) (epgStateGet('channels_error') ?? ''),
        'channels_updated' => epgFormatTime(epgStateGet('channels_updated_at')),
        'generated_error' => (string) (epgStateGet('generated_error') ?? ''),
    ];
}

function epgSearchChannels(string $query): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return [];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
    $stmt = epgDb()->prepare('SELECT c.xmltv_id, c.display_name, s.name AS source_name
        FROM epg_channels c
        JOIN epg_sources s ON s.id = c.source_id
        WHERE c.display_name LIKE ? ESCAPE \'\\\' OR c.xmltv_id LIKE ? ESCAPE \'\\\' OR c.alt_names LIKE ? ESCAPE \'\\\'
        ORDER BY s.priority ASC, c.display_name ASC
        LIMIT 40');
    $stmt->execute([$like, $like, $like]);
    $seen = [];
    $results = [];
    foreach ($stmt as $row) {
        $id = (string) $row['xmltv_id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $results[] = [
            'xmltv_id' => $id,
            'display_name' => (string) $row['display_name'],
            'source_name' => (string) $row['source_name'],
        ];
        if (count($results) >= 20) {
            break;
        }
    }

    return $results;
}

function epgMappingPage(string $status, string $query, int $page, int $perPage = 60): array
{
    $playlist = epgPlaylistByRef();
    $mappings = [];
    foreach (epgDb()->query('SELECT * FROM epg_mappings') as $row) {
        $mappings[(string) $row['service_ref']] = $row;
    }
    $rows = [];
    foreach ($playlist as $ref => $service) {
        $mapping = $mappings[$ref] ?? null;
        $rowStatus = is_array($mapping) ? (string) $mapping['status'] : 'UNMATCHED';
        $name = (string) ($service['match_name'] ?? $service['name']);
        if ($status !== '' && $status !== 'ALL' && $rowStatus !== $status) {
            continue;
        }
        if ($query !== '' && mb_stripos($name, $query, 0, 'UTF-8') === false && mb_stripos($ref, $query, 0, 'UTF-8') === false) {
            continue;
        }
        $rows[] = [
            'name' => $name,
            'sref' => $ref,
            'bouquet' => (string) ($service['group'] ?? ''),
            'satellite' => (string) ($service['satellite'] ?? ''),
            'xmltv_id' => is_array($mapping) ? (string) ($mapping['xmltv_id'] ?? '') : '',
            'source_name' => is_array($mapping) ? (string) ($mapping['source_name'] ?? '') : '',
            'match_type' => is_array($mapping) ? (string) ($mapping['match_type'] ?? '') : '',
            'confidence' => is_array($mapping) && $mapping['confidence'] !== null ? (float) $mapping['confidence'] : null,
            'status' => $rowStatus,
        ];
    }
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

    return ['rows' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages];
}
