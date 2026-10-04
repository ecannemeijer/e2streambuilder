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

function playlistEditNext(string $next): string
{
    $next = str_replace(["\r", "\n"], '', $next);
    if (preg_match('/^(index|epg|epg-mapping|users|eit|settings)\.php$/', $next) === 1) {
        return $next;
    }
    if (preg_match('/^index\.php\?house=([a-f0-9]{32})$/', $next, $match) === 1 && homeTokenOk($match[1])) {
        return 'index.php?house=' . $match[1];
    }
    if (preg_match('/^index\.php\?open=(add-house|login|register|password)$/', $next) === 1) {
        return $next;
    }
    if (preg_match('/^playlist-edit\.php\?house=([a-f0-9]{32})$/', $next, $match) === 1 && homeTokenOk($match[1])) {
        return 'playlist-edit.php?house=' . $match[1];
    }

    return '';
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
        $next = playlistEditNext((string) ($_POST['next'] ?? ''));
        if ($next !== '') {
            $back = $next;
        }
        homeSavePlaylistSelection($token, (int) $account['id'], (string) ($_POST['layout'] ?? ''));
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
    <link rel="stylesheet" href="assets/app.css?v=34">
    <?php appShellStyle(); ?>
</head>
<body class="scroll">
<?php appChrome(); ?>
<div class="app">
    <?php appMenubar('playlist', $token); ?>
    <header class="pagehead">
        <h1>Edit playlist</h1>
        <p class="lede"><?= h((string) $home['name']) ?>. Turn categories and channels off, rename them, or move them, then save. Publishing this house again replaces this list with the channels from the receiver.</p>
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
            <input type="hidden" name="layout" id="edit-layout" value="">
            <input type="hidden" name="next" id="edit-next" value="">
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
        <div id="leave-guard">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="leave-title">
                <h2 id="leave-title">Save the playlist?</h2>
                <p>This playlist has changes. Save them before leaving this page?</p>
                <div class="actions">
                    <button class="btn primary" type="button" id="leave-save">Save</button>
                    <button class="btn" type="button" data-close>Cancel</button>
                </div>
            </div>
        </div>
        <div id="search-results">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="search-title">
                <h2 id="search-title">Channels</h2>
                <div id="search-list"></div>
                <button class="btn" type="button" data-close>Close</button>
            </div>
        </div>
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

            function snapshot() {
                return JSON.stringify(groups.map(function (group) {
                    return {
                        name: group.name,
                        channels: group.channels.map(function (channel) {
                            return {index: channel.index, name: channel.name, on: !!channel.on};
                        })
                    };
                }));
            }

            var baseline = snapshot();
            var dirty = false;
            var pendingUrl = "";
            var pendingForm = null;
            window.playlistClearDirty = function () { dirty = false; };

            function mark() {
                dirty = snapshot() !== baseline;
            }

            function moveItem(list, index, delta) {
                var next = index + delta;
                if (next < 0 || next >= list.length) return -1;
                var item = list.splice(index, 1)[0];
                list.splice(next, 0, item);
                return next;
            }

            var drag = null;

            function reorder(list, from, to) {
                if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return;
                var item = list.splice(from, 1)[0];
                if (from < to) to -= 1;
                list.splice(to, 0, item);
            }

            function bindDrag(row, kind, index, list, onDrop) {
                row.draggable = true;
                row.addEventListener("dragstart", function (event) {
                    if (event.target.closest("input, button, a")) {
                        event.preventDefault();
                        return;
                    }
                    drag = {kind: kind, index: index};
                    event.dataTransfer.effectAllowed = "move";
                    event.dataTransfer.setData("text/plain", kind);
                    row.classList.add("is-dragging");
                });
                row.addEventListener("dragend", function () {
                    row.classList.remove("is-dragging");
                    drag = null;
                });
                row.addEventListener("dragover", function (event) {
                    if (!drag || drag.kind !== kind) return;
                    event.preventDefault();
                    row.classList.add("is-drop");
                });
                row.addEventListener("dragleave", function () {
                    row.classList.remove("is-drop");
                });
                row.addEventListener("drop", function (event) {
                    if (!drag || drag.kind !== kind) return;
                    event.preventDefault();
                    event.stopPropagation();
                    var from = drag.index;
                    drag = null;
                    reorder(list, from, index);
                    onDrop();
                });
            }

            function moveButtons(index, length, onMove) {
                var wrap = document.createElement("span");
                wrap.className = "edit-move";
                [["Move up", "↑", -1], ["Move down", "↓", 1]].forEach(function (spec) {
                    var button = document.createElement("button");
                    button.type = "button";
                    button.className = "btn";
                    button.setAttribute("aria-label", spec[0]);
                    button.textContent = spec[1];
                    button.disabled = index + spec[2] < 0 || index + spec[2] >= length;
                    button.addEventListener("click", function () { onMove(spec[2]); });
                    wrap.appendChild(button);
                });
                return wrap;
            }

            function nameInput(value, label, maxLength, onValue) {
                var input = document.createElement("input");
                input.className = "edit-name-input";
                input.value = value;
                input.maxLength = maxLength;
                input.setAttribute("aria-label", label);
                input.addEventListener("click", function (event) { event.stopPropagation(); });
                input.addEventListener("input", function () {
                    onValue(input.value);
                    mark();
                });
                return input;
            }

            function render() {
                categories.innerHTML = "";
                groups.forEach(function (group, index) {
                    var row = document.createElement("div");
                    row.className = "edit-row" + (index === active ? " is-active" : "");
                    var grip = document.createElement("span");
                    grip.className = "edit-grip";
                    grip.textContent = "⋮⋮";
                    grip.title = "Drag";
                    row.appendChild(grip);
                    var box = document.createElement("input");
                    box.type = "checkbox";
                    box.setAttribute("aria-label", "Include " + group.name);
                    var mode = state(group);
                    box.checked = mode !== "off";
                    box.indeterminate = mode === "mixed";
                    box.addEventListener("change", function () {
                        var turnOn = box.checked;
                        group.channels.forEach(function (channel) { channel.on = turnOn; });
                        render();
                    });
                    var name = nameInput(group.name, "Category name", 80, function (value) { group.name = value; });
                    name.addEventListener("focus", function () {
                        if (active === index) return;
                        active = index;
                        render();
                        var inputs = categories.querySelectorAll(".edit-name-input");
                        if (inputs[index]) inputs[index].focus();
                    });
                    var count = document.createElement("span");
                    count.className = "meta";
                    count.textContent = String(group.channels.length);
                    row.appendChild(box);
                    row.appendChild(name);
                    row.appendChild(moveButtons(index, groups.length, function (delta) {
                        var next = moveItem(groups, index, delta);
                        if (next < 0) return;
                        active = next;
                        render();
                    }));
                    row.appendChild(count);
                    row.addEventListener("click", function (event) {
                        if (event.target.closest("input,button")) return;
                        active = index;
                        render();
                    });
                    bindDrag(row, "group", index, groups, function () {
                        var current = group;
                        active = Math.max(0, groups.indexOf(current));
                        render();
                    });
                    categories.appendChild(row);
                });
                channelBox.innerHTML = "";
                var group = groups[active];
                if (!group) {
                    mark();
                    return;
                }
                group.channels.forEach(function (channel, index) {
                    var row = document.createElement("div");
                    row.className = "edit-row";
                    row.setAttribute("data-channel", String(index));
                    var grip = document.createElement("span");
                    grip.className = "edit-grip";
                    grip.textContent = "⋮⋮";
                    grip.title = "Drag";
                    row.appendChild(grip);
                    var box = document.createElement("input");
                    box.type = "checkbox";
                    box.checked = channel.on;
                    box.setAttribute("aria-label", "Include " + channel.name);
                    box.addEventListener("change", function () {
                        channel.on = box.checked;
                        render();
                    });
                    var name = nameInput(channel.name, "Channel name", 120, function (value) { channel.name = value; });
                    row.appendChild(box);
                    row.appendChild(name);
                    row.appendChild(moveButtons(index, group.channels.length, function (delta) {
                        if (moveItem(group.channels, index, delta) < 0) return;
                        render();
                    }));
                    bindDrag(row, "channel", index, group.channels, function () { render(); });
                    channelBox.appendChild(row);
                });
                mark();
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
                    note.textContent = "No channel with that name.";
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
            function layoutPayload() {
                var layout = [];
                var seen = {};
                var duplicate = false;
                groups.forEach(function (group) {
                    var channels = [];
                    group.channels.forEach(function (channel) {
                        if (!channel.on) return;
                        var name = channel.name.trim();
                        channels.push({index: channel.index, name: name === "" ? "Channel" : name});
                    });
                    if (!channels.length) return;
                    var name = group.name.trim();
                    if (name === "") name = "Channels";
                    var key = name.toLowerCase();
                    if (seen[key]) duplicate = true;
                    seen[key] = true;
                    layout.push({name: name, channels: channels});
                });
                return {layout: layout, duplicate: duplicate};
            }

            function pageTarget(href) {
                var parsed = new URL(href, location.href);
                if (parsed.origin !== location.origin) return "";
                var file = parsed.pathname.split("/").pop() || "index.php";
                return file + parsed.search;
            }

            function openGuard(url, form) {
                pendingUrl = url || "";
                pendingForm = form || null;
                document.getElementById("leave-guard").classList.add("is-open");
            }

            document.getElementById("edit-form").addEventListener("submit", function (event) {
                var payload = layoutPayload();
                if (payload.duplicate || payload.layout.length === 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    document.getElementById("edit-next").value = "";
                    window.playlistAfterSave = null;
                    note.hidden = false;
                    note.textContent = payload.duplicate ? "Each category needs its own name." : "Keep at least one channel.";
                    return;
                }
                note.hidden = true;
                document.getElementById("edit-layout").value = JSON.stringify(payload.layout);
            }, true);

            document.getElementById("leave-save").addEventListener("click", function () {
                document.getElementById("leave-guard").classList.remove("is-open");
                var form = pendingForm;
                pendingForm = null;
                if (form) {
                    window.playlistAfterSave = function () {
                        if (form.requestSubmit) form.requestSubmit();
                        else form.submit();
                    };
                    document.getElementById("edit-next").value = "";
                } else {
                    window.playlistAfterSave = null;
                    document.getElementById("edit-next").value = pendingUrl;
                }
                document.getElementById("edit-form").requestSubmit();
            });

            document.addEventListener("click", function (event) {
                if (!dirty) return;
                var addHouse = event.target.closest("#add-house-open");
                if (addHouse && !document.getElementById("add-house")) {
                    event.preventDefault();
                    event.stopPropagation();
                    openGuard("index.php?open=add-house", null);
                    return;
                }
                var link = event.target.closest(".menubar a[href]");
                if (!link) return;
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === "_blank") return;
                var href = link.getAttribute("href") || "";
                if (href === "" || href.charAt(0) === "#") return;
                event.preventDefault();
                event.stopPropagation();
                openGuard(pageTarget(link.href), null);
            }, true);

            document.addEventListener("submit", function (event) {
                if (!dirty) return;
                var form = event.target;
                if (!form || !form.closest || !form.closest(".menubar")) return;
                event.preventDefault();
                event.stopPropagation();
                openGuard("", form);
            }, true);

            var housePick = document.getElementById("house-pick");
            var houseWas = housePick ? housePick.value : "";
            if (housePick) {
                document.addEventListener("change", function (event) {
                    if (event.target !== housePick) return;
                    if (!dirty) {
                        houseWas = housePick.value;
                        return;
                    }
                    var next = housePick.value;
                    housePick.value = houseWas;
                    event.preventDefault();
                    event.stopPropagation();
                    openGuard("index.php?house=" + encodeURIComponent(next), null);
                }, true);
            }

            window.addEventListener("beforeunload", function (event) {
                if (!dirty) return;
                event.preventDefault();
                event.returnValue = "";
            });
            render();
        })();
        </script>
    <?php endif; ?>
    <?php appFooter(); ?>
</div>
</body>
</html>
