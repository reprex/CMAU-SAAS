-- Competitor price checking module (MySQL 8 / MariaDB 10.6+)
-- Quantity-break pricing comparison between CMAU and competitor websites.

CREATE TABLE IF NOT EXISTS competitors (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                      VARCHAR(10)  NOT NULL UNIQUE,          -- short ID used in reports, e.g. BC, PL
    name                      VARCHAR(120) NOT NULL,
    base_url                  VARCHAR(255) NOT NULL,                 -- https://example.com.au
    platform                  ENUM('shopify','bigcommerce','magento','wordpress','custom','unknown') NOT NULL DEFAULT 'unknown',
    priority                  ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    -- Compliance: we only collect automatically when both checks pass.
    robots_checked_at         DATETIME NULL,
    robots_allows_products    TINYINT(1) NULL,                       -- NULL = not checked
    crawl_delay_seconds       SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    terms_url                 VARCHAR(255) NULL,
    terms_checked_at          DATETIME NULL,
    terms_prohibit_scraping   TINYINT(1) NULL,                       -- NULL = not checked
    -- Optional per-site extraction hints for the generic page adapter.
    price_regex               VARCHAR(500) NULL,                     -- must capture qty and price, see README
    active                    TINYINT(1) NOT NULL DEFAULT 1,
    notes                     TEXT NULL,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS competitor_product_matches (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    competitor_id       INT UNSIGNED NOT NULL,
    cmau_appaid         VARCHAR(20)  NOT NULL,                       -- our product (Shopify handle)
    supplier_code       VARCHAR(40)  NULL,                           -- supplier product code, kept private
    competitor_url      VARCHAR(500) NOT NULL,
    competitor_title    VARCHAR(255) NULL,
    match_method        ENUM('supplier_code','name_image','manual') NOT NULL DEFAULT 'manual',
    decoration_label    VARCHAR(120) NULL,                           -- decoration being compared, e.g. "1 col pad print"
    active              TINYINT(1) NOT NULL DEFAULT 1,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match (competitor_id, cmau_appaid, competitor_url(191)),
    KEY idx_appaid (cmau_appaid),
    CONSTRAINT fk_match_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(id)
);

-- One row per competitor price observation at one quantity.
CREATE TABLE IF NOT EXISTS competitor_price_snapshots (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id            INT UNSIGNED NOT NULL,
    captured_at         DATETIME NOT NULL,
    qty                 INT UNSIGNED NOT NULL,
    unit_price_ex_gst   DECIMAL(10,4) NULL,
    setup_ex_gst        DECIMAL(10,2) NULL,
    freight_ex_gst      DECIMAL(10,2) NULL,
    project_total_ex_gst DECIMAL(12,2) NULL,                         -- qty*unit + setup + freight, for markup-curve analysis
    prices_included_gst TINYINT(1) NOT NULL DEFAULT 0,               -- as displayed on the site (we store ex-GST)
    source              ENUM('shopify_json','page_jsonld','page_regex','manual') NOT NULL,
    source_note         VARCHAR(255) NULL,
    KEY idx_match_time (match_id, captured_at),
    CONSTRAINT fk_snap_match FOREIGN KEY (match_id) REFERENCES competitor_product_matches(id)
);

-- Our own prices at the same quantities, from DecorationJSON, captured at the same time.
CREATE TABLE IF NOT EXISTS cmau_price_snapshots (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cmau_appaid         VARCHAR(20) NOT NULL,
    captured_at         DATETIME NOT NULL,
    qty                 INT UNSIGNED NOT NULL,
    decoration_label    VARCHAR(120) NULL,
    unit_price_ex_gst   DECIMAL(10,4) NOT NULL,
    setup_ex_gst        DECIMAL(10,2) NOT NULL DEFAULT 0,
    project_total_ex_gst DECIMAL(12,2) NOT NULL,
    KEY idx_appaid_time (cmau_appaid, captured_at)
);

-- Request log, used to enforce crawl delays and to audit what we fetched.
CREATE TABLE IF NOT EXISTS competitor_fetch_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    competitor_id   INT UNSIGNED NOT NULL,
    url             VARCHAR(500) NOT NULL,
    http_status     SMALLINT NULL,
    fetched_at      DATETIME NOT NULL,
    outcome         VARCHAR(40) NOT NULL,                            -- ok, blocked_robots, blocked_terms, http_error, no_price
    KEY idx_comp_time (competitor_id, fetched_at)
);

-- Supplier cost and suggested-markup reference (e.g. Trends distributor pricing).
-- CONFIDENTIAL: distributor-only data entered by staff from supplier portals.
-- Never scraped automatically, never published to the website or shown to clients.
CREATE TABLE IF NOT EXISTS supplier_cost_snapshots (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier                VARCHAR(60)  NOT NULL,                   -- e.g. Trends
    supplier_code           VARCHAR(40)  NOT NULL,                   -- e.g. 112025
    cmau_appaid             VARCHAR(20)  NULL,
    captured_at             DATETIME     NOT NULL,
    qty                     INT UNSIGNED NOT NULL,
    unbranded_cost          DECIMAL(10,4) NULL,
    decorated_unit_cost     DECIMAL(10,4) NOT NULL,                  -- "Buy (unit)" incl. per-unit decoration
    decoration_label        VARCHAR(120) NULL,
    setup_cost              DECIMAL(10,2) NULL,
    suggested_markup_pct    DECIMAL(5,2) NULL,                       -- supplier's suggested mark-up at this qty
    suggested_setup_markup_pct DECIMAL(5,2) NULL,
    suggested_sell_unit     DECIMAL(10,4) NULL,                      -- supplier's calculated sell price
    freight_cost            DECIMAL(10,2) NULL,
    KEY idx_supplier_code (supplier, supplier_code, captured_at)
);

-- Our current pricing per product, maintained by the SaaS Shopify publisher (same DecorationJSON it publishes).
CREATE TABLE IF NOT EXISTS cmau_product_pricing (
    appaid          VARCHAR(20) PRIMARY KEY,
    title           VARCHAR(255) NULL,
    supplier_code   VARCHAR(40)  NULL,
    decoration_json TEXT NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- SaaS users. Competitor pricing and supplier costs are visible to role 'admin' only.
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,                           -- password_hash() / PASSWORD_DEFAULT
    role            ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
