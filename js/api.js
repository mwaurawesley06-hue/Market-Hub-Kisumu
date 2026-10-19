/* MPIDS data layer.
 *
 * Live mode: when window.MPIDS_CONFIG.apiBase points at the PHP API (for
 * example the Render service), reads and SMS registrations go to the real
 * database. Row shapes match api/handlers/public-dashboard.php.
 *
 * Demo mode: with no apiBase configured (or when the API is unreachable for
 * reads), prices come from data/prices.json and subscriptions live in
 * localStorage so the GitHub Pages build works standalone. */
(function () {
    'use strict';

    var DATA_URL = 'data/prices.json';
    var SUBS_KEY = 'mpids_subscriptions';
    var datasetPromise = null;

    var apiBase = String((window.MPIDS_CONFIG && window.MPIDS_CONFIG.apiBase) || '')
        .trim()
        .replace(/\/+$/, '');

    function liveEnabled() {
        return apiBase !== '';
    }

    function live(path, options) {
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

    function loadDataset() {
        if (datasetPromise) return datasetPromise;
        datasetPromise = fetch(DATA_URL, { cache: 'no-cache' })
            .then(function (response) {
                if (!response.ok) throw new Error('Could not load published prices (' + response.status + ')');
                return response.json();
            })
            .catch(function (error) {
                datasetPromise = null;
                throw error;
            });
        return datasetPromise;
    }

    function readSubscriptions() {
        try {
            return JSON.parse(localStorage.getItem(SUBS_KEY) || '[]');
        } catch (error) {
            return [];
        }
    }

    function writeSubscriptions(list) {
        localStorage.setItem(SUBS_KEY, JSON.stringify(list));
    }

    function matchesFilters(row, filters) {
        if (!filters) return true;
        if (filters.commodity && row.commodity_id !== Number(filters.commodity)) return false;
        if (filters.market && row.market_id !== Number(filters.market)) return false;
        if (filters.date_from && row.date_observed < filters.date_from) return false;
        if (filters.date_to && row.date_observed > filters.date_to) return false;
        return true;
    }

    function staticPrices(filters) {
        return loadDataset().then(function (data) {
            var rows = (data.rows || []).filter(function (row) {
                return matchesFilters(row, filters);
            });
            return { rows: rows, updated_at: data.updated_at, live: false };
        });
    }

    function livePrices(filters) {
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
    }

    window.MPIDSApi = {
        liveEnabled: liveEnabled,

        getPublicPrices: function (filters) {
            if (!liveEnabled()) return staticPrices(filters);
            return livePrices(filters).catch(function (error) {
                console.warn('MPIDS API unreachable — falling back to the static dataset.', error);
                return staticPrices(filters);
            });
        },

        getCommodities: function () {
            if (!liveEnabled()) {
                return loadDataset().then(function (data) { return (data.commodities || []).slice(); });
            }
            return live('/public/commodities').catch(function (error) {
                console.warn('MPIDS API unreachable — using static commodities.', error);
                return loadDataset().then(function (data) { return (data.commodities || []).slice(); });
            });
        },

        getMarkets: function () {
            if (!liveEnabled()) {
                return loadDataset().then(function (data) { return (data.markets || []).slice(); });
            }
            return live('/public/markets').catch(function (error) {
                console.warn('MPIDS API unreachable — using static markets.', error);
                return loadDataset().then(function (data) { return (data.markets || []).slice(); });
            });
        },

        createSubscription: function (payload) {
            var phone = window.MPIDSUI.kenyanPhone(payload && payload.phone_number);
            if (!phone) {
                return Promise.reject(new Error('Enter a valid Kenyan phone number, e.g. +254712345678.'));
            }
            var commodity = String((payload && payload.commodity) || '').trim();
            if (!commodity) return Promise.reject(new Error('Choose a commodity to follow.'));
            var frequency = payload && payload.frequency === 'weekly' ? 'weekly' : 'daily';
            var commodityName = (payload && payload.commodity_name) || commodity;

            if (liveEnabled()) {
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
            }

            // Demo mode: simulate the OTP flow in this browser.
            var code = String(Math.floor(100000 + Math.random() * 900000));
            var id = 'SUB-' + Date.now().toString(36).toUpperCase();
            var list = readSubscriptions();
            list.push({
                subscription_id: id,
                phone_number: phone,
                commodity: commodityName,
                frequency: frequency,
                verification_code: code,
                verified: false,
                token: 'UNSUB-' + Math.random().toString(36).slice(2, 10).toUpperCase(),
                created_at: new Date().toISOString()
            });
            writeSubscriptions(list);
            return Promise.resolve({
                subscription_id: id,
                phone_number: phone,
                detail: 'Demo build: your verification code is ' + code + ' (no real SMS was sent).',
                verification_required: true,
                sandbox_otp: code,
                live: false
            });
        },

        verifySubscription: function (subscriptionId, code, phoneNumber) {
            if (liveEnabled()) {
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
            }

            var list = readSubscriptions();
            var entry = null;
            for (var i = 0; i < list.length; i++) {
                if (String(list[i].subscription_id) === String(subscriptionId)) { entry = list[i]; break; }
            }
            if (!entry) return Promise.reject(new Error('Subscription not found on this device.'));
            if (String(entry.verification_code) !== String(code).trim()) {
                return Promise.reject(new Error('That code is not correct. Check the demo code and try again.'));
            }
            entry.verified = true;
            writeSubscriptions(list);
            return Promise.resolve({ ok: true, token: entry.token, detail: 'Verified (demo).', live: false });
        },

        unsubscribe: function (phone, token) {
            var normalized = window.MPIDSUI.kenyanPhone(phone) || phone;

            if (liveEnabled()) {
                return live('/subscriptions/', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone_number: normalized, token: token })
                }).then(function () { return { ok: true, live: true }; });
            }

            var target = window.MPIDSUI.kenyanPhone(phone);
            var list = readSubscriptions();
            var kept = list.filter(function (entry) {
                var samePhone = entry.phone_number === target;
                var sameToken = String(entry.token).toLowerCase() === String(token || '').trim().toLowerCase();
                return !(samePhone && sameToken);
            });
            if (kept.length === list.length) {
                return Promise.reject(new Error('No subscription matches that phone number and token.'));
            }
            writeSubscriptions(kept);
            return Promise.resolve({ ok: true, live: false });
        }
    };
})();
