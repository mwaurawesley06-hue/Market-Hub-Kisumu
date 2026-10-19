/* Admin shell for the static build.
 * The PHP app rendered this chrome server-side; here we rebuild it in the
 * browser so admin pages keep their layout. Live data needs the PHP backend. */
(function () {
    'use strict';

    var auth = window.MPIDSAuth;
    var user = auth.getUser();
    if (!user) {
        window.location.replace('login.html');
        return;
    }

    var pageMap = {
        'dashboard.html': 'dashboard',
        'capture-price.html': 'capture',
        'approval.html': 'approval',
        'records.html': 'records',
        'reports.html': 'reports',
        'commodities.html': 'commodities',
        'markets.html': 'markets',
        'users.html': 'users',
        'audit-logs.html': 'audit-logs'
    };
    var file = location.pathname.split('/').pop() || 'dashboard.html';
    var page = pageMap[file] || 'dashboard';

    var titles = {
        dashboard: 'Dashboard',
        capture: 'Capture price',
        approval: 'Approval queue',
        records: 'Price records',
        reports: 'Reports',
        commodities: 'Commodities',
        markets: 'Markets',
        subscribers: 'SMS subscribers',
        users: 'Super Admins',
        'audit-logs': 'Audit trail',
        settings: 'Settings'
    };
    var pageTitle = titles[page] || 'Dashboard';

    function initials(name) {
        return String(name || 'MP').split(/\s+/).map(function (part) { return part.charAt(0); }).join('').slice(0, 2).toUpperCase();
    }

    function navLink(route, icon, label, href) {
        var active = route === page ? ' class="is-active"' : '';
        var target = href || (route + '.html');
        if (route === 'capture') target = 'capture-price.html';
        if (route === 'audit-logs') target = 'audit-logs.html';
        return '<a data-route="' + route + '"' + active + ' href="' + target + '"><i class="fa-solid ' + icon + '"></i><span>' + label + '</span></a>';
    }

    document.body.classList.add('admin-shell');
    document.body.setAttribute('data-page', page);
    document.title = pageTitle + ' Â· MPIDS';

    document.body.innerHTML =
        '<a class="skip-link" href="#main-content">Skip to content</a>' +
        '<div class="app-frame">' +
            '<aside class="sidebar" id="sidebar">' +
                '<div class="brand-lockup">' +
                    '<div class="brand-mark"><img src="img/logo.png" alt="MPIDS logo"></div>' +
                    '<div><strong>MPIDS</strong><span>Market Info Hub</span></div>' +
                '</div>' +
                '<div class="sidebar-location"><i class="fa-solid fa-location-dot"></i><span>Kisumu, Kenya</span></div>' +
                '<nav class="primary-nav" aria-label="Primary navigation">' +
                    navLink('dashboard', 'fa-grid-2', 'Overview') +
                    navLink('capture', 'fa-plus-circle', 'Capture price') +
                    navLink('approval', 'fa-clipboard-check', 'Approval queue') +
                    navLink('records', 'fa-list-check', 'Price records') +
                    '<div class="nav-section-label">Manage</div>' +
                    navLink('commodities', 'fa-wheat-awn', 'Commodities') +
                    navLink('markets', 'fa-store', 'Markets') +
                    navLink('subscribers', 'fa-users-viewfinder', 'SMS subscribers', 'subscriptions.html') +
                    navLink('reports', 'fa-chart-column', 'Reports') +
                    '<div class="nav-section-label">Channels</div>' +
                    '<a href="subscriptions.html"><i class="fa-solid fa-bell"></i><span>SMS subscriptions</span></a>' +
                    '<a href="ussd.html"><i class="fa-solid fa-phone"></i><span>USSD simulator</span></a>' +
                    '<a href="public-dashboard.html"><i class="fa-solid fa-globe"></i><span>Public dashboard</span></a>' +
                    '<div class="nav-section-label">Administration</div>' +
                    navLink('users', 'fa-users-gear', 'Super Admins') +
                    navLink('audit-logs', 'fa-shield-halved', 'Audit trail') +
                '</nav>' +
                '<div class="sidebar-footer">' +
                    '<div class="user-chip">' +
                        '<div class="avatar">' + initials(user.full_name) + '</div>' +
                        '<div class="user-chip-copy"><strong>' + (user.full_name || 'Administrator') + '</strong><span>Super Admin</span></div>' +
                    '</div>' +
                '</div>' +
            '</aside>' +
            '<div class="main-column">' +
                '<header class="topbar">' +
                    '<div class="topbar-leading">' +
                        '<div class="breadcrumb"><span>Market Info Hub</span><i class="fa-solid fa-chevron-right"></i><strong>' + pageTitle + '</strong></div>' +
                    '</div>' +
                    '<div class="topbar-actions">' +
                        '<a class="topbar-public-link" href="public-dashboard.html"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>View public site</span></a>' +
                        '<button class="icon-button" type="button" data-logout aria-label="Log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>' +
                    '</div>' +
                '</header>' +
                '<main class="page-content" id="main-content"></main>' +
            '</div>' +
        '</div>' +
        '<div class="toast-region" id="toast-region" aria-live="polite"></div>';

    document.querySelectorAll('[data-logout]').forEach(function (button) {
        button.addEventListener('click', function () {
            auth.clearUser();
            window.location.href = 'login.html';
        });
    });

    window.MPIDSAdmin = {
        page: page,
        pageTitle: pageTitle,
        renderNotice: function (title, message, icon) {
            var main = document.getElementById('main-content');
            if (!main) return;
            main.innerHTML = '<section class="empty-state">' +
                '<i class="fa-solid ' + (icon || 'fa-plug-circle-xmark') + '"></i>' +
                '<h1>' + (title || 'Demo admin shell') + '</h1>' +
                '<p>' + (message ||
                    'This static build of MPIDS runs on GitHub Pages, which cannot execute the PHP/MySQL backend. ' +
                    'Deploy the full app on a PHP host to capture, approve and publish live prices.') + '</p>' +
                '<p><a class="button button-primary" href="public-dashboard.html"><i class="fa-solid fa-globe"></i><span>Open the public dashboard</span></a></p>' +
                '</section>';
        }
    };
})();
