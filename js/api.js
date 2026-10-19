/* MPIDS data layer.
 * This build is hosted on static hosting (GitHub Pages), so live PHP/MySQL
 * endpoints are not available. The layer reads the published dataset from
 * data/prices.json and keeps demo subscriptions in localStorage. */
(function () {
    'use strict';

    var DATA_URL = 'data/prices.json';
    var SUBS_KEY = 'mpids_subscriptions';
    var datasetPromise = null;

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

    function normalizePhone(value) {
        var digits = String(value || '').replace(/\D/g, '');
        if (/^0[17]\d{8}$/.test(digits)) digits = '254' + digits.slice(1);
        if (/^7\d{8}$/.test(digits) || /^1\d{8}$/.test(digits)) digits = '254' + digits;
        return digits;
    }

    function isPhone(value) {
        return /^254[17]\d{8}$/.test(normalizePhone(value));
    }

    function matchesFilters(row, filters) {
        if (!filters) return true;
        if (filters.commodity && row.commodity !== filters.commodity) return false;
        if (filters.market && row.market !== filters.market) return false;
        if (filters.from && row.observed_at < filters.from) return false;
        if (filters.to && row.observed_at > filters.to) return false;
        return true;
    }

    window.MPIDSApi = {
        getPublicPrices: function (filters) {
            return loadDataset().then(function (data) {
                var rows = (data.prices || []).slice().filter(function (row) {
                    return matchesFilters(row, filters);
                });
                rows.sort(function (a, b) {
                    if (a.observed_at === b.observed_at) return a.commodity.localeCompare(b.commodity);
                    return a.observed_at < b.observed_at ? 1 : -1;
                });
                return { rows: rows, updated_at: data.updated_at, source: data.source };
            });
        },

        getCommodities: function () {
            return loadDataset().then(function (data) { return (data.commodities || []).slice(); });
        },

        getMarkets: function () {
            return loadDataset().then(function (data) { return (data.markets || []).slice(); });
        },

        createSubscription: function (payload) {
            var phone = normalizePhone(payload && payload.phone_number);
            if (!isPhone(phone)) {
                return Promise.reject(new Error('Enter a valid Kenyan phone number, e.g. +254712345678.'));
            }
            var commodity = String((payload && payload.commodity) || '').trim();
            if (!commodity) return Promise.reject(new Error('Choose a commodity to follow.'));
            var frequency = payload && payload.frequency === 'weekly' ? 'weekly' : 'daily';
            var code = String(Math.floor(100000 + Math.random() * 900000));
            var id = 'SUB-' + Date.now().toString(36).toUpperCase();
            var list = readSubscriptions();
            list.push({
                subscription_id: id,
                phone_number: '+' + phone,
                commodity: commodity,
                frequency: frequency,
                verification_code: code,
                verified: false,
                token: 'UNSUB-' + Math.random().toString(36).slice(2, 10).toUpperCase(),
                created_at: new Date().toISOString()
            });
            writeSubscriptions(list);
            return Promise.resolve({
                subscription_id: id,
                phone_number: '+' + phone,
                verification_code: code,
                message: 'Demo build: your verification code is ' + code + ' (no real SMS was sent).'
            });
        },

        verifySubscription: function (subscriptionId, code) {
            var list = readSubscriptions();
            var entry = null;
            for (var i = 0; i < list.length; i++) {
                if (list[i].subscription_id === subscriptionId) { entry = list[i]; break; }
            }
            if (!entry) return Promise.reject(new Error('Subscription not found on this device.'));
            if (String(entry.verification_code) !== String(code).trim()) {
                return Promise.reject(new Error('That code is not correct. Check the demo code and try again.'));
            }
            entry.verified = true;
            writeSubscriptions(list);
            return Promise.resolve({ ok: true, token: entry.token });
        },

        unsubscribe: function (phone, token) {
            var target = normalizePhone(phone);
            var list = readSubscriptions();
            var kept = list.filter(function (entry) {
                var samePhone = normalizePhone(entry.phone_number) === target;
                var sameToken = String(entry.token).toLowerCase() === String(token || '').trim().toLowerCase();
                return !(samePhone && sameToken);
            });
            if (kept.length === list.length) {
                return Promise.reject(new Error('No subscription matches that phone number and token.'));
            }
            writeSubscriptions(kept);
            return Promise.resolve({ ok: true });
        }
    };
})();
