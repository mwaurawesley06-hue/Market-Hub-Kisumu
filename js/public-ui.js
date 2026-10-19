/* MPIDS public UI helpers — toasts, formatting, shared render bits. */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function toast(message, type) {
        var region = document.getElementById('toast-region');
        if (!region) return;
        var tone = type || 'info';
        var node = document.createElement('div');
        node.className = 'toast toast-' + tone;
        var icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        node.innerHTML = '<i class="fa-solid ' + (icons[tone] || icons.info) + '"></i><span>' + escapeHtml(message) + '</span>';
        region.appendChild(node);
        requestAnimationFrame(function () { node.classList.add('toast-visible'); });
        setTimeout(function () {
            node.classList.remove('toast-visible');
            setTimeout(function () { node.remove(); }, 250);
        }, 4200);
    }

    function formatMoney(currency, amount) {
        var code = currency || 'KES';
        var value = Number(amount || 0);
        return code + ' ' + value.toLocaleString('en-KE', { maximumFractionDigits: 2 });
    }

    function formatDate(iso) {
        if (!iso) return '—';
        var date = new Date(iso);
        if (isNaN(date.getTime())) return String(iso);
        return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function formatDateTime(iso) {
        if (!iso) return '—';
        var date = new Date(iso);
        if (isNaN(date.getTime())) return String(iso);
        return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) +
            ' ' + date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }

    function trendMeta(trend) {
        if (trend === 'up') return { label: 'rising', icon: 'fa-arrow-up', badge: 'badge-soft-published' };
        if (trend === 'down') return { label: 'falling', icon: 'fa-arrow-down', badge: 'badge-soft-rejected' };
        return { label: 'steady', icon: 'fa-minus', badge: 'badge-soft-info' };
    }

    function commodityIcon(icon) {
        var known = ['fa-fish', 'fa-carrot', 'fa-leaf', 'fa-wheat-awn', 'fa-seedling', 'fa-bowl-rice', 'fa-bottle-droplet', 'fa-apple-whole', 'fa-pepper-hot'];
        return known.indexOf(icon) !== -1 ? icon : 'fa-basket-shopping';
    }

    function clearFieldErrors(form) {
        if (!form) return;
        form.querySelectorAll('.field-error').forEach(function (node) { node.textContent = ''; });
        form.querySelectorAll('.is-invalid').forEach(function (node) { node.classList.remove('is-invalid'); });
    }

    function setFieldError(form, name, message) {
        var error = form.querySelector('[data-error-for="' + name + '"]');
        var field = form.querySelector('[name="' + name + '"]');
        if (error) error.textContent = message || '';
        if (field) field.classList.toggle('is-invalid', Boolean(message));
    }

    window.MPIDSUI = {
        escapeHtml: escapeHtml,
        toast: toast,
        formatMoney: formatMoney,
        formatDate: formatDate,
        formatDateTime: formatDateTime,
        trendMeta: trendMeta,
        commodityIcon: commodityIcon,
        clearFieldErrors: clearFieldErrors,
        setFieldError: setFieldError
    };
})();
