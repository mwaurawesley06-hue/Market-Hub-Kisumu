/* SMS subscription page — demo verification flow backed by localStorage. */
(function () {
    'use strict';

    var ui = window.MPIDSUI;
    var api = window.MPIDSApi;

    var form = document.getElementById('subscription-form');
    var commoditySelect = document.getElementById('subscriber-commodity');
    var verificationPanel = document.getElementById('verification-panel');
    var verificationPhone = document.getElementById('verification-phone');
    var verificationId = document.getElementById('verification-id');
    var verificationMessage = document.getElementById('verification-message');
    var verificationCode = document.getElementById('verification-code');
    var verifyButton = document.getElementById('verify-subscription');
    var localTokenNote = document.getElementById('local-token-note');
    var unsubscribeForm = document.getElementById('unsubscribe-form');
    var submitButton = document.getElementById('subscribe-submit');

    var pending = null;

    function fillCommodities() {
        if (!commoditySelect) return;
        api.getCommodities().then(function (items) {
            commoditySelect.innerHTML = '<option value="">Select a commodity</option>' +
                items.map(function (item) {
                    return '<option value="' + ui.escapeHtml(item.name) + '">' + ui.escapeHtml(item.name) + '</option>';
                }).join('');
        }).catch(function () {
            commoditySelect.innerHTML = '<option value="">Commodities unavailable</option>';
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearFieldErrors(form);
            var phone = form.phone_number.value.trim();
            var commodity = form.commodity.value;
            var frequency = form.frequency.value;
            var valid = true;

            if (!phone) { ui.setFieldError(form, 'phone_number', 'Phone number is required.'); valid = false; }
            else if (!/^(\+?254|0)[17]\d{8}$/.test(phone.replace(/[\s-]/g, ''))) {
                ui.setFieldError(form, 'phone_number', 'Enter a valid Kenyan number, e.g. +254712345678.'); valid = false;
            }
            if (!commodity) { ui.setFieldError(form, 'commodity', 'Choose a commodity.'); valid = false; }
            if (!valid) return;

            if (submitButton) submitButton.disabled = true;
            api.createSubscription({ phone_number: phone, commodity: commodity, frequency: frequency })
                .then(function (result) {
                    pending = result;
                    if (verificationPhone) verificationPhone.value = result.phone_number;
                    if (verificationId) verificationId.value = result.subscription_id;
                    if (verificationMessage) verificationMessage.textContent = result.message;
                    if (verificationPanel) verificationPanel.hidden = false;
                    if (localTokenNote) { localTokenNote.hidden = false; localTokenNote.textContent = ''; }
                    form.reset();
                    ui.toast('Subscription created. Enter the demo verification code.', 'success');
                    if (verificationPanel) verificationPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function (error) {
                    ui.toast(error.message || 'Could not create the subscription.', 'error');
                })
                .finally(function () {
                    if (submitButton) submitButton.disabled = false;
                });
        });
    }

    if (verifyButton) {
        verifyButton.addEventListener('click', function () {
            if (!pending) {
                ui.toast('Start a new subscription first.', 'warning');
                return;
            }
            var code = verificationCode ? verificationCode.value.trim() : '';
            if (!/^\d{6}$/.test(code)) {
                ui.toast('Enter the 6-digit verification code.', 'warning');
                return;
            }
            verifyButton.disabled = true;
            api.verifySubscription(pending.subscription_id, code)
                .then(function (result) {
                    ui.toast('Phone verified. SMS alerts are active for ' + pending.commodity + '.', 'success');
                    if (localTokenNote) {
                        localTokenNote.hidden = false;
                        localTokenNote.textContent = 'Verification complete. Unsubscribe token: ' + result.token +
                            ' (demo only — stored in this browser).';
                    }
                    if (verificationCode) verificationCode.value = '';
                })
                .catch(function (error) {
                    ui.toast(error.message || 'Verification failed.', 'error');
                })
                .finally(function () {
                    verifyButton.disabled = false;
                });
        });
    }

    if (unsubscribeForm) {
        unsubscribeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearFieldErrors(unsubscribeForm);
            var phone = unsubscribeForm.phone_number.value.trim();
            var token = unsubscribeForm.token.value.trim();
            if (!phone || !token) {
                ui.setFieldError(unsubscribeForm, !phone ? 'phone_number' : 'token', 'This field is required.');
                return;
            }
            api.unsubscribe(phone, token)
                .then(function () {
                    unsubscribeForm.reset();
                    ui.toast('Subscription cancelled. You will not receive further alerts.', 'success');
                })
                .catch(function (error) {
                    ui.toast(error.message || 'Could not unsubscribe.', 'error');
                });
        });
    }

    fillCommodities();
})();
