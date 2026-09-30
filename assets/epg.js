document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const text = button.getAttribute('data-copy') || '';
        const previous = button.textContent;
        try {
            await navigator.clipboard.writeText(text);
            button.textContent = 'Copied';
        } catch (error) {
            button.textContent = 'Copy failed';
        }
        setTimeout(() => {
            button.textContent = previous;
        }, 1200);
    });
});

const search = document.getElementById('xmltv-q');
const results = document.getElementById('xmltv-results');
const xmltvId = document.getElementById('xmltv-id');
const pickedRef = document.getElementById('picked-ref');
const pickedLabel = document.getElementById('picked-label');
const saveForm = document.getElementById('save-map');

document.querySelectorAll('input[name="pick"]').forEach((input) => {
    input.addEventListener('change', () => {
        if (!pickedRef || !pickedLabel) return;
        pickedRef.value = input.value;
        pickedLabel.textContent = input.dataset.name + ' · ' + input.value;
    });
});

let timer = 0;
search?.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(async () => {
        if (!results) return;
        const query = search.value.trim();
        results.replaceChildren();
        if (query.length < 2) return;
        try {
            const response = await fetch('epg-mapping.php?suggest=' + encodeURIComponent(query), { cache: 'no-store' });
            const data = await response.json();
            (data.channels || []).forEach((channel) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggest';
                button.textContent = channel.display_name + ' · ' + channel.xmltv_id + ' · ' + channel.source_name;
                button.addEventListener('click', () => {
                    if (xmltvId) xmltvId.value = channel.xmltv_id;
                    search.value = channel.display_name + ' (' + channel.xmltv_id + ')';
                    results.replaceChildren();
                });
                results.append(button);
            });
            if ((data.channels || []).length === 0) {
                const empty = document.createElement('p');
                empty.className = 'meta';
                empty.textContent = 'No XMLTV channel found.';
                results.append(empty);
            }
        } catch (error) {
            const failed = document.createElement('p');
            failed.className = 'error';
            failed.textContent = 'Search failed.';
            results.append(failed);
        }
    }, 200);
});

saveForm?.addEventListener('submit', (event) => {
    if (!pickedRef?.value || !xmltvId?.value) {
        event.preventDefault();
        if (pickedLabel) pickedLabel.textContent = 'Choose a channel and an XMLTV channel first.';
    }
});
