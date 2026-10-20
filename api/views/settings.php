<section class="page-heading">
    <div>
        <div class="eyebrow">Configuration</div>
        <h1>Settings</h1>
        <p>Control the name, tagline, theme colours, and logo that every MPIDS page presents. Changes are stored in the database and apply site-wide after you save.</p>
    </div>
    <div class="heading-actions">
        <button class="button button-secondary" type="button" id="settings-reset"><i class="fa-solid fa-rotate-left"></i><span>Restore defaults</span></button>
        <button class="button button-primary" type="button" id="settings-save"><i class="fa-solid fa-floppy-disk"></i><span>Save changes</span></button>
    </div>
</section>

<section class="content-grid settings-grid">
    <div class="panel">
        <div class="panel-header"><div><h2>Site identity</h2><p>Name and tagline shown in headers, footers, and the browser title.</p></div></div>
        <div class="panel-body">
            <form id="settings-form" novalidate>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="setting-site-name">Site name <span>*</span></label>
                        <input id="setting-site-name" name="site_name" maxlength="60" required>
                        <span class="field-error" data-error-for="site_name"></span>
                    </div>
                    <div class="form-field">
                        <label for="setting-site-tagline">Tagline</label>
                        <input id="setting-site-tagline" name="site_tagline" maxlength="90" placeholder="Market Info Hub, Kisumu">
                        <span class="field-error" data-error-for="site_tagline"></span>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header"><div><h2>Brand logo</h2><p>A square image works best. Replaces the MPIDS mark in every header.</p></div></div>
        <div class="panel-body">
            <div class="settings-logo-row">
                <div class="settings-logo-preview" id="logo-preview"><img src="<?= htmlspecialchars((string) ($branding['site_logo_url'] ?? 'img/logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Current site logo"></div>
                <div class="settings-logo-actions">
                    <input type="file" id="logo-input" accept="image/jpeg,image/png,image/webp" hidden>
                    <button class="button button-secondary" type="button" id="logo-upload"><i class="fa-solid fa-upload"></i><span>Upload logo</span></button>
                    <button class="button button-danger" type="button" id="logo-remove" hidden><i class="fa-solid fa-trash"></i><span>Remove</span></button>
                    <p class="form-hint">JPG, PNG, or WebP · up to 2 MB. Saved immediately with its own confirmation.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header"><div><h2>Theme colours</h2><p>Primary drives buttons and the sidebar; secondary drives accents and highlights.</p></div></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="form-field">
                    <label for="setting-theme-primary">Primary colour <span>*</span></label>
                    <div class="color-field">
                        <input type="color" id="setting-theme-picker-primary" value="#0b6e4f" aria-hidden="true" tabindex="-1">
                        <input id="setting-theme-primary" name="theme_primary" value="#0b6e4f" maxlength="9" required>
                    </div>
                    <span class="field-error" data-error-for="theme_primary"></span>
                </div>
                <div class="form-field">
                    <label for="setting-theme-secondary">Secondary colour <span>*</span></label>
                    <div class="color-field">
                        <input type="color" id="setting-theme-picker-secondary" value="#f2b445" aria-hidden="true" tabindex="-1">
                        <input id="setting-theme-secondary" name="theme_secondary" value="#f2b445" maxlength="9" required>
                    </div>
                    <span class="field-error" data-error-for="theme_secondary"></span>
                </div>
            </div>
            <p class="form-hint">Use a 6-digit hex value such as <code>#0b6e4f</code>. The live preview below updates as you type.</p>
            <div class="settings-theme-preview" id="theme-preview" aria-hidden="true">
                <span class="button button-primary">Primary button</span>
                <span class="badge badge-soft-active"><i class="fa-solid fa-circle-check"></i>Published</span>
                <span class="settings-theme-swatch" id="theme-preview-accent"></span>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header"><div><h2>Public hero image</h2><p>Shown at the top of the public price dashboard.</p></div></div>
        <div class="panel-body">
            <div class="form-field">
                <label for="setting-hero-image">Hero image path</label>
                <select id="setting-hero-image" name="hero_image">
                    <option value="img/market-hero.jpg">Market traders (default)</option>
                    <option value="img/photos/busy-market.jpg">Busy African market</option>
                    <option value="img/photos/produce-stall.jpg">Fresh produce stall</option>
                    <option value="img/photos/farmer-harvest.jpg">Farmer harvesting vegetables</option>
                    <option value="img/photos/lake-market-kisumu.jpg">Kisumu lake market</option>
                    <option value="img/photos/roadside-market.jpg">Roadside Kenyan market</option>
                    <option value="img/photos/farmer-field.jpg">Farmer in the field</option>
                    <option value="img/photos/beans-farm.jpg">Beans farm</option>
                    <option value="img/photos/fisherman-catch.jpg">Lake Victoria fisherman</option>
                </select>
                <span class="field-error" data-error-for="hero_image"></span>
            </div>
            <div class="settings-hero-preview" id="hero-preview" aria-hidden="true"></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header"><div><h2>SMS alerts page image</h2><p>Shown beside the SMS alerts heading on the public subscriptions page.</p></div></div>
        <div class="panel-body">
            <div class="form-field">
                <label for="setting-sms-image">SMS page image</label>
                <select id="setting-sms-image" name="sms_image">
                    <option value="img/photos/trader-phone.jpg">Trader using a phone (default)</option>
                    <option value="img/photos/lake-market-kisumu.jpg">Kisumu lake market</option>
                    <option value="img/photos/roadside-market.jpg">Roadside Kenyan market</option>
                    <option value="img/photos/busy-market.jpg">Busy African market</option>
                    <option value="img/photos/produce-stall.jpg">Fresh produce stall</option>
                    <option value="img/photos/farmer-field.jpg">Farmer in the field</option>
                    <option value="img/photos/beans-farm.jpg">Beans farm</option>
                    <option value="img/photos/fisherman-catch.jpg">Lake Victoria fisherman</option>
                    <option value="img/market-hero.jpg">Market traders</option>
                </select>
                <span class="field-error" data-error-for="sms_image"></span>
            </div>
            <div class="settings-hero-preview" id="sms-preview" aria-hidden="true"></div>
        </div>
    </div>
</section>
