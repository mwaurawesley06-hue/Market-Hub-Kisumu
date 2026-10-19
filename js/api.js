(function () {
    'use strict';
    const scriptUrl = document.currentScript?.src;
    const basePath = scriptUrl
        ? new URL('../', scriptUrl).pathname
        : window.location.pathname.slice(0, window.location.pathname.lastIndexOf('/') + 1);
    const apiBase = window.MPIDS_API_BASE || `${window.location.origin}${basePath}api`;
    let csrfToken = window.sessionStorage.getItem('mpids_csrf') || document.body.dataset.csrf || '';

    function setCsrf(token) {
        if (!token) return;
        csrfToken = token;
        window.sessionStorage.setItem('mpids_csrf', token);
    }

    async function request(method, url, body) {
        const options = {
            method,
            credentials: 'include',
            headers: {
                Accept: 'application/json'
            }
        };
        if (body !== undefined && !(body instanceof FormData)) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        } else if (body instanceof FormData) {
            options.body = body; // multipart: let the browser set the boundary
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) {
            options.headers['X-CSRF-Token'] = csrfToken;
        }
        const response = await fetch(`${apiBase}/${url.replace(/^\//, '')}`, options);
        const text = await response.text();
        let payload = {};
        try {
            payload = text ? JSON.parse(text) : {};
        } catch (error) {
            payload = { status: 'error', data: { detail: 'The server returned an invalid response.' } };
        }
        if (!response.ok) {
            const failure = { status: response.status, data: payload.data || payload };
            throw failure;
        }
        if (payload.csrf_token) setCsrf(payload.csrf_token);
        if (payload.data && payload.data.csrf_token) setCsrf(payload.data.csrf_token);
        return payload.data !== undefined ? payload.data : payload;
    }

    window.api = {
        setCsrf,
        get: (url) => request('GET', url),
        post: (url, body = {}) => request('POST', url, body),
        put: (url, body = {}) => request('PUT', url, body),
        patch: (url, body = {}) => request('PATCH', url, body),
        del: (url, body = {}) => request('DELETE', url, body),
        upload: (url, formData) => request('POST', url, formData),
        fileUrl: (url) => `${apiBase}/${url.replace(/^\//, '')}`,
        base: apiBase
    };
})();
