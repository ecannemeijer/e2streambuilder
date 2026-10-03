const statusEl = document.getElementById('status');
const testButton = document.getElementById('test');
const testResult = document.getElementById('test-result');
const video = document.getElementById('screen');
const placeholder = document.getElementById('placeholder');
const nowName = document.getElementById('now-name');
const nowState = document.getElementById('now-state');
const nowUrl = document.getElementById('now-url');
const channelList = document.getElementById('channel-list');
const channelMeta = document.getElementById('channel-meta');
const channelQuery = document.getElementById('channel-q');
const onlyStreams = document.getElementById('only-streams');
const bouquetRows = Array.from(document.querySelectorAll('#bouquet-list .bq'));
const bouquetQuery = document.getElementById('q');
const bouquetCount = document.getElementById('bq-count');

let channels = [];
let activeUrl = '';
let hls = null;
let tsPlayer = null;

function setStatus(kind, text) {
    if (!statusEl) return;
    statusEl.className = 'status ' + (kind || '');
    statusEl.querySelector('span').textContent = text;
}

function setTestResult(kind, text) {
    if (!testResult) return;
    testResult.className = kind;
    testResult.textContent = text;
}

function receiverFields() {
    return {
        host: document.querySelector('[name="host"]')?.value.trim() || '',
        webif: document.querySelector('[name="webif_port"]')?.value || '80',
        stream: document.querySelector('[name="stream_port"]')?.value || '8001',
    };
}

async function loadStatus(fromButton) {
    const fields = receiverFields();
    if (fromButton && testButton) {
        testButton.disabled = true;
        testButton.textContent = 'Testing…';
        setTestResult('note', 'Testing connection to ' + fields.host + '…');
    }
    const query = new URLSearchParams({
        host: fields.host,
        webif_port: fields.webif,
        stream_port: fields.stream,
    });
    try {
        const response = await fetch('status.php?' + query.toString(), { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            const message = data.error || 'No connection to ' + fields.host;
            setStatus('bad', message);
            if (fromButton) setTestResult('error', message);
            return;
        }
        const station = data.station ? ' · ' + data.station : '';
        if (data.standby) {
            setStatus('warn', 'Standby' + station);
            if (fromButton) setTestResult('oknote', 'Reachable, the receiver is in standby' + station);
            return;
        }
        setStatus('ok', 'Connected' + station);
        if (fromButton) setTestResult('oknote', 'Connected to ' + fields.host + station);
    } catch (error) {
        setStatus('bad', 'Receiver unreachable');
        if (fromButton) setTestResult('error', 'No connection to ' + fields.host);
    } finally {
        if (fromButton && testButton) {
            testButton.disabled = false;
            testButton.textContent = 'Test connection';
        }
    }
}

function refreshBouquetCount() {
    if (!bouquetCount) return;
    const checked = bouquetRows.filter((row) => row.querySelector('input').checked).length;
    const visible = bouquetRows.filter((row) => row.style.display !== 'none').length;
    bouquetCount.textContent = checked + ' of ' + bouquetRows.length + ' selected for the playlist'
        + (visible === bouquetRows.length ? '' : ' · ' + visible + ' visible');
}

function setChecked(predicate) {
    bouquetRows.forEach((row) => {
        row.querySelector('input').checked = predicate(row);
    });
    refreshBouquetCount();
}

function proxy(url) {
    return 'media.php?u=' + encodeURIComponent(url);
}

function stopPlayback() {
    if (hls) {
        hls.destroy();
        hls = null;
    }
    if (tsPlayer) {
        try {
            tsPlayer.pause();
            tsPlayer.unload();
            tsPlayer.detachMediaElement();
            tsPlayer.destroy();
        } catch (error) {
            /* player already gone */
        }
        tsPlayer = null;
    }
    video.pause();
    video.removeAttribute('src');
    video.load();
}

function showPlaceholder(text) {
    placeholder.textContent = text;
    placeholder.classList.remove('hidden');
}

function hidePlaceholder() {
    placeholder.classList.add('hidden');
}

function describe(channel) {
    if (channel.source === 'stream' && channel.kind === 'external') {
        return 'This protocol does not play in the browser. Open the address in VLC.';
    }
    if (channel.source === 'satellite') {
        return 'Satellite stream through the receiver. Without a dish the picture stays black.';
    }
    return 'The stream plays directly, without the receiver.';
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-src="' + src + '"]');
        if (existing) {
            if (existing.dataset.ready === '1') {
                resolve();
                return;
            }
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener('error', () => reject(new Error('Bibliotheek laden mislukt')), { once: true });
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.dataset.src = src;
        script.onload = () => {
            script.dataset.ready = '1';
            resolve();
        };
        script.onerror = () => reject(new Error('Bibliotheek laden mislukt'));
        document.head.appendChild(script);
    });
}

