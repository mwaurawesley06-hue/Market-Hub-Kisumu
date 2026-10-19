(function () {
    'use strict';
    // Section 3.5.4 duplicate-detection workflow. The backend decides whether a
    // duplicate exists (409 + duplicate payload); this file only presents the
    // warning and the three choices — every check is repeated server-side.
    let duplicateRecord = null;

    function ui() {
        return window.mpidsUi;
    }

    // Mirrors override_justification_error() in api/handlers/price-records.php:
    // at least 10 characters and at least three distinct alphabetic words.
    function justificationError(text) {
        const value = text.trim();
        if (value.length < 10) {
            return 'The justification must be at least 10 characters.';
        }
        const words = new Set(
            value.split(/\s+/u)
                .filter(word => /\p{L}/u.test(word))
                .map(word => word.toLowerCase())
        );
        if (words.size < 3) {
            return 'The justification must contain at least three real words, not only numbers or symbols.';
        }
        return null;
    }

    function options(items, selected = '') {
        return items.map(item => {
            const range = item.min_price === undefined ? '' : ` data-min="${ui().escape(item.min_price)}" data-max="${ui().escape(item.max_price)}"`;
            return `<option value="${item.id}"${range}${String(item.id) === String(selected) ? ' selected' : ''}>${ui().escape(item.name)}${item.unit_of_measure ? ` (${ui().escape(item.unit_of_measure)})` : ''}</option>`;
        }).join('');
    }

    function updateRange() {
        const select = document.getElementById('commodity');
        const option = select.options[select.selectedIndex];
        const commodity = option?.dataset.min && option?.dataset.max ? { min_price: Number(option.dataset.min), max_price: Number(option.dataset.max) } : null;
        const hint = document.getElementById('price-range');
        const price = document.getElementById('price');
        if (!commodity) {
            hint.innerHTML = '<i class="fa-solid fa-circle-info"></i>Select a commodity first';
            price.removeAttribute('min');
            price.removeAttribute('max');
            return;
        }
        hint.innerHTML = `<i class="fa-solid fa-circle-check"></i>${ui().formatMoney(commodity.min_price)} – ${ui().formatMoney(commodity.max_price)}`;
        price.min = commodity.min_price;
        price.max = commodity.max_price;
    }

    function resetForm() {
        duplicateRecord = null;
        const form = document.getElementById('capture-form');
        form.reset();
        document.getElementById('date_observed').value = new Date().toISOString().slice(0, 10);
        document.getElementById('override_justification').value = '';
        setJustificationError('');
        setDetailVisible(false);
        setOverrideAvailability();
        ui().fieldErrors(form);
        updateRange();
    }

    function setOverrideAvailability() {
        const override = document.getElementById('confirm-override');
        if (override) override.disabled = justificationError(document.getElementById('override_justification').value) !== null;
    }

    function setJustificationError(message) {
        const slot = document.querySelector('[data-error-for="override_justification"]');
        if (slot) slot.textContent = message;
    }

    function detailRows(record) {
        const rows = [
            ['Record ID', `#${record.id}`],
            ['Price', `${ui().formatMoney(record.price)} per ${record.unit_of_measure || 'unit'}`],
            ['Recorded by', record.captured_by_name || 'Unknown officer'],
            ['Entered on', record.created_at ? ui().formatDate(record.created_at, true) : 'Not recorded'],
        ];
        if (record.approved_by_name) rows.push(['Approved by', record.approved_by_name]);
        if (record.override_justification) rows.push(['Last override reason', record.override_justification]);
        return rows;
    }

    function renderDetail(targetId, rows) {
        document.getElementById(targetId).innerHTML = rows
            .map(([label, value]) => `<div class="detail-row"><dt>${ui().escape(label)}</dt><dd>${ui().escape(value)}</dd></div>`)
            .join('');
    }

    function setDetailVisible(visible) {
        document.getElementById('duplicate-detail-panel').hidden = !visible;
    }

    // Entry point for the 409 duplicate payload: shows commodity, market, date,
    // the existing price and officer, the new entry, and the three choices.
    function showDuplicateWarning(existing, newEntry) {
        duplicateRecord = existing;
        document.getElementById('duplicate-context').innerHTML =
            `<strong>${ui().escape(existing.commodity_name || 'Commodity')}</strong>` +
            `<span>${ui().escape(existing.market_name || 'Market')} · ${ui().formatDate(existing.date_observed)} · Record ID #${ui().escape(existing.id ?? '—')}</span>`;

        renderDetail('duplicate-existing', detailRows(existing));
        renderDetail('duplicate-new', [
            ['Price', `${ui().formatMoney(newEntry.price)} per ${(existing.unit_of_measure) || 'unit'}`],
            ['Date', ui().formatDate(newEntry.date_observed)],
            ['Source', newEntry.source || '—'],
            ['To be recorded under', 'your signed-in account'],
        ]);

        document.getElementById('override_justification').value = '';
        setJustificationError('');
        setDetailVisible(false);
        setOverrideAvailability();
        ui().openModal('duplicate-modal');
    }

    async function loadOptions() {
        try {
            // Authenticated active lists: the public projection omits min_price/max_price,
            // which the price-range hint and the input bounds depend on.
            const [commodities, markets] = await Promise.all([window.api.get('commodities?is_active=1'), window.api.get('markets?is_active=1')]);
            const commoditySelect = document.getElementById('commodity');
            commoditySelect.innerHTML = '<option value="">Select a commodity</option>' + options(commodities);
            document.getElementById('market').innerHTML = '<option value="">Select a market</option>' + options(markets);
        } catch (error) {
            ui().toast(error.data?.detail || 'Unable to load active reference data.', 'error');
        }
    }

    function validateCapture(form) {
        const commodity = document.getElementById('commodity');
        const market = document.getElementById('market');
        const date = document.getElementById('date_observed');
        const price = Number(document.getElementById('price').value);
        const source = document.getElementById('source').value.trim();
        const option = commodity.options[commodity.selectedIndex];
        const min = option?.dataset.min;
        const max = option?.dataset.max;
        const errors = {};
        if (!commodity.value) errors.commodity = 'Select a commodity.';
        if (!market.value) errors.market = 'Select a market.';
        if (!date.value) errors.date_observed = 'Enter the observation date.';
        else if (date.value > new Date().toISOString().slice(0, 10)) errors.date_observed = 'The observation date cannot be in the future.';
        if (!Number.isFinite(price) || price <= 0) errors.price = 'Enter a price greater than zero.';
        else if (min && max && (price < Number(min) || price > Number(max))) errors.price = `Price must be between ${ui().formatMoney(min)} and ${ui().formatMoney(max)}.`;
        if (!source) errors.source = 'Enter the price source.';
        ui().fieldErrors(form, errors);
        return Object.keys(errors).length === 0;
    }

    async function submit(body, button, override = false) {
        ui().setBusy(button, true, override ? 'Overriding' : 'Submitting');
        try {
            await window.api.post('price-records', body);
            ui().toast(override ? 'Duplicate override recorded and sent for approval.' : 'Price captured and sent for approval.', 'success');
            ui().closeModal('duplicate-modal');
            resetForm();
        } catch (error) {
            if (error.status === 409 && error.data?.duplicate) {
                showDuplicateWarning(error.data.existing || {}, body);
            } else if (error.status === 401) {
                ui().toast('Your session has expired. Please sign in again.', 'error');
                window.location.href = 'login';
            } else if (error.data?.errors) {
                if (error.data.errors.override_justification) {
                    setJustificationError(error.data.errors.override_justification);
                    ui().toast(error.data.detail || 'A written justification is required to override a duplicate price entry.', 'warning');
                } else {
                    ui().fieldErrors(document.getElementById('capture-form'), error.data.errors);
                    ui().toast(error.data.detail || 'Please correct the highlighted fields.', 'warning');
                }
            } else {
                ui().toast(error.data?.detail || 'Unable to submit the price.', 'error');
            }
        } finally {
            ui().setBusy(button, false);
        }
    }

    document.addEventListener('mpids:ready', async () => {
        await loadOptions();
        document.getElementById('commodity').addEventListener('change', updateRange);
        document.getElementById('capture-form').addEventListener('submit', event => {
            event.preventDefault();
            const form = event.currentTarget;
            if (!validateCapture(form)) {
                ui().toast('Please correct the highlighted fields.', 'warning');
                return;
            }
            const body = Object.fromEntries(new FormData(form).entries());
            submit({ ...body, price: Number(body.price) }, document.getElementById('capture-submit'));
        });

        // Option 1 — Abandon Entry: nothing saved, no audit record; the form
        // keeps the clerk's values so they can adjust the date or market.
        document.getElementById('duplicate-abandon').addEventListener('click', () => {
            duplicateRecord = null;
            ui().closeModal('duplicate-modal');
            ui().toast('Entry abandoned — the existing record was left unchanged.', 'info');
        });

        // Option 2 — View Existing Record: fetch and show the full stored record.
        document.getElementById('duplicate-view').addEventListener('click', async event => {
            const button = event.currentTarget;
            if (!duplicateRecord?.id) {
                ui().toast('The existing record could not be identified.', 'error');
                return;
            }
            ui().setBusy(button, true, 'Loading');
            try {
                const record = await window.api.get(`price-records/${duplicateRecord.id}`);
                duplicateRecord = record;
                renderDetail('duplicate-detail', detailRows(record).concat([
                    ['Commodity', record.commodity_name || '—'],
                    ['Market', record.market_name || '—'],
                    ['Date observed', ui().formatDate(record.date_observed)],
                    ['Status', record.status || '—'],
                    ['Source', record.source || '—'],
                ]));
                setDetailVisible(true);
            } catch (error) {
                if (error.status === 404) {
                    ui().toast('This record no longer exists — closing the warning.', 'warning');
                    duplicateRecord = null;
                    ui().closeModal('duplicate-modal');
                } else {
                    ui().toast(error.data?.detail || 'Unable to load the existing record.', 'error');
                }
            } finally {
                ui().setBusy(button, false);
            }
        });

        // Option 3 — Override and Submit: stays disabled until the
        // justification is meaningful (mirrors the server-side rule).
        const justification = document.getElementById('override_justification');
        justification.addEventListener('input', () => {
            setOverrideAvailability();
            if (justificationError(justification.value) === null) setJustificationError('');
        });
        document.getElementById('confirm-override').addEventListener('click', () => {
            const error = justificationError(justification.value);
            if (error || !duplicateRecord) {
                setJustificationError(error || 'A written justification is required to override a duplicate price entry.');
                return;
            }
            const form = document.getElementById('capture-form');
            const body = Object.fromEntries(new FormData(form).entries());
            submit({ ...body, price: Number(body.price), override: true, override_justification: justification.value.trim() }, document.getElementById('confirm-override'), true);
        });
    });
})();
