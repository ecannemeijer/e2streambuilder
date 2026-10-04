<?php

require_once __DIR__ . '/epg.lib.php';

$account = authUser();
if ($account === null) {
    header('Location: index.php');
    exit;
}

$token = (string) ($_POST['house'] ?? $_GET['house'] ?? '');
$home = homeForEditor($token, (int) $account['id']);
if ($home === null) {
    header('Location: index.php');
    exit;
}

if (isset($_GET['download'])) {
    $path = homePlaylistPath($token);
    $file = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($file) || $file === '') {
        header('Location: playlist-edit.php?house=' . rawurlencode($token));
        exit;
    }
    $slug = (string) ($home['slug'] ?? 'playlist');
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '.m3u8"');
    header('Content-Length: ' . strlen($file));
    echo $file;
    exit;
}

$error = null;
$progress = $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['progress'] ?? '') === '1';
$back = 'playlist-edit.php?house=' . rawurlencode($token);
if ($progress) {
    epgProgressBegin();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        authCsrfCheck();
        $raw = (string) ($_POST['keep'] ?? '');
        $keep = $raw === '' ? [] : explode(',', $raw);
        homeSavePlaylistSelection($token, (int) $account['id'], $keep);
        $_SESSION['app_notice'] = 'Playlist saved.';
        session_write_close();
        if ($progress) {
            epgProgressEmit('Playlist saved.', true, false, $back);
            exit;
        }
        header('Location: ' . $back);
        exit;
    } catch (InvalidArgumentException $e) {
        $savedAnyway = $e->getMessage() === 'The playlist was saved, but the guide could not be rebuilt.';
        if ($savedAnyway) {
            $_SESSION['app_notice'] = 'Playlist saved.';
            $_SESSION['app_error'] = $e->getMessage();
            session_write_close();
            if ($progress) {
                epgProgressEmit($e->getMessage(), true, true, $back);
                exit;
            }
            header('Location: ' . $back);
            exit;
        }
        if ($progress) {
            epgProgressEmit($e->getMessage(), true, true);
            exit;
        }
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $message = authHiddenError($e);
        if ($progress) {
            epgProgressEmit($message, true, true);
            exit;
        }
        $error = $message;
    }
}

