(function () {
    'use strict';
    const state = {
        offset: 0,
        limit: 25,
        total: 0,
        rows: []
    };

    function filters() {
        return {
            action: document.getElementById('audit-action').value.trim(),
            table: document.getElementById('audit-entity').value.trim()
        };
    }

    function updatePagination() {
        const start = state.total ? state.offset + 1 : 0;
        const end = Math.min(state.offset + state.limit, state.total);
        const pages = state.total ? Math.max(1, Math.ceil(state.total / state.limit)) : 1;
        const page = state.total ? Math.floor(state.offset / state.limit) + 1 : 1;
        document.getElementById('audit-count').textContent = state.total ? `Page ${page} of ${pages} · showing ${start}–${end} of ${state.total} entries` : 'No audit entries found';
        document.getElementById('audit-previous').disabled = state.offset === 0;
        document.getElementById('audit-next').disabled = end >= state.total;
    }

    async function loadLogs() {
        const table = document.getElementById('audit-table');
        table.innerHTML = window.mpidsUi.loadingRow(6, 'Loading audit trail');
        try {
            const rows = await window.api.get(`audit-logs${window.mpidsUi.query(filters())}`);
            state.rows = Array.isArray(rows) ? rows : [];
            renderLogs();
        } catch (error) {
            state.rows = [];
            state.total = 0;
            state.offset = 0;
            table.innerHTML = window.mpidsUi.emptyRow(6, error.data?.detail || 'Unable to load audit trail.');
            updatePagination();
        }
    }

    function renderLogs() {
        const table = document.getElementById('audit-table');
        state.total = state.rows.length;
        if (state.offset >= state.total && state.offset > 0) {
            state.offset = Math.floor((state.total - 1) / state.limit) * state.limit;
        }
        const page = state.rows.slice(state.offset, state.offset + state.limit);
        table.innerHTML = page.length ? page.map(item => `<tr><td>${window.mpidsUi.formatDate(item.timestamp, true)}</td><td>${window.mpidsUi.escape(item.user_name || 'Public / system')}</td><td><span class="badge badge-soft-info">${window.mpidsUi.escape(item.action)}</span></td><td>${window.mpidsUi.escape(item.table_name)}${item.record_id ? ` <span class="table-secondary">#${item.record_id}</span>` : ''}</td><td><button class="button button-small button-ghost" type="button" data-changes="${item.id}"><i class="fa-solid fa-code"></i>View</button></td><td>${window.mpidsUi.escape(item.ip_address)}</td></tr>`).join('') : window.mpidsUi.emptyRow(6, 'No audit entries match these filters.');
        table.querySelectorAll('[data-changes]').forEach(button => button.addEventListener('click', () => {
            const item = state.rows.find(entry => String(entry.id) === button.dataset.changes);
            if (!item) return;
            window.alert(JSON.stringify({ old: item.old_value, new: item.new_value }, null, 2));
        }));
        updatePagination();
    }

    document.addEventListener('mpids:ready', () => {
        document.getElementById('apply-audit-filters').addEventListener('click', () => {
            state.offset = 0;
            loadLogs();
        });
        ['audit-action', 'audit-entity'].forEach(id => document.getElementById(id).addEventListener('keydown', event => {
            if (event.key === 'Enter') document.getElementById('apply-audit-filters').click();
        }));
        document.getElementById('refresh-audit').addEventListener('click', loadLogs);
        document.getElementById('audit-previous').addEventListener('click', () => {
            state.offset = Math.max(0, state.offset - state.limit);
            renderLogs();
        });
        document.getElementById('audit-next').addEventListener('click', () => {
            if (state.offset + state.limit < state.total) {
                state.offset += state.limit;
                renderLogs();
            }
        });
        loadLogs();
    });
})();
