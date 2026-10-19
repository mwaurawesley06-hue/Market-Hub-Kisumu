CREATE TABLE IF NOT EXISTS users (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    phone_number VARCHAR(15) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,
    date_joined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_users_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commodities (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    category VARCHAR(50) NOT NULL,
    unit_of_measure VARCHAR(20) NOT NULL,
    min_price DECIMAL(10,2) NOT NULL,
    max_price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    image_path VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_commodity_min_price CHECK (min_price > 0),
    CONSTRAINT chk_commodity_max_ge_min CHECK (max_price >= min_price),
    CONSTRAINT chk_commodity_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS markets (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    county VARCHAR(50) NOT NULL,
    sub_county VARCHAR(50) NULL,
    latitude DECIMAL(9,6) NULL,
    longitude DECIMAL(9,6) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_market_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS price_records (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commodity_id BIGINT NOT NULL,
    market_id BIGINT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    date_observed DATE NOT NULL,
    source VARCHAR(120) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    captured_by BIGINT NOT NULL,
    approved_by BIGINT NULL,
    rejection_reason TEXT NULL,
    override_justification TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_price_commodity_market_date (commodity_id, market_id, date_observed),
    KEY idx_price_records_lookup (commodity_id, market_id, date_observed),
    KEY idx_price_records_status (status),
    KEY idx_price_records_date (date_observed),
    KEY idx_price_records_market_date (market_id, date_observed),
    CONSTRAINT fk_pr_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_market FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_captured_by FOREIGN KEY (captured_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_price_positive CHECK (price > 0),
    CONSTRAINT chk_price_status CHECK (status IN ('pending', 'published', 'rejected'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sms_subscriptions (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL,
    commodity_id BIGINT NOT NULL,
    frequency VARCHAR(10) NOT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_code_hash VARCHAR(255) NULL,
    verification_expires DATETIME NULL,
    unsubscribe_token VARCHAR(64) NULL,
    verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_verification_sent_at DATETIME NULL,
    verified_at DATETIME NULL,
    last_alert_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscription_phone_commodity_frequency (phone_number, commodity_id, frequency),
    KEY idx_subs_commodity (commodity_id),
    KEY idx_subs_phone (phone_number),
    KEY idx_subs_alerts (commodity_id, is_verified),
    CONSTRAINT fk_subs_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE RESTRICT,
    CONSTRAINT chk_subscription_frequency CHECK (frequency IN ('daily', 'weekly')),
    CONSTRAINT chk_subscription_verified CHECK (is_verified IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ussd_sessions (
    session_id VARCHAR(64) NOT NULL PRIMARY KEY,
    phone_number VARCHAR(20) NULL,
    menu VARCHAR(32) NOT NULL DEFAULT 'main',
    state_data TEXT NULL,
    last_input VARCHAR(64) NULL,
    last_response TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ussd_sessions_active (is_active, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    bucket CHAR(64) PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NULL,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id BIGINT NULL,
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_action (action),
    KEY idx_audit_table (table_name),
    KEY idx_audit_time (timestamp),
    KEY idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_audit_old_value CHECK (old_value IS NULL OR JSON_VALID(old_value)),
    CONSTRAINT chk_audit_new_value CHECK (new_value IS NULL OR JSON_VALID(new_value))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by BIGINT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO site_settings (setting_key, setting_value)
    SELECT 'site_name', 'MPIDS'
    WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'site_name');

INSERT INTO site_settings (setting_key, setting_value)
    SELECT 'site_tagline', 'Market Info Hub, Kisumu'
    WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'site_tagline');

INSERT INTO site_settings (setting_key, setting_value)
    SELECT 'theme_primary', '#0b6e4f'
    WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'theme_primary');

INSERT INTO site_settings (setting_key, setting_value)
    SELECT 'theme_secondary', '#f2b445'
    WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'theme_secondary');

INSERT INTO site_settings (setting_key, setting_value)
    SELECT 'hero_image', 'img/market-hero.jpg'
    WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'hero_image');

