(function () {
    'use strict';
    const state = {
        selectedId: null,
        offset: 0,
        limit: 25,
        total: 0,
        rows: []
    };

    function filters() {
        return {
            status: document.getElementById('approval-status').value,
            commodity: document.getElementById('approval-commodity').value,
            market: document.getElementById('approval-market').value,
            date_from: document.getElementById('approval-from').value,
            date_to: document.getElementById('approval-to').value
        };
    }

    function updatePagination() {
        const start = state.total ? state.offset + 1 : 0;
        const end = Math.min(state.offset + state.limit, state.total);
        document.getElementById('approval-count').textContent = state.total ? `Showing ${start}–${end} of ${state.total} records` : 'No records waiting for review';
        document.getElementById('approval-previous').disabled = state.offset === 0;
        document.getElementById('approval-next').disabled = end >= state.total;
    }

    async function loadOptions() {
        try {
            const [commodities, markets] = await Promise.all([window.api.get('public/commodities'), window.api.get('public/markets')]);
            document.getElementById('approval-commodity').innerHTML = '<option value="">All commodities</option>' + commodities.map(item => `<option value="${item.id}">${window.mpidsUi.escape(item.name)}</option>`).join('');
            document.getElementById('approval-market').innerHTML = '<option value="">All markets</option>' + markets.map(item => `<option value="${item.id}">${window.mpidsUi.escape(item.name)}</option>`).join('');
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to load filters.', 'error');
        }
    }

    async function loadQueue() {
        const table = document.getElementById('approval-table');
        table.innerHTML = window.mpidsUi.loadingRow(7, 'Loading pending prices');
        try {
            const rows = await window.api.get(`price-records${window.mpidsUi.query(filters())}`);
            state.rows = Array.isArray(rows) ? rows : [];
            renderQueue();
        } catch (error) {
            state.rows = [];
            state.total = 0;
            state.offset = 0;
            table.innerHTML = window.mpidsUi.emptyRow(7, error.data?.detail || 'Unable to load the queue.');
            updatePagination();
        }
    }

    function visibleRows() {
        const term = document.getElementById('approval-search').value.trim().toLowerCase();
        if (!term) return state.rows;
        return state.rows.filter(row => [row.commodity_name, row.market_name, row.source, row.captured_by_name]
            .some(value => String(value || '').toLowerCase().includes(term)));
    }

    function renderQueue() {
        const table = document.getElementById('approval-table');
        const rows = visibleRows();
        state.total = rows.length;
        if (state.offset >= state.total && state.offset > 0) {
            state.offset = Math.floor((state.total - 1) / state.limit) * state.limit;
        }
        const page = rows.slice(state.offset, state.offset + state.limit);
        table.innerHTML = page.length ? page.map(row => `<tr><td><span class="table-primary">${window.mpidsUi.escape(row.commodity_name)}</span><span class="table-secondary">${window.mpidsUi.escape(row.unit_of_measure)}</span></td><td>${window.mpidsUi.escape(row.market_name)}<span class="table-secondary">${window.mpidsUi.escape(row.market_county)}</span></td><td>${window.mpidsUi.formatDate(row.date_observed)}</td><td class="table-primary">${window.mpidsUi.formatMoney(row.price)}</td><td>${window.mpidsUi.escape(row.source)}</td><td>${window.mpidsUi.escape(row.captured_by_name || '—')}</td><td><div class="table-actions"><button class="button button-small button-primary" type="button" data-approve="${row.id}"><i class="fa-solid fa-check"></i>Approve</button><button class="button button-small button-danger" type="button" data-reject="${row.id}"><i class="fa-solid fa-xmark"></i>Reject</button></div></td></tr>`).join('') : window.mpidsUi.emptyRow(7, 'No prices are waiting for approval.');
        updatePagination();
    }

    document.addEventListener('mpids:ready', async () => {
        await loadOptions();
        document.getElementById('apply-approval-filters').addEventListener('click', () => {
            const from = document.getElementById('approval-from').value;
            const to = document.getElementById('approval-to').value;
            if (from && to && from > to) {
                window.mpidsUi.toast('The start date must be on or before the end date.', 'warning');
                return;
            }
            state.offset = 0;
            loadQueue();
        });
        document.getElementById('approval-search').addEventListener('input', () => {
            state.offset = 0;
            renderQueue();
        });
        document.getElementById('refresh-approval').addEventListener('click', loadQueue);
        document.getElementById('approval-previous').addEventListener('click', () => {
            state.offset = Math.max(0, state.offset - state.limit);
            renderQueue();
        });
        document.getElementById('approval-next').addEventListener('click', () => {
            if (state.offset + state.limit < state.total) {
                state.offset += state.limit;
                renderQueue();
            }
        });
        document.getElementById('approval-table').addEventListener('click', async event => {
            const approve = event.target.closest('[data-approve]');
            const reject = event.target.closest('[data-reject]');
            if (approve) {
                window.mpidsUi.setBusy(approve, true, 'Approving');
                try {
                    await window.api.post(`price-records/${approve.dataset.approve}/approve`);
                    window.mpidsUi.toast('Price published and verified SMS alerts were queued.', 'success');
                    await loadQueue();
                } catch (error) {
                    window.mpidsUi.toast(error.data?.detail || 'Unable to approve this price.', 'error');
                } finally { window.mpidsUi.setBusy(approve, false); }
            }
            if (reject) {
                state.selectedId = reject.dataset.reject;
                document.getElementById('reject-reason').value = '';
                document.querySelector('[data-error-for="reason"]').textContent = '';
                window.mpidsUi.openModal('reject-modal');
            }
        });
        document.getElementById('confirm-reject').addEventListener('click', async event => {
            const reason = document.getElementById('reject-reason').value.trim();
            if (reason.length < 3) {
                document.querySelector('[data-error-for="reason"]').textContent = 'Enter a rejection reason.';
                return;
            }
            window.mpidsUi.setBusy(event.currentTarget, true, 'Rejecting');
            try {
                await window.api.post(`price-records/${state.selectedId}/reject`, { reason });
                window.mpidsUi.toast('Price rejected. It can now be edited and resubmitted.', 'success');
                window.mpidsUi.closeModal('reject-modal');
                state.selectedId = null;
                await loadQueue();
            } catch (error) {
                window.mpidsUi.toast(error.data?.detail || 'Unable to reject this price.', 'error');
            } finally { window.mpidsUi.setBusy(event.currentTarget, false); }
        });
        const failureRows = document.getElementById('sms-failures');
        async function loadSmsFailures() {
            if (!failureRows) return;
            try {
                const entries = await window.api.get('audit-logs?action=sms.send_failed&table=sms_subscriptions');
                const list = Array.isArray(entries) ? entries : [];
                failureRows.innerHTML = list.length
                    ? list.slice(0, 10).map(row => {
                        const detail = row.new_value || {};
                        return `<tr><td>${window.mpidsUi.escape(detail.phone_number || '—')}</td>
                            <td class="table-secondary">${window.mpidsUi.escape(detail.error || 'Unknown error')}</td>
                            <td class="table-secondary">${window.mpidsUi.escape(detail.message || '')}</td>
                            <td>${window.mpidsUi.formatDate(row.timestamp, true)}</td></tr>`;
                    }).join('')
                    : window.mpidsUi.emptyRow(4, 'No SMS delivery failures recorded.');
            } catch (error) {
                failureRows.innerHTML = window.mpidsUi.emptyRow(4, error.data?.detail || 'Unable to load SMS failures.');
            }
        }
        document.getElementById('sms-failures-refresh').addEventListener('click', loadSmsFailures);

        await Promise.all([loadQueue(), loadSmsFailures()]);
    });
})();
