document.addEventListener('mpids:ready', async () => {
    try {
        const [stats, records] = await Promise.all([
            window.api.get('dashboard/stats'),
            window.api.get('price-records')
        ]);
        document.getElementById('stat-pending').textContent = stats.pending;
        document.getElementById('stat-published').textContent = stats.published;
        document.getElementById('stat-commodities').textContent = stats.commodities;
        document.getElementById('stat-subscribers').textContent = stats.subscriptions;
        const recent = Array.isArray(records) ? records.slice(0, 5) : [];
        document.getElementById('recent-records').innerHTML = recent.length
            ? recent.map(row => `<tr><td><span class="table-primary">${window.mpidsUi.escape(row.commodity_name)}</span><span class="table-secondary">${window.mpidsUi.escape(row.unit_of_measure)}</span></td><td>${window.mpidsUi.escape(row.market_name)}<span class="table-secondary">${window.mpidsUi.escape(row.market_county)}</span></td><td>${window.mpidsUi.formatDate(row.date_observed)}</td><td class="table-primary">${window.mpidsUi.formatMoney(row.price)}</td><td>${window.mpidsUi.badge(row.status)}</td></tr>`).join('')
            : window.mpidsUi.emptyRow(5, 'No price activity yet.');
    } catch (error) {
        window.mpidsUi.toast(error.data?.detail || 'Unable to load the dashboard.', 'error');
    }
});
