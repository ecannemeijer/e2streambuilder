<?php

require_once __DIR__ . '/epg.lib.php';

authRequireAdmin();

if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    $query = is_string($_GET['suggest']) ? $_GET['suggest'] : '';
    try {
        echo json_encode(['channels' => epgSearchChannels($query)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['channels' => [], 'error' => authHiddenError($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$notice = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        @set_time_limit(0);
        if ($action === 'manual') {
            epgWithLock(static function (): void {
                epgSaveManual((string) ($_POST['service_ref'] ?? ''), (string) ($_POST['xmltv_id'] ?? ''));
                epgGenerateXml();
            });
            $notice = 'Manual mapping saved.';
        } elseif ($action === 'clear') {
            epgWithLock(static function (): void {
                epgClearManual((string) ($_POST['service_ref'] ?? ''));
                epgRematchAll();
                epgGenerateXml();
            });
            $notice = 'Mapping cleared and matched again automatically.';
        } elseif ($action === 'rematch') {
            epgWithLock(static function (): void {
                epgRematchAll();
                epgGenerateXml();
            });
            $notice = 'Channels matched again. Manual mappings were kept.';
        }
    } catch (Throwable $e) {
        $error = authHiddenError($e);
    }
}

$status = (string) ($_GET['status'] ?? 'ALL');
if (!in_array($status, ['ALL', 'MATCHED', 'MANUAL', 'UNMATCHED', 'CONFLICT'], true)) {
    $status = 'ALL';
}
$query = trim((string) ($_GET['q'] ?? ''));
$page = (int) ($_GET['page'] ?? 1);
$loadError = null;
try {
    $listing = epgMappingPage($status, $query, $page);
} catch (Throwable $e) {
    $loadError = authHiddenError($e);
    $listing = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
}

function mappingHref(string $status, string $query, int $page): string
{
    return 'epg-mapping.php?' . http_build_query([
        'status' => $status,
        'q' => $query,
        'page' => $page,
    ]);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EPG mapping · E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=29">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <?php appMenubar('mapping'); ?>
    <header class="pagehead">
        <h1>EPG mapping</h1>
        <p class="lede">Manual mappings are kept and always win over automatic matching.</p>
    </header>

    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php elseif ($notice !== null): ?>
        <p class="oknote"><?= h($notice) ?></p>
    <?php endif; ?>
    <?php if ($loadError !== null): ?>
        <p class="error"><?= h($loadError) ?></p>
    <?php endif; ?>

    <section class="panel stack">
        <form method="get" class="toolbar">
            <label class="field"><span>Status</span>
                <select class="select" name="status">
                    <?php foreach (['ALL' => 'All', 'UNMATCHED' => 'UNMATCHED', 'CONFLICT' => 'CONFLICT', 'MATCHED' => 'MATCHED', 'MANUAL' => 'MANUAL'] as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field wide"><span>Search channel</span>
                <input name="q" value="<?= h($query) ?>" placeholder="Name or service reference">
            </label>
            <div class="actions">
                <button class="btn primary" type="submit">Filter</button>
            </div>
        </form>
        <form method="post">
            <button class="btn" name="action" value="rematch">Match again automatically</button>
        </form>
    </section>

    <section class="panel stack">
        <h2>Map manually</h2>
        <p class="note">Pick a channel in the table, search for the XMLTV channel, and save.</p>
        <form method="post" id="save-map" class="stack">
            <input type="hidden" name="action" value="manual">
            <input type="hidden" name="service_ref" id="picked-ref">
            <input type="hidden" name="xmltv_id" id="xmltv-id">
            <p class="meta" id="picked-label">No channel selected yet.</p>
            <label class="field wide"><span>XMLTV channel</span>
                <input id="xmltv-q" type="search" placeholder="Search by name or id" autocomplete="off">
            </label>
            <div id="xmltv-results" class="suggest-list"></div>
            <button class="btn primary" type="submit">Save mapping</button>
        </form>
    </section>

    <section class="panel stack">
        <p class="meta"><?= epgFormatNumber((int) $listing['total']) ?> channels · page <?= (int) $listing['page'] ?> of <?= (int) $listing['pages'] ?></p>
        <div class="table-wrap">
            <table class="grid">
                <thead>
                <tr>
                    <th></th>
                    <th>Channel</th>
                    <th>Service reference</th>
                    <th>Bouquet</th>
                    <th>Satellite</th>
                    <th>XMLTV id</th>
                    <th>Source</th>
                    <th>Match</th>
                    <th>Confidence</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($listing['rows'] as $row): ?>
                    <tr>
                        <td><input type="radio" name="pick" value="<?= h($row['sref']) ?>" data-name="<?= h($row['name']) ?>"></td>
                        <td><?= h($row['name']) ?></td>
                        <td class="mono"><?= h($row['sref']) ?></td>
                        <td><?= h($row['bouquet']) ?></td>
                        <td><?= h($row['satellite']) ?></td>
                        <td class="mono"><?= h($row['xmltv_id']) ?></td>
                        <td><?= h($row['source_name']) ?></td>
                        <td><?= h($row['match_type']) ?></td>
                        <td><?= $row['confidence'] === null ? '—' : h((string) (int) round($row['confidence'] * 100)) . '%' ?></td>
                        <td><span class="badge <?= h(strtolower($row['status'])) ?>"><?= h($row['status']) ?></span></td>
                        <td>
                            <?php if ($row['xmltv_id'] !== ''): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="clear">
                                    <input type="hidden" name="service_ref" value="<?= h($row['sref']) ?>">
                                    <button class="btn" type="submit">Clear</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($listing['pages'] > 1): ?>
            <p class="actions">
                <?php if ($listing['page'] > 1): ?>
                    <a class="btn slow" href="<?= h(mappingHref($status, $query, $listing['page'] - 1)) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($listing['page'] < $listing['pages']): ?>
                    <a class="btn slow" href="<?= h(mappingHref($status, $query, $listing['page'] + 1)) ?>">Next</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>
</div>
<script src="assets/epg.js?v=3"></script>
</body>
</html>
