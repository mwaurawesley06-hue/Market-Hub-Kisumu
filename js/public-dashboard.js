(function () {
    'use strict';
    let requestId = 0;

    async function loadFilters() {
        try {
            const [commodities, markets] = await Promise.all([window.api.get('public/commodities'), window.api.get('public/markets')]);
            document.getElementById('public-commodity').innerHTML = '<option value="">All commodities</option>' + commodities.map(item => `<option value="${item.id}">${window.publicUi.escape(item.name)}</option>`).join('');
            document.getElementById('public-market').innerHTML = '<option value="">All markets</option>' + markets.map(item => `<option value="${item.id}">${window.publicUi.escape(item.name)}</option>`).join('');
        } catch (error) { window.publicUi.toast(error.data?.detail || 'Unable to load filters.', 'error'); }
    }

    function query() {
        const params = new URLSearchParams();
        [['commodity', 'public-commodity'], ['market', 'public-market'], ['date_from', 'public-from'], ['date_to', 'public-to']].forEach(([param, id]) => {
            const value = document.getElementById(id).value;
            if (value) params.set(param, value);
        });
        const value = params.toString();
        return value ? `?${value}` : '';
    }

    const TREND_LABEL = { up: 'rose', down: 'fell', same: 'unchanged' };

    function trendMarkup(item) {
        const trend = TREND_LABEL[item.trend] ? item.trend : 'same';
        const icon = trend === 'up' ? 'fa-arrow-trend-up' : trend === 'down' ? 'fa-arrow-trend-down' : 'fa-minus';
        return `<span class="trend trend-${trend}"><i class="fa-solid ${icon}"></i>${TREND_LABEL[trend]}</span>`;
    }

    function photoMarkup(item) {
        const src = item.image_path ? `img/commodities/${window.publicUi.escape(item.image_path)}` : null;
        return `<div class="public-price-photo">${src
            ? `<img src="${src}" alt="${window.publicUi.escape(item.commodity_name)}" loading="lazy">`
            : `<span class="public-price-photo-fallback"><i class="fa-solid fa-carrot"></i></span>`}<span class="public-price-photo-badge">${window.publicUi.escape(item.category || 'market')}</span>${trendMarkup(item)}</div>`;
    }

    function thumbMarkup(item) {
        const src = item.image_path ? `img/commodities/${window.publicUi.escape(item.image_path)}` : null;
        return `<span class="cell-thumb">${src ? `<img src="${src}" alt="" loading="lazy">` : '<i class="fa-solid fa-carrot"></i>'}</span>`;
    }

    function setHero(item) {
        document.getElementById('hero-price').textContent = item ? window.publicUi.formatMoney(item.price) : '—';
        document.getElementById('hero-market').textContent = item ? `${item.commodity_name} · ${item.market_name}` : 'No published prices match these filters.';
    }

    function renderEmpty(message) {
        setHero(null);
        document.getElementById('updated-at').textContent = '—';
        document.getElementById('public-price-grid').innerHTML = `<div class="public-table-panel empty-state"><i class="fa-solid fa-inbox"></i><h2>${window.publicUi.escape(message)}</h2><p>Approved market records will appear here.</p></div>`;
        document.getElementById('public-table').innerHTML = '<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-inbox"></i><span>No published prices match these filters.</span></td></tr>';
    }

    async function loadPrices() {
        const active = ++requestId;
        const grid = document.getElementById('public-price-grid');
        const table = document.getElementById('public-table');
        grid.innerHTML = '<div class="public-table-panel loading-overlay"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading published prices</span></div>';
        try {
            const data = await window.api.get(`public/dashboard${query()}`);
            if (active !== requestId) return;
            const items = data.rows || [];
            if (!items.length) {
                renderEmpty('No published prices yet');
                return;
            }
            document.getElementById('updated-at').textContent = data.updated_at ? new Date(data.updated_at).toLocaleString('en-KE') : 'just now';
            grid.innerHTML = items.slice(0, 6).map(item => `<article class="public-price-card">${photoMarkup(item)}<div class="public-price-card-body"><h3>${window.publicUi.escape(item.commodity_name)}</h3><span class="price">${window.publicUi.formatMoney(item.price)}</span><span class="unit">per ${window.publicUi.escape(item.unit_of_measure)}</span><span class="market"><i class="fa-solid fa-location-dot"></i> ${window.publicUi.escape(item.market_name)} · ${window.publicUi.formatDate(item.date_observed)}</span></div></article>`).join('');
            table.innerHTML = items.map(item => `<tr><td><span class="cell-commodity">${thumbMarkup(item)}<span class="table-primary">${window.publicUi.escape(item.commodity_name)}</span></span></td><td>${window.publicUi.escape(item.market_name)}</td><td>${window.publicUi.formatDate(item.date_observed)}</td><td class="table-primary">${window.publicUi.formatMoney(item.price)} / ${window.publicUi.escape(item.unit_of_measure)}</td><td>${trendMarkup(item)}</td><td>${window.publicUi.escape(item.category || '—')}</td></tr>`).join('');
            setHero(items[0]);
        } catch (error) {
            if (active !== requestId) return;
            setHero(null);
            document.getElementById('updated-at').textContent = '—';
            grid.innerHTML = '<div class="public-table-panel empty-state"><i class="fa-solid fa-triangle-exclamation"></i><h2>Unable to load prices</h2><p>Please refresh and try again.</p></div>';
            table.innerHTML = '<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>Published prices could not be loaded.</span></td></tr>';
            window.publicUi.toast(error.data?.detail || 'Unable to load published prices.', 'error');
        }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        await loadFilters();
        document.getElementById('public-filter').addEventListener('click', () => {
            const from = document.getElementById('public-from').value;
            const to = document.getElementById('public-to').value;
            if (from && to && from > to) {
                window.publicUi.toast('The start date must be on or before the end date.', 'warning');
                return;
            }
            loadPrices();
        });
        await loadPrices();
        if ('serviceWorker' in navigator) {
            // The page itself lives at the app root, so the worker and its scope
            // are resolved from here. Deriving them from the API URL instead would
            // walk one directory too far up (…/mpids/api has no trailing slash).
            const appBase = new URL('./', window.location.href);
            navigator.serviceWorker
                .register(new URL('service-worker.js', appBase).href, { scope: appBase.pathname })
                .catch((error) => { console.warn('Service worker registration failed.', error); });
        }
    });
})();
