/* Public price dashboard — hero signal, filters, cards and directory table. */
(function () {
    'use strict';

    var ui = window.MPIDSUI;
    var api = window.MPIDSApi;

    var grid = document.getElementById('public-price-grid');
    var tableBody = document.getElementById('public-table');
    var heroPrice = document.getElementById('hero-price');
    var heroMarket = document.getElementById('hero-market');
    var updatedAt = document.getElementById('updated-at');
    var commoditySelect = document.getElementById('public-commodity');
    var marketSelect = document.getElementById('public-market');
    var fromInput = document.getElementById('public-from');
    var toInput = document.getElementById('public-to');
    var filterButton = document.getElementById('public-filter');

    var allRows = [];

    function setGrid(html) { if (grid) grid.innerHTML = html; }
    function setTable(html) { if (tableBody) tableBody.innerHTML = html; }

    function renderCard(row) {
        var trend = ui.trendMeta(row.trend);
        return '<article class="public-price-card">' +
            '<div class="public-price-photo">' +
                '<div class="public-price-photo-fallback"><i class="fa-solid ' + ui.commodityIcon(row.icon) + '"></i></div>' +
                '<span class="badge ' + trend.badge + ' trend"><i class="fa-solid ' + trend.icon + '"></i> ' + trend.label + '</span>' +
            '</div>' +
            '<div class="public-price-card-body">' +
                '<h3>' + ui.escapeHtml(row.commodity) + '</h3>' +
                '<span class="price">' + ui.formatMoney(row.currency, row.price) + '</span>' +
                '<div class="unit">per ' + ui.escapeHtml(row.unit) + '</div>' +
                '<span class="market"><i class="fa-solid fa-location-dot"></i> ' + ui.escapeHtml(row.market) +
                    ' · observed ' + ui.formatDate(row.observed_at) + '</span>' +
            '</div>' +
        '</article>';
    }

    function renderRow(row) {
        var trend = ui.trendMeta(row.trend);
        return '<tr>' +
            '<td><div class="cell-commodity"><span class="cell-thumb"><i class="fa-solid ' + ui.commodityIcon(row.icon) + '"></i></span>' +
                '<div><span class="table-primary">' + ui.escapeHtml(row.commodity) + '</span>' +
                '<span class="table-secondary">' + ui.escapeHtml(row.category) + '</span></div></div></td>' +
            '<td>' + ui.escapeHtml(row.market) + '</td>' +
            '<td>' + ui.formatDate(row.observed_at) + '</td>' +
            '<td><span class="table-primary">' + ui.formatMoney(row.currency, row.price) + '</span>' +
                '<span class="table-secondary">per ' + ui.escapeHtml(row.unit) + '</span></td>' +
            '<td><span class="badge ' + trend.badge + '"><i class="fa-solid ' + trend.icon + '"></i> ' + trend.label + '</span></td>' +
            '<td>' + ui.escapeHtml(row.category) + '</td>' +
        '</tr>';
    }

    function renderRows(rows, updatedAtIso) {
        allRows = rows;
        if (updatedAt) updatedAt.textContent = ui.formatDateTime(updatedAtIso);

        if (rows.length) {
            var top = rows[0];
            if (heroPrice) heroPrice.textContent = ui.formatMoney(top.currency, top.price) + ' / ' + top.unit;
            if (heroMarket) heroMarket.textContent = top.commodity + ' · ' + top.market + ' · ' + ui.formatDate(top.observed_at);
            setGrid(rows.map(renderCard).join(''));
            setTable(rows.map(renderRow).join(''));
        } else {
            if (heroPrice) heroPrice.textContent = '—';
            if (heroMarket) heroMarket.textContent = 'No prices match the current filters.';
            setGrid('<div class="public-table-panel loading-overlay"><i class="fa-solid fa-basket-shopping"></i><span>No published prices match these filters.</span></div>');
            setTable('<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-basket-shopping"></i><span>No published prices match these filters.</span></td></tr>');
        }
    }

    function renderError(message) {
        if (heroMarket) heroMarket.textContent = 'Prices could not be loaded.';
        setGrid('<div class="public-table-panel empty-state"><i class="fa-solid fa-triangle-exclamation"></i>' +
            '<h2>Prices unavailable</h2><p>' + ui.escapeHtml(message) + '</p></div>');
        setTable('<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-triangle-exclamation"></i>' +
            '<span>' + ui.escapeHtml(message) + '</span></td></tr>');
    }

    function currentFilters() {
        return {
            commodity: commoditySelect ? commoditySelect.value : '',
            market: marketSelect ? marketSelect.value : '',
            from: fromInput ? fromInput.value : '',
            to: toInput ? toInput.value : ''
        };
    }

    function loadPrices(filters) {
        setGrid('<div class="public-table-panel loading-overlay"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading published prices</span></div>');
        setTable('<tr><td colspan="6" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading prices</span></td></tr>');
        return api.getPublicPrices(filters).then(function (result) {
            renderRows(result.rows, result.updated_at);
            return result;
        }).catch(function (error) {
            renderError(error.message || 'Could not load published prices.');
            ui.toast(error.message || 'Could not load published prices.', 'error');
        });
    }

    function fillFilters() {
        return Promise.all([api.getCommodities(), api.getMarkets()]).then(function (parts) {
            var commodities = parts[0];
            var markets = parts[1];
            if (commoditySelect) {
                commoditySelect.innerHTML = '<option value="">All commodities</option>' +
                    commodities.map(function (item) {
                        return '<option value="' + ui.escapeHtml(item.name) + '">' + ui.escapeHtml(item.name) + '</option>';
                    }).join('');
            }
            if (marketSelect) {
                marketSelect.innerHTML = '<option value="">All markets</option>' +
                    markets.map(function (item) {
                        return '<option value="' + ui.escapeHtml(item.name) + '">' + ui.escapeHtml(item.name) + '</option>';
                    }).join('');
            }
        }).catch(function () { /* filters stay at their defaults */ });
    }

    if (filterButton) {
        filterButton.addEventListener('click', function () { loadPrices(currentFilters()); });
    }

    fillFilters();
    loadPrices();
})();
