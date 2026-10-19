/* Default branding + service worker registration for public pages.
 * The PHP build injected admin-configured branding; on static hosting we
 * fall back to the MPIDS defaults shipped with the site. */
(function () {
    'use strict';

    var defaults = {
        name: 'MPIDS',
        tagline: 'Market Info Hub, Kisumu',
        logo: 'img/logo.png'
    };

    function applyBranding(brand) {
        var data = brand || defaults;
        document.querySelectorAll('[data-brand-name]').forEach(function (node) { node.textContent = data.name; });
        document.querySelectorAll('[data-brand-tagline]').forEach(function (node) { node.textContent = data.tagline; });
        document.querySelectorAll('[data-brand-logo]').forEach(function (node) {
            if (node.tagName === 'IMG') node.src = data.logo;
        });
    }

    applyBranding(defaults);

    if ('serviceWorker' in navigator && /^https?:$/.test(location.protocol)) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('service-worker.js').catch(function () { /* offline support is optional */ });
        });
    }
})();
