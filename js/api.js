/* MPIDS data layer — live API only.
 *
 * Every read and write goes to the PHP/MySQL backend configured in
 * js/config.js (window.MPIDS_CONFIG.apiBase), for example the Render
 * service. There is no demo dataset and no browser-local subscription
 * store: when the API cannot be reached the caller gets a clear error,
 * never fabricated records. Row shapes match the backend handlers in
 * api/handlers/. */
(function () {
    'use strict';

    var apiBase = String((window.MPIDS_CONFIG && window.MPIDS_CONFIG.apiBase) || '')
        .trim()
        .replace(/\/+$/, '');

    function liveEnabled() {
        return apiBase !== '';
    }

    function live(path, options) {
        if (!liveEnabled()) {
            return Promise.reject(new Error('The MPIDS API base URL is not configured. Set window.MPIDS_CONFIG.apiBase in js/config.js.'));
        }
        return fetch(apiBase + path, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var error = new Error(body.detail || ('Request failed (' + response.status + ')'));
                    error.status = response.status;
                    error.body = body;
                    throw error;
                }
                return body;
            });
        });
    }

    window.MPIDSApi = {
        liveEnabled: liveEnabled,

        getPublicPrices: function (filters) {
            var params = new URLSearchParams();
            if (filters) {
                if (filters.commodity) params.set('commodity', filters.commodity);
                if (filters.market) params.set('market', filters.market);
                if (filters.date_from) params.set('date_from', filters.date_from);
                if (filters.date_to) params.set('date_to', filters.date_to);
            }
            var query = params.toString();
            return live('/public/dashboard/' + (query ? '?' + query : '')).then(function (data) {
                data.live = true;
                return data;
            });
        },

        getCommodities: function () {
            return live('/public/commodities');
        },

        getMarkets: function () {
            return live('/public/markets');
        },

        createSubscription: function (payload) {
            var phone = window.MPIDSUI.kenyanPhone(payload && payload.phone_number);
            if (!phone) {
                return Promise.reject(new Error('Enter a valid Kenyan phone number, e.g. +254712345678.'));
            }
            var commodity = String((payload && payload.commodity) || '').trim();
            if (!commodity) return Promise.reject(new Error('Choose a commodity to follow.'));
            var frequency = payload && payload.frequency === 'weekly' ? 'weekly' : 'daily';

            return live('/subscriptions/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone_number: phone, commodity: commodity, frequency: frequency })
            }).then(function (result) {
                return {
                    subscription_id: result.subscription_id,
                    phone_number: phone,
                    detail: result.detail,
                    verification_required: result.verification_required !== false,
                    sandbox_otp: result.sandbox_otp || null,
                    live: true
                };
            });
        },

        verifySubscription: function (subscriptionId, code, phoneNumber) {
            return live('/subscriptions/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    phone_number: phoneNumber || '',
                    code: code,
                    subscription_id: subscriptionId
                })
            }).then(function (result) {
                return {
                    ok: true,
                    detail: result.detail,
                    token: result.sandbox_unsubscribe_token || null,
                    live: true
                };
            });
        },

        unsubscribe: function (phone, token) {
            var normalized = window.MPIDSUI.kenyanPhone(phone) || phone;
            return live('/subscriptions/', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone_number: normalized, token: token })
            }).then(function () { return { ok: true, live: true }; });
        }
    };
})();
