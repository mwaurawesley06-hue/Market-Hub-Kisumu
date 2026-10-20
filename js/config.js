/* MPIDS static-build configuration.
 *
 * apiBase must point at the PHP API root INCLUDING /api, e.g.:
 *   window.MPIDS_CONFIG = { apiBase: 'https://mpids-api.onrender.com/api' };
 *
 * The static site is the public frontend only: prices, commodities, markets
 * and SMS registrations are read from and written to the live MySQL database
 * behind that API. If the API is unreachable the pages show an error state —
 * there is no offline demo dataset. */
window.MPIDS_CONFIG = {
    apiBase: 'https://mpids-api.onrender.com/api'
};