async function play(channel) {
    if (!video || !nowName) {
        activeUrl = channel.url;
        document.querySelectorAll('.ch').forEach((row) => {
            row.classList.toggle('active', row.dataset.url === channel.url && row.dataset.name === channel.name);
        });
        return;
    }
    stopPlayback();
    activeUrl = channel.url;
    nowName.textContent = channel.name;
    nowState.textContent = describe(channel);
    nowUrl.hidden = false;
    nowUrl.textContent = channel.url;
    document.querySelectorAll('.ch').forEach((row) => {
        row.classList.toggle('active', row.dataset.url === channel.url && row.dataset.name === channel.name);
    });

    if (channel.kind === 'external') {
        showPlaceholder('Open this address in VLC.');
        return;
    }

    showPlaceholder('Connecting…');

    try {
        if (channel.kind === 'ts') {
            await loadScript('https://cdn.jsdelivr.net/npm/mpegts.js@1.7.3/dist/mpegts.min.js');
        } else if (channel.kind !== 'file') {
            await loadScript('https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js');
        }
    } catch (error) {
        showPlaceholder('The player library could not be loaded.');
        return;
    }

    if (channel.kind === 'file') {
        video.src = proxy(channel.url);
        hidePlaceholder();
        video.play().catch(() => showPlaceholder('Playback was blocked by the browser.'));
        return;
    }

    if (channel.kind === 'ts') {
        if (!window.mpegts || !mpegts.isSupported()) {
            showPlaceholder('This browser cannot play the TS stream.');
            return;
        }
        tsPlayer = mpegts.createPlayer({
            type: 'mse',
            isLive: true,
            url: proxy(channel.url),
        });
        tsPlayer.attachMediaElement(video);
        tsPlayer.on(mpegts.Events.ERROR, () => {
            showPlaceholder('The stream produced no picture.');
        });
        tsPlayer.load();
        hidePlaceholder();
        tsPlayer.play();
        return;
    }

    if (window.Hls && Hls.isSupported()) {
        hls = new Hls({ liveDurationInfinity: true });
        hls.loadSource(proxy(channel.url));
        hls.attachMedia(video);
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            hidePlaceholder();
            video.play().catch(() => showPlaceholder('Playback was blocked by the browser.'));
        });
        hls.on(Hls.Events.ERROR, (event, data) => {
            if (!data.fatal) return;
            showPlaceholder('The stream does not start. The address may be offline.');
        });
        return;
    }

    if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = proxy(channel.url);
        hidePlaceholder();
        video.play().catch(() => showPlaceholder('Playback was blocked by the browser.'));
        return;
    }

    showPlaceholder('This browser cannot play HLS.');
}

function renderChannels() {
    const query = (channelQuery.value || '').trim().toLowerCase();
    const streamsOnly = onlyStreams.checked;
    const visible = channels.filter((channel) => {
        if (streamsOnly && channel.source !== 'stream') return false;
        if (query && !channel.name.toLowerCase().includes(query)) return false;
        return true;
    });
    channelMeta.textContent = visible.length + ' of ' + channels.length + ' channels';
    if (visible.length === 0) {
        const message = streamsOnly && channels.some((channel) => channel.source !== 'stream')
            ? 'No streams in this bouquet. Turn off Streams only to see the satellite channels.'
            : 'No channels in this selection.';
        channelList.innerHTML = '<p class="empty"></p>';
        channelList.querySelector('.empty').textContent = message;
        return;
    }
    channelList.replaceChildren();
    visible.forEach((channel) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ch' + (channel.url === activeUrl ? ' active' : '');
        button.dataset.url = channel.url;
        button.dataset.name = channel.name;
        const badge = channel.source === 'stream'
            ? (channel.kind === 'external' ? '<span class="badge ext">external</span>' : '<span class="badge">stream</span>')
            : '<span class="badge sat">satellite</span>';
        const title = document.createElement('strong');
        title.innerHTML = badge;
        title.append(channel.name);
        const sub = document.createElement('small');
        sub.textContent = channel.url;
        button.append(title, sub);
        button.addEventListener('click', () => play(channel));
        channelList.append(button);
    });
}

async function openBouquet(button) {
    document.querySelectorAll('.bq').forEach((row) => row.classList.remove('active'));
    button.closest('.bq').classList.add('active');
    channelMeta.textContent = 'Loading channels…';
    channelList.innerHTML = '<p class="empty">Loading channels…</p>';
    try {
        const response = await fetch('channels.php?ref=' + encodeURIComponent(button.dataset.ref), { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Could not load channels');
        }
        channels = data.channels || [];
        channelList.scrollTop = 0;
        renderChannels();
    } catch (error) {
        channels = [];
        channelMeta.textContent = 'Loading failed';
        channelList.innerHTML = '<p class="empty"></p>';
        channelList.querySelector('.empty').textContent = error.message;
    }
}

document.getElementById('test')?.addEventListener('click', (event) => {
    event.preventDefault();
    loadStatus(true);
});
bouquetQuery?.addEventListener('input', () => {
    const query = bouquetQuery.value.trim().toLowerCase();
    bouquetRows.forEach((row) => {
        row.style.display = query === '' || (row.dataset.name || '').includes(query) ? '' : 'none';
    });
    refreshBouquetCount();
});
document.getElementById('all')?.addEventListener('click', () => setChecked(() => true));
document.getElementById('none')?.addEventListener('click', () => setChecked(() => false));
document.getElementById('streams')?.addEventListener('click', () => setChecked((row) => row.dataset.stream === '1'));
document.getElementById('other')?.addEventListener('click', () => setChecked((row) => row.dataset.stream !== '1'));
document.getElementById('bouquet-list')?.addEventListener('click', (event) => {
    const button = event.target.closest('.bq-open');
    if (!button) return;
    openBouquet(button);
});
channelQuery?.addEventListener('input', renderChannels);
onlyStreams?.addEventListener('change', renderChannels);
video?.addEventListener('playing', hidePlaceholder);

refreshBouquetCount();
if (statusEl) loadStatus();
