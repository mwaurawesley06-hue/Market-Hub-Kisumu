/* Public price dashboard — static port of js/public-dashboard.js.
 * Renders the latest published price per commodity/market with the same
 * category badge, rose/fell/unchanged trend chip and commodity photos. */
(function () {
    'use strict';

    var ui = window.MPIDSUI;
    var api = window.MPIDSApi;
    var requestId = 0;

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

    var TREND_LABEL = { up: 'rose', down: 'fell', same: 'unchanged' };

    function trendMarkup(item) {
        var trend = TREND_LABEL[item.trend] ? item.trend : 'same';
        var icon = trend === 'up' ? 'fa-arrow-trend-up' : trend === 'down' ? 'fa-arrow-trend-down' : 'fa-minus';
        return '<span class="trend trend-' + trend + '"><i class="fa-solid ' + icon + '"></i>' + TREND_LABEL[trend] + '</span>';
    }

    function photoSrc(item) {
        return item.image_path ? 'img/commodities/' + ui.escapeHtml(item.image_path) : null;
    }

    function photoMarkup(item) {
        var src = photoSrc(item);
        return '<div class="public-price-photo">' + (src
            ? '<img src="' + src + '" alt="' + ui.escapeHtml(item.commodity_name) + '" loading="lazy">'
            : '<span class="public-price-photo-fallback"><i class="fa-solid fa-carrot"></i></span>') +
            '<span class="public-price-photo-badge">' + ui.escapeHtml(item.category || 'market') + '</span>' +
            trendMarkup(item) + '</div>';
    }

    function thumbMarkup(item) {
        var src = photoSrc(item);
        return '<span class="cell-thumb">' + (src
            ? '<img src="' + src + '" alt="" loading="lazy">'
            : '<i class="fa-solid fa-carrot"></i>') + '</span>';
    }

    function setHero(item) {
        if (heroPrice) heroPrice.textContent = item ? ui.formatMoney(item.price) : '—';
        if (heroMarket) {
            heroMarket.textContent = item
                ? item.commodity_name + ' · ' + item.market_name
                : 'No published prices match these filters.';
        }
    }

    function renderEmpty(message) {
        setHero(null);
        if (updatedAt) updatedAt.textContent = '—';
        if (grid) {
            grid.innerHTML = '<div class="public-table-panel empty-state"><i class="fa-solid fa-inbox"></i>' +
                '<h2>' + ui.escapeHtml(message) + '</h2><p>Approved market records will appear here.</p></div>';
        }
        if (tableBody) {
            tableBody.innerHTML = '<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-inbox"></i>' +
                '<span>No published prices match these filters.</span></td></tr>';
        }
    }

    function loadFilters() {
        return Promise.all([api.getCommodities(), api.getMarkets()]).then(function (parts) {
            if (commoditySelect) {
                commoditySelect.innerHTML = '<option value="">All commodities</option>' +
                    parts[0].map(function (item) {
                        return '<option value="' + item.id + '">' + ui.escapeHtml(item.name) + '</option>';
                    }).join('');
            }
            if (marketSelect) {
                marketSelect.innerHTML = '<option value="">All markets</option>' +
                    parts[1].map(function (item) {
                        return '<option value="' + item.id + '">' + ui.escapeHtml(item.name) + '</option>';
                    }).join('');
            }
        }).catch(function () { /* filters stay at their defaults */ });
    }

    function loadPrices() {
        var active = ++requestId;
        if (grid) {
            grid.innerHTML = '<div class="public-table-panel loading-overlay"><i class="fa-solid fa-circle-notch fa-spin"></i>' +
                '<span>Loading published prices</span></div>';
        }
        var filters = {
            commodity: commoditySelect ? commoditySelect.value : '',
            market: marketSelect ? marketSelect.value : '',
            date_from: fromInput ? fromInput.value : '',
            date_to: toInput ? toInput.value : ''
        };
        return api.getPublicPrices(filters).then(function (data) {
            if (active !== requestId) return;
            var items = data.rows || [];
            if (!items.length) {
                renderEmpty('No published prices yet');
                return;
            }
            if (updatedAt) updatedAt.textContent = data.updated_at ? new Date(data.updated_at).toLocaleString('en-KE') : 'just now';
            if (grid) {
                grid.innerHTML = items.slice(0, 6).map(function (item) {
                    return '<article class="public-price-card">' + photoMarkup(item) +
                        '<div class="public-price-card-body">' +
                        '<h3>' + ui.escapeHtml(item.commodity_name) + '</h3>' +
                        '<span class="price">' + ui.formatMoney(item.price) + '</span>' +
                        '<span class="unit">per ' + ui.escapeHtml(item.unit_of_measure) + '</span>' +
                        '<span class="market"><i class="fa-solid fa-location-dot"></i> ' +
                        ui.escapeHtml(item.market_name) + ' · ' + ui.formatDate(item.date_observed) + '</span>' +
                        '</div></article>';
                }).join('');
            }
            if (tableBody) {
                tableBody.innerHTML = items.map(function (item) {
                    return '<tr>' +
                        '<td><span class="cell-commodity">' + thumbMarkup(item) +
                        '<span class="table-primary">' + ui.escapeHtml(item.commodity_name) + '</span></span></td>' +
                        '<td>' + ui.escapeHtml(item.market_name) + '</td>' +
                        '<td>' + ui.formatDate(item.date_observed) + '</td>' +
                        '<td class="table-primary">' + ui.formatMoney(item.price) + ' / ' + ui.escapeHtml(item.unit_of_measure) + '</td>' +
                        '<td>' + trendMarkup(item) + '</td>' +
                        '<td>' + ui.escapeHtml(item.category || '—') + '</td>' +
                        '</tr>';
                }).join('');
            }
            setHero(items[0]);
        }).catch(function (error) {
            if (active !== requestId) return;
            renderEmpty('Unable to load prices');
            ui.toast(error.message || 'Unable to load published prices.', 'error');
        });
    }

    if (filterButton) {
        filterButton.addEventListener('click', function () {
            var from = fromInput ? fromInput.value : '';
            var to = toInput ? toInput.value : '';
            if (from && to && from > to) {
                ui.toast('The start date must be on or before the end date.', 'warning');
                return;
            }
            loadPrices();
        });
    }

    loadFilters().then(loadPrices);
})();
