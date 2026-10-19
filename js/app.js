(function () {
    'use strict';
    const iconForStatus = { pending: 'fa-clock', published: 'fa-circle-check', rejected: 'fa-circle-xmark', active: 'fa-circle-check', inactive: 'fa-circle-minus' };
    const backgroundSelector = '.app-frame > .sidebar, .sidebar-backdrop, .main-column > .topbar';
    let activeModalId = null;
    let returnFocus = null;

    function backgroundTargets() {
        return [...document.querySelectorAll(backgroundSelector), ...[...document.querySelectorAll('#main-content > *')].filter(element => !element.classList.contains('modal'))];
    }

    function setBackgroundHidden(hidden) {
        backgroundTargets().forEach(element => {
            if (hidden) element.setAttribute('aria-hidden', 'true');
            else element.removeAttribute('aria-hidden');
        });
    }

    const ui = {
        escape(value) {
            return String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
        },
        formatMoney(value) {
            if (value === null || value === undefined || value === '') return '—';
            return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 2 }).format(Number(value) || 0);
        },
        formatDate(value, withTime = false) {
            if (!value) return '—';
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return ui.escape(value);
            return new Intl.DateTimeFormat('en-KE', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(date);
        },
        badge(status, label = '') {
            const value = String(status || '').toLowerCase();
            return `<span class="badge badge-soft-${ui.escape(value)}"><i class="fa-solid ${iconForStatus[value] || 'fa-circle'}"></i>${ui.escape(label || value)}</span>`;
        },
        toast(message, type = 'info') {
            const region = document.getElementById('toast-region');
            if (!region) return;
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-exclamation' : type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info'}"></i><span>${ui.escape(message)}</span>`;
            region.appendChild(toast);
            window.setTimeout(() => toast.classList.add('toast-visible'), 10);
            window.setTimeout(() => { toast.classList.remove('toast-visible'); window.setTimeout(() => toast.remove(), 220); }, 4200);
        },
        setBusy(button, busy, label = 'Working') {
            if (!button) return;
            if (busy) {
                button.dataset.originalLabel = button.innerHTML;
                button.disabled = true;
                button.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> ${ui.escape(label)}`;
            } else {
                button.disabled = false;
                if (button.dataset.originalLabel) button.innerHTML = button.dataset.originalLabel;
            }
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
        openModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            activeModalId = id;
            modal.classList.add('is-visible');
            document.body.classList.add('modal-open');
            setBackgroundHidden(true);
            const focusable = modal.querySelector('input, textarea, select, button');
            if (focusable) window.setTimeout(() => focusable.focus(), 20);
        },
        closeModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('is-visible');
            if (activeModalId === id) activeModalId = null;
            if (!document.querySelector('.modal.is-visible')) {
                document.body.classList.remove('modal-open');
                setBackgroundHidden(false);
                if (returnFocus?.isConnected) returnFocus.focus();
                returnFocus = null;
            }
        },
        emptyRow(colspan, message) {
            return `<tr><td colspan="${colspan}" class="table-empty"><i class="fa-solid fa-inbox"></i><span>${ui.escape(message)}</span></td></tr>`;
        },
        loadingRow(colspan, message = 'Loading data') {
            return `<tr><td colspan="${colspan}" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>${ui.escape(message)}</span></td></tr>`;
        },
        query(params = {}) {
            const search = new URLSearchParams();
            Object.entries(params).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) search.set(key, value); });
            const value = search.toString();
            return value ? `?${value}` : '';
        }
    };
    window.mpidsUi = ui;

    /** Promise-based confirmation dialog for destructive actions. */
    window.mpidsConfirm = function ({ title = 'Are you sure?', message = '', confirmLabel = 'Confirm', danger = true } = {}) {
        return new Promise(resolve => {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'mpids-confirm-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = `<div class="modal-dialog"><div class="modal-header"><h2>${ui.escape(title)}</h2><button class="icon-button" type="button" data-confirm-cancel aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body"><p class="modal-message">${ui.escape(message)}</p></div><div class="modal-footer"><button class="button button-secondary" type="button" data-confirm-cancel>Cancel</button><button class="button ${danger ? 'button-danger' : 'button-primary'}" type="button" data-confirm-ok>${ui.escape(confirmLabel)}</button></div></div>`;
            document.body.appendChild(modal);
            const finish = value => {
                ui.closeModal(modal.id);
                window.setTimeout(() => modal.remove(), 220);
                resolve(value);
            };
            modal.querySelectorAll('[data-confirm-cancel]').forEach(button => button.addEventListener('click', () => finish(false)));
            modal.querySelector('[data-confirm-ok]').addEventListener('click', () => finish(true));
            modal.addEventListener('click', event => { if (event.target === modal) finish(false); });
            ui.openModal(modal.id);
        });
    };

    function setActiveNavigation() {
        const page = document.body.dataset.page;
        document.querySelectorAll('.primary-nav [data-route]').forEach(link => {
            const active = link.dataset.route === page;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }

    function setSidebarExpanded() {
        const expanded = document.body.classList.contains('sidebar-open');
        document.querySelectorAll('[data-sidebar-toggle][aria-controls]').forEach(button => button.setAttribute('aria-expanded', String(expanded)));
    }

    function setupShell() {
        document.querySelectorAll('[data-sidebar-toggle]').forEach(button => button.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-open');
            setSidebarExpanded();
        }));
        setSidebarExpanded();
        document.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => ui.openModal(button.dataset.openModal)));
        document.querySelector('[data-logout]')?.addEventListener('click', async event => {
            const button = event.currentTarget;
            ui.setBusy(button, true, '');
            try { await window.auth.logout(); } catch (error) { ui.toast(error.data?.detail || 'Unable to log out.', 'error'); ui.setBusy(button, false); }
        });
        document.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => ui.closeModal(button.closest('.modal')?.id)));
        document.querySelectorAll('.modal').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) ui.closeModal(modal.id); }));
        document.addEventListener('keydown', event => {
            const modal = activeModalId ? document.getElementById(activeModalId) : null;
            if (!modal) return;
            if (event.key === 'Escape') {
                ui.closeModal(activeModalId);
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])')].filter(element => !element.hidden);
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        });
        setActiveNavigation();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        setupShell();
        try {
            const user = await window.auth.bootstrap();
            document.dispatchEvent(new CustomEvent('mpids:ready', { detail: { user } }));
        } catch (error) {
            if (error.status !== 401) ui.toast(error.data?.detail || 'Unable to load your session.', 'error');
        }
    });
})();
