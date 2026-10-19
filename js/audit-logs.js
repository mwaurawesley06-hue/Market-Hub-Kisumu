(function () {
    'use strict';
    if (window.MPIDSAdmin) {
        window.MPIDSAdmin.renderNotice('Audit trail', 'Audit logging needs the live backend. This static build does not record admin actions.', 'fa-shield-halved');
    }
})();
