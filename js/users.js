(function () {
    'use strict';
    let editingId = null;
    let rows = [];

    async function loadUsers() {
        const table = document.getElementById('users-table');
        table.innerHTML = window.mpidsUi.loadingRow(5, 'Loading administrators');
        try {
            const data = await window.api.get('users');
            rows = Array.isArray(data) ? data : [];
            table.innerHTML = rows.length ? rows.map(item => {
                const deactivate = item.is_active ? `<button class="button button-small button-danger" type="button" data-deactivate="${item.id}"><i class="fa-solid fa-user-slash"></i>Deactivate</button>` : '';
                return `<tr><td class="table-primary">${window.mpidsUi.escape(item.full_name)}</td><td>${window.mpidsUi.escape(item.email)}</td><td><span class="badge badge-soft-info"><i class="fa-solid fa-shield-halved"></i>Super Admin</span></td><td>${item.is_active ? window.mpidsUi.badge('active', 'Active') : window.mpidsUi.badge('inactive', 'Inactive')}</td><td><div class="table-actions"><button class="button button-small button-secondary" type="button" data-edit="${item.id}"><i class="fa-solid fa-pen"></i>Edit</button>${deactivate}</div></td></tr>`;
            }).join('') : window.mpidsUi.emptyRow(5, 'No administrators configured.');
        } catch (error) {
            table.innerHTML = window.mpidsUi.emptyRow(5, error.data?.detail || 'Unable to load administrators.');
        }
    }

    function openForm(item = null) {
        const form = document.getElementById('user-form');
        editingId = item?.id || null;
        form.reset();
        window.mpidsUi.fieldErrors(form);
        document.getElementById('user-title').textContent = item ? 'Edit Super Admin' : 'Add Super Admin';
        form.querySelector('button[type="submit"] span').textContent = item ? 'Save changes' : 'Save administrator';
        const password = document.getElementById('user-password');
        password.required = !item;
        if (item) {
            document.getElementById('user-name').value = item.full_name;
            document.getElementById('user-email').value = item.email;
            document.getElementById('user-phone').value = item.phone_number || '';
            document.getElementById('user-active').value = item.is_active ? '1' : '0';
        }
        window.mpidsUi.openModal('user-modal');
    }

    function validateForm(form) {
        const errors = {};
        if (!form.full_name.value.trim()) errors.full_name = 'Enter the user name.';
        if (!form.email.validity.valid) errors.email = 'Enter a valid email address.';
        if (form.password.value && form.password.value.length < 8) errors.password = 'Password must be at least 8 characters.';
        if (!editingId && !form.password.value) errors.password = 'Enter an initial password.';
        window.mpidsUi.fieldErrors(form, errors);
        return Object.keys(errors).length === 0;
    }

    document.addEventListener('mpids:ready', async () => {
        document.querySelector('[data-open-modal="user-modal"]').addEventListener('click', () => openForm());
        document.getElementById('users-table').addEventListener('click', async event => {
            const edit = event.target.closest('[data-edit]');
            const deactivate = event.target.closest('[data-deactivate]');
            if (edit) {
                const item = rows.find(entry => String(entry.id) === edit.dataset.edit);
                if (item) openForm(item);
                else window.mpidsUi.toast('This account is no longer listed. Reload the page.', 'error');
            }
            if (deactivate && window.confirm('Deactivate this Super Admin account?')) {
                window.mpidsUi.setBusy(deactivate, true, 'Deactivating');
                try {
                    await window.api.del(`users/${deactivate.dataset.deactivate}`);
                    window.mpidsUi.toast('Account deactivated.', 'success');
                    await loadUsers();
                } catch (error) {
                    window.mpidsUi.toast(error.data?.detail || 'Unable to deactivate account.', 'error');
                } finally {
                    window.mpidsUi.setBusy(deactivate, false);
                }
            }
        });
        document.getElementById('user-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            if (!validateForm(form)) return;
            const button = form.querySelector('button[type="submit"]');
            const body = Object.fromEntries(new FormData(form).entries());
            if (!body.password) delete body.password;
            window.mpidsUi.setBusy(button, true, 'Saving');
            try {
                if (editingId) await window.api.patch(`users/${editingId}`, body);
                else await window.api.post('users', body);
                window.mpidsUi.toast(editingId ? 'Super Admin updated.' : 'Super Admin added.', 'success');
                window.mpidsUi.closeModal('user-modal');
                editingId = null;
                form.reset();
                await loadUsers();
            } catch (error) {
                if (error.data?.errors) window.mpidsUi.fieldErrors(form, error.data.errors);
                window.mpidsUi.toast(error.data?.detail || 'Unable to save account.', 'error');
            } finally {
                window.mpidsUi.setBusy(button, false);
            }
        });
        await loadUsers();
    });
})();
