(function () {
    'use strict';
    const ui = {
        escape(value) {
            return String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
        },
        formatMoney(value) {
            return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 2 }).format(Number(value) || 0);
        },
        // Mirrors normalize_phone() in includes/helpers.php so the form can
        // reject a bad number inline instead of bouncing it off the API.
        // Returns the canonical +254… form, or null when it is not a Kenyan mobile.
        kenyanPhone(value) {
            const phone = String(value ?? '').trim().replace(/[\s\-().]/g, '');
            if (!phone) return null;
            if (/^\+?254([17][0-9]{8})$/.test(phone)) return `+254${phone.replace(/^\+?254/, '')}`;
            if (/^0([17][0-9]{8})$/.test(phone)) return `+254${phone.slice(1)}`;
            if (/^([17][0-9]{8})$/.test(phone)) return `+254${phone}`;
            return null;
        },
        formatDate(value) {
            if (!value) return '—';
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return ui.escape(value);
            return new Intl.DateTimeFormat('en-KE', { dateStyle: 'medium' }).format(date);
        },
        toast(message, type = 'info') {
            const region = document.getElementById('toast-region');
            if (!region) return;
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info'}"></i><span>${ui.escape(message)}</span>`;
            region.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('toast-visible'));
            setTimeout(() => { toast.classList.remove('toast-visible'); setTimeout(() => toast.remove(), 220); }, 4200);
        },
        fieldErrors(form, errors = {}) {
            form.querySelectorAll('.field-error').forEach(element => { element.textContent = ''; });
            form.querySelectorAll('.is-invalid').forEach(element => {
                element.classList.remove('is-invalid');
                element.removeAttribute('aria-invalid');
                element.removeAttribute('aria-describedby');
            });
            Object.entries(errors).forEach(([name, message]) => {
                const input = form.querySelector(`[name="${name}"]`);
                const error = form.querySelector(`[data-error-for="${name}"]`);
                if (input) {
                    input.classList.add('is-invalid');
                    input.setAttribute('aria-invalid', 'true');
                }
                if (error) {
                    if (!error.id) error.id = `field-error-${name}`;
                    error.textContent = message;
                    if (input) input.setAttribute('aria-describedby', error.id);
                }
            });
        },
        setBusy(button, busy, label = 'Working') {
            if (!button) return;
            if (busy) { button.dataset.original = button.innerHTML; button.disabled = true; button.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> ${ui.escape(label)}`; }
            else { button.disabled = false; if (button.dataset.original) button.innerHTML = button.dataset.original; }
        }
    };
    window.publicUi = ui;
})();
