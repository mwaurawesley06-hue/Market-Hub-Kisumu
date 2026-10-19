/* USSD simulator — runs the menu session locally in the browser. */
(function () {
    'use strict';

    var ui = window.MPIDSUI;
    var api = window.MPIDSApi;

    var form = document.getElementById('ussd-form');
    var output = document.getElementById('ussd-output');
    var status = document.getElementById('ussd-status');
    var resetButton = document.getElementById('ussd-reset');

    var commodities = [];
    var session = null;

    function print(text) {
        if (output) output.textContent = text;
    }

    function setStatus(text, tone) {
        if (!status) return;
        status.textContent = text;
        status.setAttribute('data-tone', tone || 'open');
    }

    function mainMenu() {
        session = { step: 'main', phone: session ? session.phone : '' };
        print(
            'MPIDS Market Info Hub\n' +
            'Kisumu · latest approved prices\n\n' +
            '1. Check latest prices\n' +
            '2. Subscribe to SMS alerts\n' +
            '0. Exit\n\n' +
            'Reply with an option.'
        );
        setStatus('Main menu', 'open');
    }

    function showPrices() {
        api.getPublicPrices().then(function (result) {
            var lines = result.rows.slice(0, 6).map(function (row, index) {
                return (index + 1) + '. ' + row.commodity + ' — ' +
                    ui.formatMoney(row.currency, row.price) + '/' + row.unit +
                    ' (' + row.market + ')';
            });
            print('Latest published prices\n\n' + lines.join('\n') + '\n\n0. Main menu');
            setStatus('Price list', 'open');
            session = { step: 'price-back', phone: session ? session.phone : '' };
        }).catch(function () {
            print('Prices are unavailable right now.\n\n0. Main menu');
            session = { step: 'price-back', phone: session ? session.phone : '' };
        });
    }

    function askPhone() {
        session = { step: 'phone', phone: session ? session.phone : '' };
        print('SMS subscription\n\nEnter the phone number to verify\n(e.g. 0712345678):\n\n* Back');
        setStatus('Awaiting phone', 'open');
    }

    function listCommodities(prefix) {
        var lines = commodities.map(function (item, index) {
            return (index + 1) + '. ' + item.name;
        });
        print(prefix + '\n\n' + lines.join('\n') + '\n\n* Back  # Cancel');
        setStatus('Awaiting commodity', 'open');
    }

    function askFrequency() {
        print('SMS subscription\n\n' + session.phone + ' following ' + session.commodity + '\n\n' +
            '1. Daily alerts\n2. Weekly alerts\n\n* Back  # Cancel');
        setStatus('Awaiting frequency', 'open');
    }

    function confirmAndCreate() {
        api.createSubscription({
            phone_number: session.phone,
            commodity: session.commodity,
            frequency: session.frequency
        }).then(function (result) {
            print(
                'Subscription created\n\n' +
                'ID: ' + result.subscription_id + '\n' +
                'Commodity: ' + session.commodity + '\n' +
                'Frequency: ' + session.frequency + '\n\n' +
                'Demo verification code: ' + result.verification_code + '\n' +
                '(no real SMS was sent)\n\n' +
                'Confirm the code on the SMS alerts page.\n0. Main menu'
            );
            setStatus('Subscription created', 'done');
            session = { step: 'done', phone: result.phone_number };
        }).catch(function (error) {
            print((error.message || 'Could not create the subscription.') + '\n\n0. Main menu');
            setStatus('Error', 'error');
            session = { step: 'done', phone: session.phone };
        });
    }

    function handleInput(raw) {
        var text = String(raw || '').trim();
        var step = session ? session.step : 'main';

        if (text === '#') {
            print('Session cancelled.\nDial again to restart.');
            setStatus('Cancelled', 'error');
            session = null;
            return;
        }
        if (text === '*') {
            if (step === 'main') { print('Goodbye.'); setStatus('End', 'done'); session = null; return; }
            mainMenu();
            return;
        }
        if (text === '0') {
            if (step === 'price-back' || step === 'done' || step === 'main') { mainMenu(); return; }
        }

        if (step === 'main') {
            if (text === '1') { showPrices(); return; }
            if (text === '2') { askPhone(); return; }
            print('Unknown option.\n\n1. Check latest prices\n2. Subscribe to SMS alerts\n0. Exit');
            return;
        }
        if (step === 'price-back') { mainMenu(); return; }
        if (step === 'phone') {
            if (!/^(\+?254|0)[17]\d{8}$/.test(text.replace(/[\s-]/g, ''))) {
                print('That number looks invalid.\n\nEnter the phone number to verify\n(e.g. 0712345678):\n\n* Back');
                return;
            }
            session.phone = text;
            session.step = 'commodity';
            listCommodities('Choose a commodity for ' + text);
            return;
        }
        if (step === 'commodity') {
            var index = parseInt(text, 10) - 1;
            if (isNaN(index) || index < 0 || index >= commodities.length) {
                listCommodities('Unknown option. Choose a commodity:');
                return;
            }
            session.commodity = commodities[index].name;
            session.step = 'frequency';
            askFrequency();
            return;
        }
        if (step === 'frequency') {
            if (text === '1') session.frequency = 'daily';
            else if (text === '2') session.frequency = 'weekly';
            else { askFrequency(); return; }
            confirmAndCreate();
            return;
        }
        mainMenu();
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var phoneField = form.phone_number;
            var textField = form.text;
            if (!session && phoneField.value.trim()) {
                session = { step: 'main', phone: phoneField.value.trim() };
            }
            handleInput(textField.value);
            textField.value = '';
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            session = null;
            mainMenu();
        });
    }

    api.getCommodities().then(function (items) {
        commodities = items;
        mainMenu();
    }).catch(function () {
        print('Could not load commodities. Reload the page to try again.');
        setStatus('Unavailable', 'error');
    });
})();
