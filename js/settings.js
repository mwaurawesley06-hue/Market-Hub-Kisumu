(function () {
    'use strict';

    const defaults = {
        site_name: 'MPIDS',
        site_tagline: 'Market Info Hub, Kisumu',
        theme_primary: '#0b6e4f',
        theme_secondary: '#f2b445',
        hero_image: 'img/market-hero.jpg',
        sms_image: 'img/photos/trader-phone.jpg'
    };

    const form = document.getElementById('settings-form');
    const saveButton = document.getElementById('settings-save');
    const resetButton = document.getElementById('settings-reset');
    const logoInput = document.getElementById('logo-input');
    const logoUploadButton = document.getElementById('logo-upload');
    const logoRemoveButton = document.getElementById('logo-remove');
    const logoPreview = document.getElementById('logo-preview');
    const heroSelect = document.getElementById('setting-hero-image');
    const heroPreview = document.getElementById('hero-preview');
    const smsSelect = document.getElementById('setting-sms-image');
    const smsPreview = document.getElementById('sms-preview');
    const accentSwatch = document.getElementById('theme-preview-accent');

    const fields = {
        site_name: document.getElementById('setting-site-name'),
        site_tagline: document.getElementById('setting-site-tagline'),
        theme_primary: document.getElementById('setting-theme-primary'),
        theme_secondary: document.getElementById('setting-theme-secondary')
    };
    const pickers = {
        theme_primary: document.getElementById('setting-theme-picker-primary'),
        theme_secondary: document.getElementById('setting-theme-picker-secondary')
    };

    function currentTheme() {
        return {
            primary: (fields.theme_primary.value || '').trim(),
            secondary: (fields.theme_secondary.value || '').trim()
        };
    }

    function applyPreview() {
        const theme = currentTheme();
        if (accentSwatch) accentSwatch.style.background = theme.secondary || defaults.theme_secondary;
        const previewPanel = document.getElementById('theme-preview');
        if (previewPanel) {
            previewPanel.style.setProperty('--green', theme.primary || defaults.theme_primary);
            previewPanel.style.setProperty('--green-deep', theme.primary || defaults.theme_primary);
            previewPanel.style.setProperty('--gold', theme.secondary || defaults.theme_secondary);
        }
    }

    function syncPickers() {
        Object.entries(pickers).forEach(([key, picker]) => {
            if (!picker) return;
            const value = (fields[key].value || '').trim();
            if (/^#[0-9a-fA-F]{6}$/.test(value)) picker.value = value;
        });
        applyPreview();
    }

    Object.keys(pickers).forEach(key => {
        if (!pickers[key]) return;
        pickers[key].addEventListener('input', () => {
            fields[key].value = pickers[key].value;
            applyPreview();
        });
        fields[key].addEventListener('input', syncPickers);
    });

    function updateHeroPreview() {
        if (!heroPreview || !heroSelect) return;
        const value = heroSelect.value;
        heroPreview.style.backgroundImage = `url('${value.replace(/'/g, '%27')}')`;
    }
    if (heroSelect) heroSelect.addEventListener('change', updateHeroPreview);

    function updateSmsPreview() {
        if (!smsPreview || !smsSelect) return;
        smsPreview.style.backgroundImage = `url('${smsSelect.value.replace(/'/g, '%27')}')`;
    }
    if (smsSelect) smsSelect.addEventListener('change', updateSmsPreview);

    function fillForm(values) {
        form.reset();
        window.mpidsUi.fieldErrors(form);
        Object.entries(fields).forEach(([key, field]) => {
            field.value = values[key] ?? defaults[key];
        });
        if (heroSelect) heroSelect.value = values.hero_image || defaults.hero_image;
        if (smsSelect) smsSelect.value = values.sms_image || defaults.sms_image;
        syncPickers();
        updateHeroPreview();
        updateSmsPreview();
    }

    async function loadSettings() {
        try {
            const data = await window.api.get('settings');
            fillForm(data);
            if (data.site_logo) {
                // site_logo_url is cache-busted so a re-upload shows immediately.
                const preview = data.site_logo_url || data.site_logo;
                logoPreview.innerHTML = `<img src="${window.mpidsUi.escape(preview)}" alt="Current site logo">`;
                // Only offer removal while a custom logo is actually configured.
                // Compare the raw path: the cache-busted URL never equals the default.
                logoRemoveButton.hidden = data.site_logo === 'img/logo.png';
            }
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to load settings.', 'error');
        }
    }

    async function save() {
        const payload = {
            site_name: fields.site_name.value.trim(),
            site_tagline: fields.site_tagline.value.trim(),
            theme_primary: fields.theme_primary.value.trim(),
            theme_secondary: fields.theme_secondary.value.trim(),
            hero_image: heroSelect ? heroSelect.value : '',
            sms_image: smsSelect ? smsSelect.value : ''
        };
        window.mpidsUi.setBusy(saveButton, true, 'Saving');
        try {
            const data = await window.api.put('settings', payload);
            fillForm(data);
            window.mpidsUi.toast('Settings saved. Refresh the public pages to see the new branding.', 'success');
        } catch (error) {
            window.mpidsUi.fieldErrors(form, error.data?.errors || {});
            window.mpidsUi.toast(error.data?.detail || 'Unable to save settings.', 'error');
        } finally {
            window.mpidsUi.setBusy(saveButton, false);
        }
    }

    async function restoreDefaults() {
        const confirmed = await window.mpidsConfirm({
            title: 'Restore default settings',
            message: 'This resets the site name, tagline, theme colours, and hero image to the MPIDS defaults. Your custom logo is kept.',
            confirmLabel: 'Restore defaults'
        });
        if (!confirmed) return;
        window.mpidsUi.setBusy(resetButton, true, 'Restoring');
        try {
            const data = await window.api.put('settings', defaults);
            fillForm(data);
            window.mpidsUi.toast('Default settings restored.', 'success');
        } catch (error) {
            window.mpidsUi.fieldErrors(form, error.data?.errors || {});
            window.mpidsUi.toast(error.data?.detail || 'Unable to restore defaults.', 'error');
        } finally {
            window.mpidsUi.setBusy(resetButton, false);
        }
    }

    async function uploadLogo(file) {
        if (!file) return;
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            window.mpidsUi.toast('Only JPG, PNG, or WebP images are supported.', 'error');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            window.mpidsUi.toast('The logo is larger than the 2 MB limit.', 'error');
            return;
        }
        const body = new FormData();
        body.append('logo', file);
        window.mpidsUi.setBusy(logoUploadButton, true, 'Uploading');
        try {
            const data = await window.api.upload('settings/logo', body);
            logoPreview.innerHTML = `<img src="${window.mpidsUi.escape(data.logo_url)}" alt="Current site logo">`;
            logoRemoveButton.hidden = false;
            window.mpidsUi.toast('Logo updated. Refresh any page to see it in the header.', 'success');
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to upload the logo.', 'error');
        } finally {
            window.mpidsUi.setBusy(logoUploadButton, false);
            logoInput.value = '';
        }
    }

    async function removeLogo() {
        const confirmed = await window.mpidsConfirm({
            title: 'Remove custom logo',
            message: 'The MPIDS default logo will be shown again.',
            confirmLabel: 'Remove logo'
        });
        if (!confirmed) return;
        window.mpidsUi.setBusy(logoRemoveButton, true, 'Removing');
        try {
            await window.api.del('settings/logo');
            logoPreview.innerHTML = '<img src="img/logo.png" alt="Current site logo">';
            logoRemoveButton.hidden = true;
            window.mpidsUi.toast('Custom logo removed.', 'success');
        } catch (error) {
            window.mpidsUi.toast(error.data?.detail || 'Unable to remove the logo.', 'error');
        } finally {
            window.mpidsUi.setBusy(logoRemoveButton, false);
        }
    }

    saveButton.addEventListener('click', save);
    resetButton.addEventListener('click', restoreDefaults);
    logoUploadButton.addEventListener('click', () => logoInput.click());
    logoInput.addEventListener('change', () => uploadLogo(logoInput.files[0]));
    if (logoRemoveButton) logoRemoveButton.addEventListener('click', removeLogo);

    fillForm(defaults);
    loadSettings();
})();
