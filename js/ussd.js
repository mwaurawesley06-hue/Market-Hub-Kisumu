/* USSD simulator — every step posts to the live backend's /api/ussd endpoint,
 * which keeps the conversation in the ussd_sessions table. This is the same
 * gateway protocol a handset would speak; only the phone is simulated here.
 *
 * API base comes from js/config.js (window.MPIDS_CONFIG.apiBase). */
(function () {
    'use strict';

    const apiBase = String((window.MPIDS_CONFIG && window.MPIDS_CONFIG.apiBase) || '')
        .trim()
        .replace(/\/+$/, '');

    // The gateway has no memory between prompts, so the simulator keeps the
    // sessionId the server handed out and replays it on every step.
    let sessionId = window.sessionStorage.getItem('mpids_ussd_session') || '';

    function newSessionId() {
        const random = (window.crypto && window.crypto.randomUUID)
            ? window.crypto.randomUUID()
            : Math.random().toString(36).slice(2) + Date.now().toString(36);
        return `sim-${random}`;
    }

    function storeSession(value) {
        sessionId = value;
        window.sessionStorage.setItem('mpids_ussd_session', value);
    }

    function setBusy(button, busy, busyLabel) {
        if (!button) return;
        if (busy) {
            button.dataset.label = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i><span>' + (busyLabel || 'Sending') + '</span>';
        } else {
            button.disabled = false;
            if (button.dataset.label) button.innerHTML = button.dataset.label;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('ussd-form');
        const phoneInput = document.getElementById('ussd-phone');
        const textInput = document.getElementById('ussd-text');
        const submit = document.getElementById('ussd-submit');
        const output = document.getElementById('ussd-output');
        const status = document.getElementById('ussd-status');
        const reset = document.getElementById('ussd-reset');
        if (!form || !submit || !output) {
            return;
        }
        if (!apiBase) {
            output.textContent = 'The MPIDS API base URL is not configured (js/config.js).';
            setStatus('Not configured', 'error');
            return;
        }

        if (!sessionId) {
            storeSession(newSessionId());
        }

        function setStatus(label, tone) {
            if (!status) return;
            status.textContent = label;
            status.dataset.tone = tone;
        }

        function render(response) {
            const isEnd = response.startsWith('END');
            output.textContent = response;
            setStatus(isEnd ? 'Session ended' : 'Session open', isEnd ? 'ended' : 'open');
            if (isEnd) {
                // END closes the handset's session: the next reply has to dial
                // the code again, so the server must not keep the old state.
                storeSession(newSessionId());
            }
            // CON keeps it open and the input is cleared so the next keypress
            // answers the screen that is currently on the phone.
            if (!isEnd) {
                textInput.value = '';
            }
            output.scrollTop = output.scrollHeight;
        }

        function showError(message) {
            output.textContent = message;
            setStatus('Request failed', 'error');
            window.MPIDSUI.toast(message, 'error');
        }

        async function send(text) {
            window.MPIDSUI.clearFieldErrors(form);
            setBusy(submit, true, 'Sending');
            setStatus('Sending…', 'busy');
            try {
                const response = await fetch(`${apiBase}/ussd`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'text/plain' },
                    body: JSON.stringify({
                        sessionId: sessionId,
                        phoneNumber: phoneInput ? phoneInput.value.trim() : '',
                        text: text
                    })
                });
                const body = await response.text();
                if (!response.ok) {
                    // A gateway that refuses the call still answers in USSD
                    // shape, so anything else is a transport problem.
                    showError(body.startsWith('CON') || body.startsWith('END')
                        ? body
                        : `The gateway answered ${response.status}. Please try again shortly.`);
                    return;
                }
                render(body);
            } catch (error) {
                showError('Unable to reach the USSD endpoint. Check your connection and try again.');
            } finally {
                setBusy(submit, false);
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(textInput.value.trim());
        });

        if (reset) {
            reset.addEventListener('click', function (event) {
                event.preventDefault();
                storeSession(newSessionId());
                textInput.value = '';
                window.MPIDSUI.clearFieldErrors(form);
                send('');
            });
        }

        textInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                send(textInput.value.trim());
            }
        });

        // Dial the code once so the phone shows a live menu rather than the
        // placeholder text.
        send('');
    });
})();