$path = homePlaylistPath($token);
$body = is_file($path) ? file_get_contents($path) : false;
$groups = [];
if (is_string($body) && $body !== '') {
    $groups = playlistGroupsFromBody($body)['groups'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit playlist · E2 Stream Builder</title>
    <?php appThemeScript(); ?>
    <link rel="stylesheet" href="assets/app.css?v=31">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <?php appMenubar('playlist', $token); ?>
    <header class="pagehead">
        <h1>Edit playlist</h1>
        <p class="lede"><?= h((string) $home['name']) ?>. Turn categories and channels off, then save. Publishing this house again replaces this list with the channels from the receiver.</p>
    </header>

    <?php if ($error !== null): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <?php if ($groups === []): ?>
        <section class="panel stack">
            <p>This house has no playlist yet. Publish it first.</p>
            <p><a class="btn" href="index.php?house=<?= h($token) ?>">Back to the house</a></p>
        </section>
    <?php else: ?>
        <form method="post" action="playlist-edit.php" id="edit-form">
            <?= authCsrfField() ?>
            <input type="hidden" name="house" value="<?= h($token) ?>">
            <input type="hidden" name="keep" id="edit-keep" value="">
            <div class="edit-tools">
                <label class="field"><span>Search channel</span>
                    <input id="channel-search" type="search" placeholder="Channel name" autocomplete="off">
                </label>
                <button class="btn" type="button" id="channel-find">Search</button>
                <p class="hint" id="channel-find-note" hidden>No channel with that name.</p>
                <button class="btn primary" type="submit">Save playlist</button>
                <a class="btn" href="playlist-edit.php?house=<?= h($token) ?>&amp;download=1">Download playlist</a>
            </div>
            <div class="edit-layout">
                <div class="edit-col" id="edit-categories"></div>
                <div class="edit-col" id="edit-channels"></div>
            </div>
        </form>
        <script type="application/json" id="edit-data"><?= json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
        <script>
        (function () {
            var dataNode = document.getElementById("edit-data");
            var groups = JSON.parse(dataNode.textContent);
            groups.forEach(function (group) {
                group.channels.forEach(function (channel) { channel.on = true; });
            });
            var active = 0;
            var categories = document.getElementById("edit-categories");
            var channelBox = document.getElementById("edit-channels");
            var search = document.getElementById("channel-search");
            var note = document.getElementById("channel-find-note");
            var matches = [];
            var query = "";

            function state(group) {
                var on = 0;
                group.channels.forEach(function (channel) { if (channel.on) on++; });
                return on === 0 ? "off" : (on === group.channels.length ? "on" : "mixed");
            }

            function render() {
                categories.innerHTML = "";
                groups.forEach(function (group, index) {
                    var row = document.createElement("div");
                    row.className = "edit-row" + (index === active ? " is-active" : "");
                    var box = document.createElement("input");
                    box.type = "checkbox";
                    box.setAttribute("aria-label", group.name);
                    var mode = state(group);
                    box.checked = mode !== "off";
                    box.indeterminate = mode === "mixed";
                    box.addEventListener("change", function () {
                        var turnOn = box.checked;
                        group.channels.forEach(function (channel) { channel.on = turnOn; });
                        render();
                    });
                    var button = document.createElement("button");
                    button.type = "button";
                    button.className = "edit-name";
                    button.textContent = group.name;
                    button.addEventListener("click", function () {
                        active = index;
                        render();
                    });
                    var count = document.createElement("span");
                    count.className = "meta";
                    count.textContent = String(group.channels.length);
                    row.appendChild(box);
                    row.appendChild(button);
                    row.appendChild(count);
                    categories.appendChild(row);
                });
                channelBox.innerHTML = "";
                var group = groups[active];
                if (!group) return;
                group.channels.forEach(function (channel, index) {
                    var row = document.createElement("div");
                    row.className = "edit-row";
                    row.setAttribute("data-channel", String(index));
                    var box = document.createElement("input");
                    box.type = "checkbox";
                    box.checked = channel.on;
                    box.setAttribute("aria-label", channel.name);
                    box.addEventListener("change", function () {
                        channel.on = box.checked;
                        render();
                    });
                    var name = document.createElement("span");
                    name.className = "edit-name";
                    name.textContent = channel.name;
                    row.appendChild(box);
                    row.appendChild(name);
                    channelBox.appendChild(row);
                });
            }

            function collect() {
                var found = [];
                var needle = query.trim().toLowerCase();
                if (needle === "") return found;
                groups.forEach(function (group, groupIndex) {
                    group.channels.forEach(function (channel, channelIndex) {
                        if (channel.name.toLowerCase().indexOf(needle) !== -1) {
                            found.push({group: groupIndex, channel: channelIndex});
                        }
                    });
                });
                return found;
            }

            function focusChannel(hit) {
                active = hit.group;
                render();
                var row = channelBox.querySelector('[data-channel="' + hit.channel + '"]');
                if (!row) return;
                row.classList.add("is-hit");
                row.tabIndex = -1;
                row.scrollIntoView({block: "center"});
                row.focus();
            }

            function openResults() {
                query = search.value;
                matches = collect();
                var list = document.getElementById("search-list");
                var overlay = document.getElementById("search-results");
                list.innerHTML = "";
                if (query.trim() === "") {
                    note.hidden = true;
                    return;
                }
                if (matches.length === 0) {
                    note.hidden = false;
                    return;
                }
                note.hidden = true;
                matches.slice(0, 40).forEach(function (hit) {
                    var button = document.createElement("button");
                    button.type = "button";
                    button.className = "btn search-hit";
                    var strong = document.createElement("strong");
                    strong.textContent = groups[hit.group].channels[hit.channel].name;
                    var category = document.createElement("span");
                    category.textContent = groups[hit.group].name;
                    button.appendChild(strong);
                    button.appendChild(category);
                    button.addEventListener("click", function () {
                        overlay.classList.remove("is-open");
                        focusChannel(hit);
                    });
                    list.appendChild(button);
                });
                if (matches.length > 40) {
                    var more = document.createElement("p");
                    more.textContent = "Showing the first 40 matches.";
                    list.appendChild(more);
                }
                overlay.classList.add("is-open");
            }

            document.getElementById("channel-find").addEventListener("click", openResults);
            search.addEventListener("keydown", function (event) {
                if (event.key !== "Enter") return;
                event.preventDefault();
                openResults();
            });
            document.getElementById("edit-form").addEventListener("submit", function () {
                var keep = [];
                groups.forEach(function (group) {
                    group.channels.forEach(function (channel) {
                        if (channel.on) keep.push(channel.index);
                    });
                });
                document.getElementById("edit-keep").value = keep.join(",");
            }, true);
            render();
        })();
        </script>
        <div id="search-results">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="search-title">
                <h2 id="search-title">Channels</h2>
                <div id="search-list"></div>
                <button class="btn" type="button" data-close>Close</button>
            </div>
        </div>
    <?php endif; ?>
    <?php appFooter(); ?>
</div>
</body>
</html>
