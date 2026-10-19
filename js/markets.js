(function () {
    let editingId = null;
    let rows = [];

    function openForm(item = null) {
        editingId = null;
        const form = document.getElementById('market-form');
        form.reset();
        window.mpidsUi.fieldErrors(form);
        document.getElementById('market-title').textContent = item ? 'Edit market' : 'Add market';
        form.querySelector('button[type="submit"] span').textContent = item ? 'Save changes' : 'Save market';
        if (item) {
            editingId = item.id;
            document.getElementById('market-name').value = item.name;
            document.getElementById('market-county').value = item.county;
            document.getElementById('market-sub-county').value = item.sub_county || '';
            document.getElementById('market-active').value = item.is_active ? '1' : '0';
        }
        window.mpidsUi.openModal('market-modal');
    }

    async function loadMarkets() {
        const table = document.getElementById('markets-table');
        table.innerHTML = window.mpidsUi.loadingRow(5, 'Loading markets');
        try {
            const data = await window.api.get('markets');
            rows = Array.isArray(data) ? data : [];
            table.innerHTML = rows.length ? rows.map(item => {
                const deactivate = item.is_active ? `<button class="button button-small button-danger" type="button" data-delete="${item.id}"><i class="fa-solid fa-ban"></i>Deactivate</button>` : '';
                return `<tr><td class="table-primary">${window.mpidsUi.escape(item.name)}</td><td>${window.mpidsUi.escape(item.county)}</td><td>${window.mpidsUi.escape(item.sub_county || '—')}</td><td>${item.is_active ? window.mpidsUi.badge('active', 'Active') : window.mpidsUi.badge('inactive', 'Inactive')}</td><td><div class="table-actions"><button class="button button-small button-secondary" type="button" data-edit="${item.id}"><i class="fa-solid fa-pen"></i>Edit</button>${deactivate}</div></td></tr>`;
            }).join('') : window.mpidsUi.emptyRow(5, 'No markets configured.');
        } catch (error) { table.innerHTML = window.mpidsUi.emptyRow(5, error.data?.detail || 'Unable to load markets.'); }
    }

    document.addEventListener('mpids:ready', async () => {
        document.querySelector('[data-open-modal="market-modal"]').addEventListener('click', () => openForm());
        document.getElementById('markets-table').addEventListener('click', async event => {
            const edit = event.target.closest('[data-edit]');
            const remove = event.target.closest('[data-delete]');
            if (edit) {
                const item = rows.find(entry => String(entry.id) === edit.dataset.edit);
                if (item) openForm(item);
                else window.mpidsUi.toast('This market is no longer listed. Reload the page.', 'error');
            }
            if (remove && window.confirm('Deactivate this market? Existing price records will remain available.')) {
                try { await window.api.del(`markets/${remove.dataset.delete}`); window.mpidsUi.toast('Market deactivated.', 'success'); await loadMarkets(); } catch (error) { window.mpidsUi.toast(error.data?.detail || 'Unable to deactivate market.', 'error'); }
            }
        });
        document.getElementById('market-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const body = Object.fromEntries(new FormData(form).entries());
            const button = form.querySelector('button[type="submit"]');
            window.mpidsUi.setBusy(button, true, 'Saving');
            try {
                if (editingId) await window.api.patch(`markets/${editingId}`, body); else await window.api.post('markets', body);
                window.mpidsUi.toast(editingId ? 'Market updated.' : 'Market added.', 'success');
                window.mpidsUi.closeModal('market-modal');
                await loadMarkets();
            } catch (error) {
                if (error.data?.errors) window.mpidsUi.fieldErrors(form, error.data.errors);
                window.mpidsUi.toast(error.data?.detail || 'Unable to save market.', 'error');
            } finally { window.mpidsUi.setBusy(button, false); }
        });
        await loadMarkets();
    });
})();
