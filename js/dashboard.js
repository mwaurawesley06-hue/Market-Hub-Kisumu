(function () {
    'use strict';
    if (!window.MPIDSAdmin) return;

    var ui = window.MPIDSUI;
    var main = document.getElementById('main-content');
    if (!main) return;

    function metric(label, value) {
        return '<div class="metric-card"><span>' + label + '</span><strong>' + value + '</strong></div>';
    }

    function trendCell(trend) {
        var map = {
            up: ['trend-up', 'Rose'],
            down: ['trend-down', 'Fell'],
            same: ['trend-flat', 'Unchanged'],
            unchanged: ['trend-flat', 'Unchanged']
        };
        var entry = map[trend] || ['trend-flat', 'Unchanged'];
        return '<span class="trend ' + entry[0] + '">' + entry[1] + '</span>';
    }

    main.innerHTML =
        '<section class="panel">' +
            '<div class="panel-header"><div><h2>Market overview</h2><p id="ov-sub">Loading published prices…</p></div></div>' +
            '<div class="panel-body"><div class="report-metrics" id="ov-metrics">' +
                metric('Published prices', '—') +
                metric('Commodities', '—') +
                metric('Markets', '—') +
                metric('Records rising', '—') +
            '</div></div>' +
        '</section>' +
        '<section class="panel">' +
            '<div class="panel-header"><div><h2>Latest published prices</h2><p>Newest approved observations first.</p></div></div>' +
            '<div class="table-wrap"><table class="data-table">' +
                '<thead><tr><th scope="col">Commodity</th><th scope="col">Market</th><th scope="col">Price</th><th scope="col">Trend</th><th scope="col">Observed</th></tr></thead>' +
                '<tbody id="ov-rows"><tr><td colspan="5" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading prices</span></td></tr>' +
            '</tbody></table></div>' +
        '</section>';

    window.MPIDSApi.getPublicPrices().then(function (data) {
        var rows = data.rows || [];
        var commodities = {};
        var markets = {};
        var rising = 0;
        rows.forEach(function (row) {
            commodities[row.commodity_id] = true;
            markets[row.market_name] = true;
            if (row.trend === 'up') rising++;
        });

        var sourceNote = data.live
            ? 'Live from the MPIDS API.'
            : 'Sample dataset — connect the Render API in js/config.js for live registrations.';
        document.getElementById('ov-sub').textContent =
            sourceNote + ' Last updated ' + (data.updated_at ? ui.formatDateTime(data.updated_at) : '—') + '.';

        document.getElementById('ov-metrics').innerHTML =
            metric('Published prices', String(rows.length)) +
            metric('Commodities', String(Object.keys(commodities).length)) +
            metric('Markets', String(Object.keys(markets).length)) +
            metric('Records rising', String(rising));

        var latest = rows.slice(0, 12);
        document.getElementById('ov-rows').innerHTML = latest.length
            ? latest.map(function (row) {
                return '<tr>' +
                    '<td class="table-primary">' + ui.escapeHtml(row.commodity_name) + '</td>' +
                    '<td>' + ui.escapeHtml(row.market_name) + '</td>' +
                    '<td class="table-primary">' + ui.formatMoney(row.price) + '</td>' +
                    '<td>' + trendCell(row.trend) + '</td>' +
                    '<td>' + ui.formatDate(row.date_observed) + '</td>' +
                '</tr>';
            }).join('')
            : '<tr><td colspan="5" class="table-empty">No published prices yet.</td></tr>';
    }).catch(function () {
        window.MPIDSAdmin.renderNotice(
            'Welcome back',
            'Published prices could not be loaded. Check your connection, then refresh this page.',
            'fa-grid-2'
        );
    });
})();
