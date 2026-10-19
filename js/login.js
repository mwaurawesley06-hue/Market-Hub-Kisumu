/* Login page — demo sign-in for the static build. */
(function () {
    'use strict';

    var ui = window.MPIDSUI;
    var auth = window.MPIDSAuth;
    var form = document.getElementById('login-form');
    var submit = document.getElementById('login-submit');

    if (auth.getUser()) {
        window.location.replace('dashboard.html');
        return;
    }

    if (!form) return;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        ui.clearFieldErrors(form);

        var email = form.email.value.trim();
        var password = form.password.value;
        var valid = true;

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            ui.setFieldError(form, 'email', 'Enter a valid email address.');
            valid = false;
        }
        if (!password) {
            ui.setFieldError(form, 'password', 'Password is required.');
            valid = false;
        }
        if (!valid) return;

        if (submit) submit.disabled = true;
        // Static demo build: the PHP/MySQL auth API is not available on
        // GitHub Pages, so any valid-looking credentials open the demo shell.
        setTimeout(function () {
            auth.setUser({
                email: email,
                full_name: 'Demo Admin',
                role: 'super_admin',
                signed_in_at: new Date().toISOString()
            });
            ui.toast('Signed in to the demo admin shell.', 'success');
            window.location.href = 'dashboard.html';
        }, 250);
    });
})();
