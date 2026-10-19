document.addEventListener('DOMContentLoaded', async () => {
    const form = document.getElementById('login-form');
    // A signed-in admin must never be asked to sign in again. They land here
    // from the public pages (e.g. after registering an SMS subscription), and
    // their session is still valid — take them straight back to the workspace.
    try {
        const session = await window.api.get('auth/me');
        if (session && session.user) {
            window.location.replace('dashboard');
            return;
        }
    } catch (error) { /* not signed in — show the form */ }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = document.getElementById('login-submit');
        window.publicUi.fieldErrors(form);
        const email = form.email;
        const password = form.password;
        const errors = {};
        if (!email.validity.valid) errors.email = 'Enter a valid email address.';
        if (!password.value) errors.password = 'Enter your password.';
        window.publicUi.fieldErrors(form, errors);
        if (Object.keys(errors).length) return;
        window.publicUi.setBusy(button, true, 'Signing in');
        try {
            await window.auth.login(form.email.value.trim(), form.password.value);
            window.location.href = 'dashboard';
        } catch (error) {
            window.publicUi.toast(error.data?.detail || 'Unable to sign in.', 'error');
        } finally { window.publicUi.setBusy(button, false); }
    });
});
