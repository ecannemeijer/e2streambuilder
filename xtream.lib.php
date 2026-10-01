<?php

require_once __DIR__ . '/epg.lib.php';

function xtreamPlayUrl(string $url): string
{
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }

    return $url;
}

function xtreamAuthOk(?string $username, ?string $password): bool
{
    $settings = xtreamSettings();
    if ($settings['xtream_password'] === '') {
        return false;
    }

    return hash_equals($settings['xtream_username'], (string) $username)
        && hash_equals($settings['xtream_password'], (string) $password);
}

function xtreamServerInfo(): array
{
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $port = $https ? '443' : '80';
    if (preg_match('/^(.*):(\d+)$/', $host, $match) === 1 && strpos($host, ']') === false) {
        $host = $match[1];
        $port = $match[2];
    }

    return [
        'url' => $host,
        'port' => $port,
        'https_port' => '443',
        'server_protocol' => $https ? 'https' : 'http',
        'rtmp_port' => '0',
        'timezone' => date_default_timezone_get() ?: 'UTC',
        'timestamp_now' => time(),
        'time_now' => date('Y-m-d H:i:s'),
    ];
}

function xtreamUserInfo(): array
{
    $settings = xtreamSettings();

    return [
        'username' => $settings['xtream_username'],
        'password' => $settings['xtream_password'],
        'message' => 'Welcome',
        'auth' => 1,
        'status' => 'Active',
        'exp_date' => null,
        'is_trial' => '0',
        'active_cons' => '0',
        'created_at' => '1600000000',
        'max_connections' => '1',
        'allowed_output_formats' => ['ts'],
    ];
}

/**
 * @param list<int> $ids
 */
function xtreamKeepIds(PDO $db, string $table, array $ids): void
{
    $db->exec('CREATE TEMP TABLE IF NOT EXISTS ' . $table . ' (id INTEGER PRIMARY KEY)');
    $db->exec('DELETE FROM ' . $table);
    $insert = $db->prepare('INSERT INTO ' . $table . ' (id) VALUES (?)');
    foreach ($ids as $id) {
        $insert->execute([$id]);
    }
}

