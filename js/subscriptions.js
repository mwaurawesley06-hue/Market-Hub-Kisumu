(function () {
    'use strict';

    function resetNote() {
        const note = document.getElementById('local-token-note');
        note.hidden = true;
        note.textContent = '';
    }

    async function loadCommodities() {
        try {
            const data = await window.api.get('public/commodities');
            const options = Array.isArray(data) ? data : [];
            document.getElementById('subscriber-commodity').innerHTML = '<option value="">Select a commodity</option>' + options.map(item => `<option value="${item.id}">${window.publicUi.escape(item.name)}</option>`).join('');
        } catch (error) { window.publicUi.toast(error.data?.detail || 'Unable to load commodities.', 'error'); }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        await loadCommodities();
        const form = document.getElementById('subscription-form');
        const panel = document.getElementById('verification-panel');
        const phoneField = document.getElementById('verification-phone');
        const idField = document.getElementById('verification-id');
        const codeField = document.getElementById('verification-code');
        const codeError = document.querySelector('#verification-panel [data-error-for="code"]');
        const unsubscribeForm = document.getElementById('unsubscribe-form');
        const unsubscribePhone = document.getElementById('unsubscribe-phone');
        const unsubscribeToken = document.getElementById('unsubscribe-token');

        function showCodeError(message) {
            if (!codeError) return;
            codeError.id = codeError.id || 'field-error-code';
            codeError.textContent = message;
            codeField.classList.toggle('is-invalid', Boolean(message));
            if (message) {
                codeField.setAttribute('aria-invalid', 'true');
                codeField.setAttribute('aria-describedby', codeError.id);
            } else {
                codeField.removeAttribute('aria-invalid');
                codeField.removeAttribute('aria-describedby');
            }
        }

        function closeVerification() {
            panel.hidden = true;
            idField.value = '';
            codeField.value = '';
            showCodeError('');
            // Deliberately keeps the local-token note visible: on a sandbox the
            // unsubscribe token is only ever shown here, so hiding it with the
            // panel would make the unsubscribe form unusable.
        }

        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = document.getElementById('subscribe-submit');
            window.publicUi.fieldErrors(form);
            const phone = form.phone_number.value.trim();
            const errors = {};
            if (!window.publicUi.kenyanPhone(phone)) errors.phone_number = 'Enter a Kenyan mobile number, e.g. 0712345678.';
            if (!form.commodity.value) errors.commodity = 'Select a commodity.';
            if (!['daily', 'weekly'].includes(form.frequency.value)) errors.frequency = 'Choose daily or weekly alerts.';
            window.publicUi.fieldErrors(form, errors);
            if (Object.keys(errors).length) return;
            window.publicUi.setBusy(button, true, 'Requesting');
            try {
                const data = await window.api.post('subscriptions', Object.fromEntries(new FormData(form).entries()));
                if (!data.verification_required) {
                    closeVerification();
                    window.publicUi.toast('This phone number is already verified for the selected alerts.', 'info');
                    return;
                }
                panel.hidden = false;
                phoneField.value = form.phone_number.value.trim();
                unsubscribePhone.value = form.phone_number.value.trim();
                idField.value = data.subscription_id;
                codeField.value = data.sandbox_otp || '';
                showCodeError('');
                const note = document.getElementById('local-token-note');
                if (data.sandbox_otp) { note.hidden = false; note.textContent = 'Local development code shown because SMS credentials are not configured.'; }
                window.publicUi.toast('Subscription created. Verify your phone to activate alerts.', 'success');
            } catch (error) {
                if (error.data?.errors) window.publicUi.fieldErrors(form, error.data.errors);
                window.publicUi.toast(error.data?.detail || 'Unable to create subscription.', 'error');
            } finally { window.publicUi.setBusy(button, false); }
        });

        document.getElementById('verify-subscription').addEventListener('click', async event => {
            const button = event.currentTarget;
            const code = codeField.value.trim();
            const phone = phoneField.value.trim();
            if (!phone) { showCodeError('Request a subscription before verifying.'); return; }
            if (!/^\d{6}$/.test(code)) { showCodeError('Enter the 6-digit verification code.'); return; }
            showCodeError('');
            window.publicUi.setBusy(button, true, 'Verifying');
            try {
                const data = await window.api.post('subscriptions/verify', { phone_number: phone, code, subscription_id: idField.value ? Number(idField.value) : undefined });
                const tokenNote = document.getElementById('local-token-note');
                if (data.sandbox_unsubscribe_token) {
                    tokenNote.hidden = false;
                    tokenNote.textContent = `Local development unsubscribe token: ${data.sandbox_unsubscribe_token}`;
                    unsubscribeToken.value = data.sandbox_unsubscribe_token;
                }
                closeVerification();
                window.publicUi.toast('Subscription verified. Alerts are now active.', 'success');
            } catch (error) {
                if (error.data?.errors?.code) showCodeError(error.data.errors.code);
                window.publicUi.toast(error.data?.detail || 'Unable to verify subscription.', 'error');
            }
            finally { window.publicUi.setBusy(button, false); }
        });

        unsubscribeForm.addEventListener('submit', async event => {
            event.preventDefault();
            const button = document.getElementById('unsubscribe-submit');
            window.publicUi.fieldErrors(unsubscribeForm);
            const phone = unsubscribePhone.value.trim();
            const token = unsubscribeToken.value.trim();
            const errors = {};
            if (!window.publicUi.kenyanPhone(phone)) errors.phone_number = 'Enter a Kenyan mobile number, e.g. 0712345678.';
            if (!token) errors.token = 'Enter the unsubscribe token from your SMS.';
            window.publicUi.fieldErrors(unsubscribeForm, errors);
            if (Object.keys(errors).length) return;
            window.publicUi.setBusy(button, true, 'Unsubscribing');
            try {
                await window.api.del('subscriptions/unsubscribe', { phone_number: phone, token });
                unsubscribeToken.value = '';
                resetNote();
                closeVerification();
                window.publicUi.toast('You have been unsubscribed from SMS price alerts.', 'success');
            } catch (error) {
                if (error.data?.errors) window.publicUi.fieldErrors(unsubscribeForm, error.data.errors);
                window.publicUi.toast(error.data?.detail || 'Unable to unsubscribe with those details.', 'error');
            } finally { window.publicUi.setBusy(button, false); }
        });
    });
})();
