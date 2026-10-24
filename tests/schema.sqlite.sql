-- SQLite equivalent of sql/001_competitor_prices.sql, used only by tests.
CREATE TABLE competitors (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
  base_url TEXT NOT NULL, platform TEXT NOT NULL DEFAULT 'unknown', priority TEXT NOT NULL DEFAULT 'medium',
  robots_checked_at TEXT, robots_allows_products INTEGER, crawl_delay_seconds INTEGER NOT NULL DEFAULT 10,
  terms_url TEXT, terms_checked_at TEXT, terms_prohibit_scraping INTEGER, price_regex TEXT,
  active INTEGER NOT NULL DEFAULT 1, notes TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE competitor_product_matches (id INTEGER PRIMARY KEY AUTOINCREMENT, competitor_id INTEGER NOT NULL,
  cmau_appaid TEXT NOT NULL, supplier_code TEXT, competitor_url TEXT NOT NULL, competitor_title TEXT,
  match_method TEXT NOT NULL DEFAULT 'manual', decoration_label TEXT, active INTEGER NOT NULL DEFAULT 1, notes TEXT, created_at TEXT);
CREATE TABLE competitor_price_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, match_id INTEGER NOT NULL,
  captured_at TEXT NOT NULL, qty INTEGER NOT NULL, unit_price_ex_gst REAL, setup_ex_gst REAL, freight_ex_gst REAL,
  project_total_ex_gst REAL, prices_included_gst INTEGER NOT NULL DEFAULT 0, source TEXT NOT NULL, source_note TEXT);
CREATE TABLE cmau_price_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, cmau_appaid TEXT NOT NULL, captured_at TEXT NOT NULL,
  qty INTEGER NOT NULL, decoration_label TEXT, unit_price_ex_gst REAL NOT NULL, setup_ex_gst REAL NOT NULL DEFAULT 0,
  project_total_ex_gst REAL NOT NULL);
CREATE TABLE competitor_fetch_log (id INTEGER PRIMARY KEY AUTOINCREMENT, competitor_id INTEGER NOT NULL, url TEXT NOT NULL,
  http_status INTEGER, fetched_at TEXT NOT NULL, outcome TEXT NOT NULL);
CREATE TABLE supplier_cost_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier TEXT NOT NULL, supplier_code TEXT NOT NULL,
  cmau_appaid TEXT, captured_at TEXT NOT NULL, qty INTEGER NOT NULL, unbranded_cost REAL, decorated_unit_cost REAL NOT NULL,
  decoration_label TEXT, setup_cost REAL, suggested_markup_pct REAL, suggested_setup_markup_pct REAL,
  suggested_sell_unit REAL, freight_cost REAL);
