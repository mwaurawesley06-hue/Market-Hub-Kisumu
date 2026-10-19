document.addEventListener('mpids:ready', async () => {
    const rows = document.getElementById('subscribers-rows');
    const count = document.getElementById('subscribers-count');
    const refresh = document.getElementById('subscribers-refresh');
    if (!rows) return;

    function render(list) {
        const items = Array.isArray(list) ? list : [];
        if (count) count.textContent = `${items.length} total`;
        rows.innerHTML = items.length
            ? items.map(row => `<tr>
                <td>${window.mpidsUi.escape(row.phone_number)}</td>
                <td>${window.mpidsUi.escape(row.commodity_name)}</td>
                <td>${window.mpidsUi.escape(row.frequency)}</td>
                <td>${window.mpidsUi.badge(row.is_verified ? 'verified' : 'pending')}</td>
                <td>${row.last_alert_at ? window.mpidsUi.formatDate(row.last_alert_at, true) : '<span class="table-secondary">never</span>'}</td>
                <td>${window.mpidsUi.formatDate(row.created_at, true)}</td>
            </tr>`).join('')
            : window.mpidsUi.emptyRow(6, 'No SMS subscriptions yet.');
    }

    async function load() {
        try {
            render(await window.api.get('subscriptions'));
        } catch (error) {
            rows.innerHTML = window.mpidsUi.emptyRow(6, error.data?.detail || 'Unable to load subscribers.');
            window.mpidsUi.toast(error.data?.detail || 'Unable to load subscribers.', 'error');
        }
    }

    if (refresh) refresh.addEventListener('click', load);
    const exportButton = document.getElementById('subscribers-export');
    if (exportButton) {
        exportButton.addEventListener('click', () => {
            window.location.href = window.api.fileUrl('subscriptions?export=csv');
        });
    }
    await load();
});
