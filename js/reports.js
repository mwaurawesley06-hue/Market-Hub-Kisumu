(function () {
    let lastQuery = '';

    async function loadOptions() {
        const [commodities, markets] = await Promise.all([window.api.get('public/commodities'), window.api.get('public/markets')]);
        document.getElementById('report-commodity').innerHTML = '<option value="">All commodities</option>' + commodities.map(item => `<option value="${item.id}">${window.mpidsUi.escape(item.name)}</option>`).join('');
        document.getElementById('report-market').innerHTML = '<option value="">All markets</option>' + markets.map(item => `<option value="${item.id}">${window.mpidsUi.escape(item.name)}</option>`).join('');
    }

    function getQuery() {
        return window.mpidsUi.query({ commodity: document.getElementById('report-commodity').value, market: document.getElementById('report-market').value, date_from: document.getElementById('report-from').value, date_to: document.getElementById('report-to').value });
    }

    async function runReport() {
        const button = document.getElementById('run-report');
        const from = document.getElementById('report-from').value;
        const to = document.getElementById('report-to').value;
        if (from && to && from > to) {
            window.mpidsUi.toast('The start date must be on or before the end date.', 'warning');
            return;
        }
        window.mpidsUi.setBusy(button, true, 'Running');
        lastQuery = getQuery();
        try {
            const data = await window.api.get(`reports${lastQuery}`);
            const metrics = data.summary;
            const change = metrics.change_pct === null || metrics.change_pct === undefined ? '—' : `${metrics.change_pct > 0 ? '+' : ''}${metrics.change_pct}%`;
            document.querySelectorAll('#report-summary .metric-card strong').forEach((element, index) => {
                const values = [metrics.count, window.mpidsUi.formatMoney(metrics.average), window.mpidsUi.formatMoney(metrics.highest), window.mpidsUi.formatMoney(metrics.lowest), change];
                element.textContent = values[index];
            });
            document.getElementById('report-table').innerHTML = data.records.length ? data.records.map(row => `<tr><td class="table-primary">${window.mpidsUi.escape(row.commodity_name)}</td><td>${window.mpidsUi.escape(row.market_name)}</td><td>${window.mpidsUi.formatDate(row.date_observed)}</td><td class="table-primary">${window.mpidsUi.formatMoney(row.price)}</td><td>${window.mpidsUi.escape(row.source)}</td><td>${window.mpidsUi.escape(row.approved_by_name || '—')}</td></tr>`).join('') : window.mpidsUi.emptyRow(6, 'No published records match this report.');
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to generate the report.', 'error');
        } finally { window.mpidsUi.setBusy(button, false); }
    }

    document.addEventListener('mpids:ready', async () => {
        await loadOptions();
        document.getElementById('run-report').addEventListener('click', runReport);
        document.getElementById('export-report').addEventListener('click', () => window.location.href = window.api.fileUrl(`reports/export${lastQuery || getQuery()}`));
        document.getElementById('print-report').addEventListener('click', () => window.print());
    });
})();
