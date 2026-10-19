(function () {
    'use strict';

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
            window.publicUi.toast(message, 'error');
        }

        async function send(text) {
            window.publicUi.fieldErrors(form);
            window.publicUi.setBusy(submit, true, 'Sending');
            setStatus('Sending…', 'busy');
            try {
                const response = await fetch(`${window.api.base}/ussd`, {
                    method: 'POST',
                    credentials: 'include',
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
                        : `The gateway answered ${response.status}. Check that Apache and MySQL are running.`);
                    return;
                }
                render(body);
            } catch (error) {
                showError('Unable to reach the USSD endpoint. Check that Apache and MySQL are running.');
            } finally {
                window.publicUi.setBusy(submit, false);
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
                window.publicUi.fieldErrors(form);
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