function xtreamBuildCatalog(): array
{
    epgLog('Reading channels from the receiver');
    $rows = playlistRows(fetchAllServices(), null, 'all');
    $tvgIds = playlistTvgIds();
    $bouquets = fetchBouquets();
    $bouquetOrder = [];
    $bouquetNameOrder = [];
    foreach ($bouquets as $bouquetIndex => $bouquet) {
        $bouquetOrder[$bouquet['ref']] = $bouquetIndex;
        $bouquetNameOrder[$bouquet['name']] = $bouquetIndex;
    }

    return epgWithLock(static function () use ($rows, $tvgIds, $bouquets, $bouquetOrder, $bouquetNameOrder): array {
        epgLog('Building the Xtream channel list');
        $db = epgDb();
        $db->beginTransaction();
        $keepCategories = [];
        $keepStreams = [];
        try {
            $categoryByRef = [];
            foreach ($db->query('SELECT category_id, bouquet_ref FROM xtream_categories') as $row) {
                $categoryByRef[(string) $row['bouquet_ref']] = (int) $row['category_id'];
            }
            $streamByKey = [];
            foreach ($db->query('SELECT stream_id, category_id, service_ref FROM xtream_streams') as $row) {
                $streamByKey[(int) $row['category_id'] . "\0" . $row['service_ref']] = (int) $row['stream_id'];
            }
            $nextCategory = (int) $db->query('SELECT COALESCE(MAX(category_id), 0) FROM xtream_categories')->fetchColumn();
            $nextStream = (int) $db->query('SELECT COALESCE(MAX(stream_id), 0) FROM xtream_streams')->fetchColumn();
            $insertCategory = $db->prepare('INSERT INTO xtream_categories (category_id, bouquet_ref, category_name, sort_order) VALUES (?, ?, ?, ?)');
            $updateCategory = $db->prepare('UPDATE xtream_categories SET category_name = ?, sort_order = ? WHERE category_id = ?');
            $insertStream = $db->prepare('INSERT INTO xtream_streams
                (stream_id, category_id, service_ref, name, stream_url, epg_channel_id, added_at, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $updateStream = $db->prepare('UPDATE xtream_streams SET name = ?, stream_url = ?, epg_channel_id = ?, sort_order = ? WHERE stream_id = ?');
            $seen = [];
            $channelOrder = [];
            $now = time();
            foreach ($rows as $row) {
                $bouquetRef = (string) ($row['bouquet_ref'] ?? '');
                $serviceRef = (string) ($row['sref'] ?? '');
                $url = xtreamPlayUrl((string) ($row['url'] ?? ''));
                if ($bouquetRef === '' || $serviceRef === '' || preg_match('#^https?://\S+$#i', $url) !== 1) {
                    continue;
                }
                $pair = $bouquetRef . "\0" . $serviceRef;
                if (isset($seen[$pair])) {
                    continue;
                }
                $seen[$pair] = true;
                $categorySort = $bouquetOrder[$bouquetRef] ?? $bouquetNameOrder[(string) $row['group']] ?? (100000 + count($categoryByRef));
                if (!isset($categoryByRef[$bouquetRef])) {
                    $nextCategory++;
                    $insertCategory->execute([$nextCategory, $bouquetRef, (string) $row['group'], $categorySort]);
                    $categoryByRef[$bouquetRef] = $nextCategory;
                } else {
                    $updateCategory->execute([(string) $row['group'], $categorySort, $categoryByRef[$bouquetRef]]);
                }
                $categoryId = $categoryByRef[$bouquetRef];
                $keepCategories[$categoryId] = true;
                $key = $categoryId . "\0" . $serviceRef;
                $epgId = (string) ($tvgIds[$serviceRef] ?? '');
                $streamSort = $channelOrder[$categoryId] ?? 0;
                $channelOrder[$categoryId] = $streamSort + 1;
                if (!isset($streamByKey[$key])) {
                    $nextStream++;
                    $insertStream->execute([$nextStream, $categoryId, $serviceRef, (string) $row['name'], $url, $epgId, $now, $streamSort]);
                    $streamByKey[$key] = $nextStream;
                } else {
                    $updateStream->execute([(string) $row['name'], $url, $epgId, $streamSort, $streamByKey[$key]]);
                }
                $keepStreams[$streamByKey[$key]] = true;
            }
            foreach ($bouquets as $bouquet) {
                $bouquetRef = $bouquet['ref'];
                if ($bouquetRef === '' || isset($categoryByRef[$bouquetRef])) {
                    continue;
                }
                $nextCategory++;
                $insertCategory->execute([
                    $nextCategory,
                    $bouquetRef,
                    $bouquet['name'],
                    $bouquetOrder[$bouquetRef] ?? (100000 + $nextCategory),
                ]);
                $categoryByRef[$bouquetRef] = $nextCategory;
                $keepCategories[$nextCategory] = true;
            }
            xtreamKeepIds($db, 'xtream_keep_stream', array_keys($keepStreams));
            xtreamKeepIds($db, 'xtream_keep_category', array_keys($keepCategories));
            if ($keepStreams === []) {
                $db->exec('DELETE FROM xtream_streams');
            } else {
                $db->exec('DELETE FROM xtream_streams WHERE stream_id NOT IN (SELECT id FROM xtream_keep_stream)');
            }
            if ($keepCategories === []) {
                $db->exec('DELETE FROM xtream_categories');
            } else {
                $db->exec('DELETE FROM xtream_categories WHERE category_id NOT IN (SELECT id FROM xtream_keep_category)');
            }
            $db->commit();
            epgLog('Xtream channel list saved: ' . count($keepStreams) . ' channels in ' . count($keepCategories) . ' bouquets');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $epgError = '';
        try {
            epgLog('Building the programme guide');
            epgGenerateXml();
        } catch (Throwable $e) {
            $epgError = $e->getMessage();
        }
        epgStateSet('xtream_epg_error', $epgError);
        epgStateSet('xtream_build_error', '');
        epgStateSet('xtream_built_at', epgNow());

        return [
            'categories' => count($keepCategories),
            'channels' => count($keepStreams),
            'epg_error' => $epgError,
        ];
    });
}

function xtreamStats(): array
{
    $db = epgDb();

    return [
        'categories' => (int) $db->query('SELECT COUNT(*) FROM xtream_categories')->fetchColumn(),
        'channels' => (int) $db->query('SELECT COUNT(*) FROM xtream_streams')->fetchColumn(),
        'epg_error' => (string) (epgStateGet('xtream_epg_error') ?? ''),
        'build_error' => (string) (epgStateGet('xtream_build_error') ?? ''),
        'built_at' => epgStateGet('xtream_built_at'),
    ];
}

function xtreamListCategories(): array
{
    $categories = [];
    foreach (epgDb()->query('SELECT category_id, category_name FROM xtream_categories ORDER BY sort_order ASC, category_id ASC') as $row) {
        $categories[] = [
            'category_id' => (string) $row['category_id'],
            'category_name' => (string) $row['category_name'],
            'parent_id' => 0,
        ];
    }

    return $categories;
}

function xtreamListStreams(int $categoryId): array
{
    $sql = 'SELECT stream_id, name, stream_url, epg_channel_id, category_id, added_at FROM xtream_streams';
    $params = [];
    if ($categoryId > 0) {
        $sql .= ' WHERE category_id = ?';
        $params[] = $categoryId;
    }
    $sql .= ' ORDER BY sort_order ASC, stream_id ASC';
    $stmt = epgDb()->prepare($sql);
    $stmt->execute($params);
    $streams = [];
    $num = 0;
    foreach ($stmt as $row) {
        $num++;
        $streams[] = [
            'num' => $num,
            'name' => (string) $row['name'],
            'stream_type' => 'live',
            'stream_id' => (int) $row['stream_id'],
            'stream_icon' => logoUrlForXmltvId((string) $row['epg_channel_id']),
            'epg_channel_id' => (string) $row['epg_channel_id'],
            'added' => (string) $row['added_at'],
            'category_id' => (string) $row['category_id'],
            'custom_sid' => '',
            'tv_archive' => 0,
            'direct_source' => xtreamDirectSource((string) $row['stream_url']),
            'tv_archive_duration' => 0,
        ];
    }

    return $streams;
}

function xtreamDirectSource(string $url): string
{
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }

    return $url;
}

function xtreamStreamUrl(int $streamId): ?string
{
    $stmt = epgDb()->prepare('SELECT stream_url FROM xtream_streams WHERE stream_id = ?');
    $stmt->execute([$streamId]);
    $url = $stmt->fetchColumn();
    if (!is_string($url) || preg_match('#^https?://\S+$#i', $url) !== 1) {
        return null;
    }

    return $url;
}

function xtreamJson(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
