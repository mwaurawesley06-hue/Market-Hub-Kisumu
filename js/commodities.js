(function () {
    'use strict';
    let editingId = null;
    let rows = [];
    let uploader = null;

    function assetUrl(path) {
        return path ? `img/commodities/${window.mpidsUi.escape(path)}` : null;
    }

    function rowImage(item) {
        const url = assetUrl(item.image_path);
        return `<span class="cell-thumb">${url ? `<img src="${url}?v=${item.updated_token || ''}" alt="" loading="lazy">` : '<i class="fa-solid fa-image"></i>'}</span>`;
    }

    function openForm(item = null) {
        editingId = null;
        const form = document.getElementById('commodity-form');
        form.reset();
        window.mpidsUi.fieldErrors(form);
        document.getElementById('commodity-title').textContent = item ? 'Edit commodity' : 'Add commodity';
        form.querySelector('button[type="submit"] span').textContent = item ? 'Save changes' : 'Save commodity';
        if (item) {
            editingId = item.id;
            document.getElementById('commodity-name').value = item.name;
            document.getElementById('commodity-unit').value = item.unit_of_measure;
            document.getElementById('commodity-category').value = item.category;
            document.getElementById('commodity-min').value = item.min_price;
            document.getElementById('commodity-max').value = item.max_price;
            document.getElementById('commodity-active').value = item.is_active ? '1' : '0';
        }
        document.getElementById('image-current-path').value = item?.image_path || '';
        uploader?.render(assetUrl(item?.image_path));
        window.mpidsUi.openModal('commodity-modal');
    }

    async function loadCommodities() {
        const table = document.getElementById('commodities-table');
        table.innerHTML = window.mpidsUi.loadingRow(7, 'Loading commodities');
        try {
            const data = await window.api.get('commodities');
            rows = (Array.isArray(data) ? data : []).map(item => ({ ...item, updated_token: Date.now() }));
            table.innerHTML = rows.length ? rows.map(item => {
                const deactivate = item.is_active ? `<button class="button button-small button-danger" type="button" data-delete="${item.id}"><i class="fa-solid fa-ban"></i>Deactivate</button>` : '';
                return `<tr><td><span class="cell-commodity">${rowImage(item)}<span class="table-primary">${window.mpidsUi.escape(item.name)}</span></span></td><td>${window.mpidsUi.escape(item.category)}</td><td>${window.mpidsUi.escape(item.unit_of_measure)}</td><td>${window.mpidsUi.formatMoney(item.min_price)} – ${window.mpidsUi.formatMoney(item.max_price)}</td><td>${item.is_active ? window.mpidsUi.badge('active', 'Active') : window.mpidsUi.badge('inactive', 'Inactive')}</td><td>${item.image_path ? window.mpidsUi.badge('info', 'Has photo') : '<span class="table-secondary">No photo</span>'}</td><td><div class="table-actions"><button class="button button-small button-secondary" type="button" data-edit="${item.id}"><i class="fa-solid fa-pen"></i>Edit</button>${deactivate}</div></td></tr>`;
            }).join('') : window.mpidsUi.emptyRow(7, 'No commodities configured.');
        } catch (error) { table.innerHTML = window.mpidsUi.emptyRow(7, error.data?.detail || 'Unable to load commodities.'); }
    }

    document.addEventListener('mpids:ready', async () => {
        const imageInput = document.getElementById('commodity-image-input');
        uploader = window.mpidsImageUploader.createImageUploader({
            input: imageInput,
            preview: document.getElementById('commodity-image-preview'),
            removeButton: document.getElementById('commodity-image-remove'),
            commodityIdGetter: () => editingId,
            currentUrlGetter: () => assetUrl(document.getElementById('image-current-path').value),
            onChanged: () => loadCommodities(),
        });

        document.querySelector('[data-open-modal="commodity-modal"]').addEventListener('click', () => openForm());
        document.getElementById('commodities-table').addEventListener('click', async event => {
            const edit = event.target.closest('[data-edit]');
            const remove = event.target.closest('[data-delete]');
            if (edit) {
                const item = rows.find(entry => String(entry.id) === edit.dataset.edit);
                if (item) openForm(item);
                else window.mpidsUi.toast('This commodity is no longer listed. Reload the page.', 'error');
            }
            if (remove && window.confirm('Deactivate this commodity? Existing price records will remain available.')) {
                try { await window.api.del(`commodities/${remove.dataset.delete}`); window.mpidsUi.toast('Commodity deactivated.', 'success'); await loadCommodities(); } catch (error) { window.mpidsUi.toast(error.data?.detail || 'Unable to deactivate commodity.', 'error'); }
            }
        });
        document.getElementById('commodity-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const body = Object.fromEntries(new FormData(form).entries());
            const button = form.querySelector('button[type="submit"]');
            window.mpidsUi.setBusy(button, true, 'Saving');
            try {
                if (editingId) await window.api.patch(`commodities/${editingId}`, body); else await window.api.post('commodities', body);
                window.mpidsUi.toast(editingId ? 'Commodity updated.' : 'Commodity added.', 'success');
                window.mpidsUi.closeModal('commodity-modal');
                await loadCommodities();
            } catch (error) {
                if (error.data?.errors) window.mpidsUi.fieldErrors(form, error.data.errors);
                window.mpidsUi.toast(error.data?.detail || 'Unable to save commodity.', 'error');
            } finally { window.mpidsUi.setBusy(button, false); }
        });
        await loadCommodities();
    });
})();
