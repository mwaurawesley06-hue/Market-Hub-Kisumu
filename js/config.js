/* MPIDS static-build configuration.
 *
 * Set apiBase to your Render (or other) PHP API root INCLUDING /api, e.g.
 *   window.MPIDS_CONFIG = { apiBase: 'https://mpids-api.onrender.com/api' };
 *
 * While apiBase is empty the site runs in demo mode: prices come from
 * data/prices.json and subscriptions are stored in this browser only.
 * With apiBase set, prices, commodities, markets and SMS registrations
 * go to the live database; the static dataset is only used as an offline
 * fallback if the API cannot be reached. */
window.MPIDS_CONFIG = {
    apiBase: 'https://mpids-api.onrender.com/api'
};
