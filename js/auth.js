/* Demo session helpers for the admin shell (localStorage-backed). */
(function () {
    'use strict';

    var KEY = 'mpids_user';

    window.MPIDSAuth = {
        getUser: function () {
            try {
                return JSON.parse(localStorage.getItem(KEY) || 'null');
            } catch (error) {
                return null;
            }
        },
        setUser: function (user) {
            localStorage.setItem(KEY, JSON.stringify(user));
        },
        clearUser: function () {
            localStorage.removeItem(KEY);
        }
    };
})();
