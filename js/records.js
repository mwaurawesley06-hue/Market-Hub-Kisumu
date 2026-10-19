(function () {
    'use strict';
    const state = {
        editingId: null,
        offset: 0,
        limit: 25,
        total: 0,
        rows: []
    };

    function optionMarkup(item, selected) {
        const range = item.min_price === undefined ? '' : ` data-min="${window.mpidsUi.escape(item.min_price)}" data-max="${window.mpidsUi.escape(item.max_price)}"`;
        return `<option value="${item.id}"${String(item.id) === String(selected) ? ' selected' : ''}${range}>${window.mpidsUi.escape(item.name)}${item.unit_of_measure ? ` (${window.mpidsUi.escape(item.unit_of_measure)})` : ''}</option>`;
    }

    async function loadOptions() {
        // Authenticated active lists: the public projection omits min_price/max_price,
        // so the edit form would lose its price bounds.
        const [commodities, markets] = await Promise.all([window.api.get('commodities?is_active=1'), window.api.get('markets?is_active=1')]);
        document.getElementById('records-commodity').innerHTML = '<option value="">All commodities</option>' + commodities.map(item => optionMarkup(item, '')).join('');
        document.getElementById('records-market').innerHTML = '<option value="">All markets</option>' + markets.map(item => optionMarkup(item, '')).join('');
        document.getElementById('edit-commodity').innerHTML = '<option value="">Select a commodity</option>' + commodities.map(item => optionMarkup(item, '')).join('');
        document.getElementById('edit-market').innerHTML = '<option value="">Select a market</option>' + markets.map(item => optionMarkup(item, '')).join('');
    }

    function updateEditRange() {
        const select = document.getElementById('edit-commodity');
        const option = select.options[select.selectedIndex];
        const hint = document.getElementById('edit-price-range');
        const price = document.getElementById('edit-price');
        if (!option?.dataset.min || !option?.dataset.max) {
            hint.innerHTML = '<i class="fa-solid fa-circle-info"></i>Select a commodity first';
            price.removeAttribute('min');
            price.removeAttribute('max');
            return;
        }
        const min = Number(option.dataset.min);
        const max = Number(option.dataset.max);
        hint.innerHTML = `<i class="fa-solid fa-circle-check"></i>${window.mpidsUi.formatMoney(min)} – ${window.mpidsUi.formatMoney(max)}`;
        price.min = String(min);
        price.max = String(max);
    }

    function updatePagination() {
        const start = state.total ? state.offset + 1 : 0;
        const end = Math.min(state.offset + state.limit, state.total);
        document.getElementById('records-count').textContent = state.total ? `Showing ${start}–${end} of ${state.total} records` : 'No records found';
        document.getElementById('records-previous').disabled = state.offset === 0;
        document.getElementById('records-next').disabled = end >= state.total;
    }

    function decisionTrail(row) {
        const details = [`Captured by ${row.captured_by_name || 'Unknown'}`];
        if (row.approved_by_name) details.push(`Approved by ${row.approved_by_name}`);
        if (row.rejection_reason) details.push(`Reason: ${row.rejection_reason}`);
        if (row.override_justification) details.push(`Correction: ${row.override_justification}`);
        if (row.updated_at) details.push(`Updated ${window.mpidsUi.formatDate(row.updated_at, true)}`);
        return details.map(detail => `<span class="table-secondary">${window.mpidsUi.escape(detail)}</span>`).join('');
    }

    async function loadRecords() {
        const table = document.getElementById('records-table');
        table.innerHTML = window.mpidsUi.loadingRow(7, 'Loading price records');
        const query = window.mpidsUi.query({
            status: document.getElementById('records-status').value,
            commodity: document.getElementById('records-commodity').value,
            market: document.getElementById('records-market').value,
            date_from: document.getElementById('records-from').value,
            date_to: document.getElementById('records-to').value
        });
        try {
            const rows = await window.api.get(`price-records${query}`);
            state.rows = Array.isArray(rows) ? rows : [];
            renderRecords();
        } catch (error) {
            state.rows = [];
            table.innerHTML = window.mpidsUi.emptyRow(7, error.data?.detail || 'Unable to load records.');
            updatePagination();
        }
    }

    function visibleRows() {
        const term = document.getElementById('records-search').value.trim().toLowerCase();
        if (!term) return state.rows;
        return state.rows.filter(row => [row.commodity_name, row.market_name, row.source, row.captured_by_name]
            .some(value => String(value || '').toLowerCase().includes(term)));
    }

    function renderRecords() {
        const table = document.getElementById('records-table');
        const rows = visibleRows();
        state.total = rows.length;
        if (state.offset >= state.total && state.offset > 0) {
            state.offset = Math.floor((state.total - 1) / state.limit) * state.limit;
        }
        const page = rows.slice(state.offset, state.offset + state.limit);
        table.innerHTML = page.length ? page.map(row => {
            const actions = row.status === 'rejected'
                ? `<button class="button button-small button-secondary" type="button" data-edit="${row.id}"><i class="fa-solid fa-pen"></i>Edit</button><button class="button button-small button-secondary" type="button" data-resubmit="${row.id}"><i class="fa-solid fa-rotate"></i>Resubmit</button><button class="button button-small button-danger" type="button" data-delete="${row.id}"><i class="fa-solid fa-trash"></i>Delete</button>`
                : '<span class="table-secondary">No actions</span>';
            return `<tr><td><span class="table-primary">${window.mpidsUi.escape(row.commodity_name)}</span><span class="table-secondary">${window.mpidsUi.escape(row.unit_of_measure)}</span></td><td>${window.mpidsUi.escape(row.market_name)}<span class="table-secondary">${window.mpidsUi.escape(row.market_county)}</span></td><td>${window.mpidsUi.formatDate(row.date_observed)}</td><td class="table-primary">${window.mpidsUi.formatMoney(row.price)}</td><td>${window.mpidsUi.badge(row.status)}</td><td class="decision-trail">${decisionTrail(row)}</td><td><div class="table-actions">${actions}</div></td></tr>`;
        }).join('') : window.mpidsUi.emptyRow(7, 'No price records match these filters.');
        updatePagination();
    }

    async function openEdit(id) {
        try {
            const record = await window.api.get(`price-records/${id}`);
            if (record.status !== 'rejected') {
                window.mpidsUi.toast('Only rejected records can be corrected.', 'warning');
                return;
            }
            state.editingId = record.id;
            const form = document.getElementById('record-edit-form');
            window.mpidsUi.fieldErrors(form);
            document.getElementById('edit-commodity').value = record.commodity_id;
            document.getElementById('edit-market').value = record.market_id;
            document.getElementById('edit-date').value = String(record.date_observed).slice(0, 10);
            document.getElementById('edit-price').value = record.price;
            document.getElementById('edit-source').value = record.source;
            document.getElementById('edit-record-context').innerHTML = `<strong>Rejected record #${record.id}</strong>${record.rejection_reason ? `<span>${window.mpidsUi.escape(record.rejection_reason)}</span>` : '<span>No rejection reason was recorded.</span>'}`;
            updateEditRange();
            window.mpidsUi.openModal('record-edit-modal');
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to open this record.', 'error');
        }
    }

    function validateEdit(form) {
        const commodity = document.getElementById('edit-commodity');
        const market = document.getElementById('edit-market');
        const date = document.getElementById('edit-date');
        const price = Number(document.getElementById('edit-price').value);
        const source = document.getElementById('edit-source').value.trim();
        const option = commodity.options[commodity.selectedIndex];
        const errors = {};
        if (!commodity.value) errors.commodity = 'Select a commodity.';
        if (!market.value) errors.market = 'Select a market.';
        if (!date.value) errors.date_observed = 'Enter the observation date.';
        else if (date.value > new Date().toISOString().slice(0, 10)) errors.date_observed = 'The observation date cannot be in the future.';
        if (!Number.isFinite(price) || price <= 0) errors.price = 'Enter a price greater than zero.';
        else if (option?.dataset.min && (price < Number(option.dataset.min) || price > Number(option.dataset.max))) errors.price = `Price must be between ${window.mpidsUi.formatMoney(option.dataset.min)} and ${window.mpidsUi.formatMoney(option.dataset.max)}.`;
        if (!source) errors.source = 'Enter the price source.';
        window.mpidsUi.fieldErrors(form, errors);
        return Object.keys(errors).length === 0;
    }

    document.addEventListener('mpids:ready', async () => {
        try {
            await loadOptions();
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to load filters.', 'error');
            return;
        }
        document.getElementById('edit-commodity').addEventListener('change', updateEditRange);
        document.getElementById('apply-record-filters').addEventListener('click', () => {
            const from = document.getElementById('records-from').value;
            const to = document.getElementById('records-to').value;
            if (from && to && from > to) {
                window.mpidsUi.toast('The start date must be on or before the end date.', 'warning');
                return;
            }
            state.offset = 0;
            loadRecords();
        });
        document.getElementById('records-search').addEventListener('input', () => {
            state.offset = 0;
            renderRecords();
        });
        document.getElementById('records-previous').addEventListener('click', () => {
            state.offset = Math.max(0, state.offset - state.limit);
            renderRecords();
        });
        document.getElementById('records-next').addEventListener('click', () => {
            if (state.offset + state.limit < state.total) {
                state.offset += state.limit;
                renderRecords();
            }
        });
        document.getElementById('records-table').addEventListener('click', async event => {
            const edit = event.target.closest('[data-edit]');
            const resubmit = event.target.closest('[data-resubmit]');
            const remove = event.target.closest('[data-delete]');
            if (edit) await openEdit(edit.dataset.edit);
            if (resubmit) {
                window.mpidsUi.setBusy(resubmit, true, 'Resubmitting');
                try {
                    await window.api.post(`price-records/${resubmit.dataset.resubmit}/resubmit`);
                    window.mpidsUi.toast('Record returned to the approval queue.', 'success');
                    await loadRecords();
                } catch (error) {
                    window.mpidsUi.toast(error.data?.detail || 'Unable to resubmit this record.', 'error');
                } finally {
                    window.mpidsUi.setBusy(resubmit, false);
                }
            }
            if (remove && window.confirm('Delete this rejected record? This action is recorded in the audit trail.')) {
                window.mpidsUi.setBusy(remove, true, 'Deleting');
                try {
                    await window.api.del(`price-records/${remove.dataset.delete}`);
                    window.mpidsUi.toast('Rejected record deleted.', 'success');
                    await loadRecords();
                } catch (error) {
                    window.mpidsUi.toast(error.data?.detail || 'Unable to delete this record.', 'error');
                } finally {
                    window.mpidsUi.setBusy(remove, false);
                }
            }
        });
        document.getElementById('record-edit-form').addEventListener('submit', async event => {
            event.preventDefault();
            if (!state.editingId) return;
            const form = event.currentTarget;
            if (!validateEdit(form)) {
                window.mpidsUi.toast('Please correct the highlighted fields.', 'warning');
                return;
            }
            const button = form.querySelector('button[type="submit"]');
            const body = Object.fromEntries(new FormData(form).entries());
            window.mpidsUi.setBusy(button, true, 'Saving');
            try {
                await window.api.patch(`price-records/${state.editingId}`, { ...body, price: Number(body.price) });
                window.mpidsUi.toast('Record corrected and returned to the approval queue.', 'success');
                window.mpidsUi.closeModal('record-edit-modal');
                state.editingId = null;
                await loadRecords();
            } catch (error) {
                if (error.data?.errors) window.mpidsUi.fieldErrors(form, error.data.errors);
                window.mpidsUi.toast(error.data?.detail || 'Unable to save the correction.', 'error');
            } finally {
                window.mpidsUi.setBusy(button, false);
            }
        });
        await loadRecords();
    });
})();
