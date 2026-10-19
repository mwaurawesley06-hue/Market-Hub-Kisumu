(function () {
    'use strict';
    const auth = {
        user: null,
        async bootstrap() {
            try {
                const data = await window.api.get('auth/me');
                auth.user = data.user;
                if (data.csrf_token) window.api.setCsrf(data.csrf_token);
                return auth.user;
            } catch (error) {
                if (error.status === 401) {
                    window.location.href = 'login';
                }
                throw error;
            }
        },
            async login(email, password) {
                const data = await window.api.post('auth/login', { username: email, password });
                // The login endpoint answers with the user fields at the top level,
                // while /auth/me nests them under `user`. Accept both.
                auth.user = data.user || data;
                if (data.csrf_token) window.api.setCsrf(data.csrf_token);
                return auth.user;
            },
        async logout() {
            await window.api.post('auth/logout');
            window.sessionStorage.removeItem('mpids_csrf');
            window.location.href = 'login';
        }
    };
    window.auth = auth;
})();
