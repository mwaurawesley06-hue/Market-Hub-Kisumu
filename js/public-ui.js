/* MPIDS public UI helpers — toasts, formatting, shared render bits.
 * Formatting mirrors js/public-ui.js of the PHP app so the static build
 * shows the same "Ksh 7,000.00" money and medium dates. */
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
        var icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        node.innerHTML = '<i class="fa-solid ' + (icons[tone] || icons.info) + '"></i><span>' + escapeHtml(message) + '</span>';
        region.appendChild(node);
        requestAnimationFrame(function () { node.classList.add('toast-visible'); });
        setTimeout(function () {
            node.classList.remove('toast-visible');
            setTimeout(function () { node.remove(); }, 220);
        }, 4200);
    }

    function formatMoney(value) {
        return new Intl.NumberFormat('en-KE', {
            style: 'currency',
            currency: 'KES',
            maximumFractionDigits: 2
        }).format(Number(value) || 0);
    }

    function formatDate(value) {
        if (!value) return '—';
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) return escapeHtml(value);
        return new Intl.DateTimeFormat('en-KE', { dateStyle: 'medium' }).format(date);
    }

    function formatDateTime(value) {
        if (!value) return '—';
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleString('en-KE');
    }

    function kenyanPhone(value) {
        var phone = String(value || '').trim().replace(/[\s\-().]/g, '');
        if (!phone) return null;
        if (/^\+?254([17][0-9]{8})$/.test(phone)) return '+254' + phone.replace(/^\+?254/, '');
        if (/^0([17][0-9]{8})$/.test(phone)) return '+254' + phone.slice(1);
        if (/^([17][0-9]{8})$/.test(phone)) return '+254' + phone;
        return null;
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
        kenyanPhone: kenyanPhone,
        clearFieldErrors: clearFieldErrors,
        setFieldError: setFieldError
    };
})();
