(function () {
    'use strict';

    /**
     * Applies administrator-configured branding (site name, tagline, logo,
     * hero image, theme colours) to the public pages. Reads the public
     * settings endpoint once; on any failure the shipped markup is untouched.
     */
    function setText(selector, value) {
        document.querySelectorAll(selector).forEach(element => {
            element.textContent = value;
        });
    }

    function apply(data) {
        if (!data) return;
        if (data.site_name) {
            setText('[data-brand-name]', data.site_name);
            document.title = (document.title || '').replace(/^.*?(?= · )/u, data.site_name);
        }
        if (data.site_tagline) setText('[data-brand-tagline]', data.site_tagline);
        // site_logo_url is cache-busted; site_logo stays the raw path.
        const logo = data.site_logo_url || data.site_logo;
        if (logo) {
            document.querySelectorAll('img[data-brand-logo]').forEach(img => {
                img.src = logo;
            });
        }
        if (data.hero_image) {
            document.querySelectorAll('[data-brand-hero]').forEach(element => {
                if (element.tagName === 'IMG') element.src = data.hero_image;
                else element.style.backgroundImage = `url('${String(data.hero_image).replace(/'/g, '%27')}')`;
            });
        }
        if (data.sms_image) {
            document.querySelectorAll('[data-brand-sms]').forEach(element => {
                element.src = data.sms_image;
            });
        }
        if (data.theme_primary || data.theme_secondary) {
            const style = document.createElement('style');
            style.textContent = `:root{${data.theme_primary ? `--green:${data.theme_primary};--brand-accent:${data.theme_primary};` : ''}${data.theme_secondary ? `--gold:${data.theme_secondary};` : ''}}`;
            document.head.appendChild(style);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (!window.api) return;
        window.api.get('settings').then(apply).catch(() => { /* keep shipped defaults */ });
        showDashboardLinkWhenSignedIn();
    });

    /**
     * The public header offers "Admin sign in". For an admin who is already
     * signed in that is a dead end: it looks like they were logged out and they
     * re-type credentials to get back to the workspace. Swap it for a link that
     * returns to the dashboard instead.
     */
    async function showDashboardLinkWhenSignedIn() {
        let session = null;
        try {
            session = await window.api.get('auth/me');
        } catch (error) {
            return; // anonymous visitor: keep the sign-in button
        }
        if (!session || !session.user) return;
        document.querySelectorAll('.public-header-actions a[href="login"]').forEach(link => {
            link.setAttribute('href', 'dashboard');
            link.setAttribute('aria-label', 'Back to the admin dashboard');
            link.innerHTML = '<i class="fa-solid fa-grid-2"></i><span>Dashboard</span>';
        });
    }
})();
