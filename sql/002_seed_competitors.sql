-- Competitors and compliance checks as at 24 Oct 2026 (robots.txt and published terms read by hand).
-- Laneway Promos and Boost are not included: website addresses not confirmed.
INSERT INTO competitors
 (code, name, base_url, platform, priority, robots_checked_at, robots_allows_products, crawl_delay_seconds,
  terms_url, terms_checked_at, terms_prohibit_scraping, notes)
VALUES
 ('BC', 'Brandconnect',       'https://brandconnect.com.au',     'bigcommerce', 'high',   '2026-10-24', 1, 10, NULL, '2026-10-24', 0, 'No terms page linked. robots: AI bots crawl-delay 10. Uses markup on total project cost (per conversation with BC).'),
 ('PL', 'Payless Promotions', 'https://paylesspromotions.com.au', 'magento',    'high',   '2026-10-24', 1, 10, 'https://paylesspromotions.com.au/policies', '2026-10-24', 0, 'robots blocks filters/search only.'),
 ('S2', 'Sense2',             'https://sense2.com',              'custom',      'high',   '2026-10-24', 1, 10, 'https://sense2.com/terms', '2026-10-24', 0, 'Terms of sale only; bars reproduction of content (internal comparison only, never republish). Has /api/mcp for agents.'),
 ('FP', 'Fresh Promotions',   'https://www.freshpromotions.com.au', 'magento',  'medium', '2026-10-24', 1, 10, 'https://www.freshpromotions.com.au/terms-and-conditions', '2026-10-24', 0, 'Terms allow viewing for internal business use; no reproduction.'),
 ('PP', 'Promo Punks',        'https://promopunks.com.au',       'shopify',     'medium', '2026-10-24', 1, 10, 'https://promopunks.com.au/policies/terms-of-service', '2026-10-24', 0, 'Shopify; products.json open.'),
 ('ME', 'Mercha',             'https://www.mercha.com.au',       'shopify',     'medium', '2026-10-24', 1, 10, 'https://www.mercha.com.au/policies/terms-of-service', '2026-10-24', 0, 'Shopify; products.json open.'),
 ('GT', 'Good Things',        'https://goodthings.com.au',       'wordpress',   'medium', '2026-10-24', 1, 10, 'https://goodthings.com.au/terms/', '2026-10-24', 0, 'robots explicitly allows AI agents.'),
 ('PS', 'PromoShop',          'https://www.promoshop.com.au',    'wordpress',   'low',    '2026-10-24', 1, 10, NULL, '2026-10-24', 0, 'Only a privacy statement found.'),
 ('BS', 'Brand Surge',        'https://brandsurge.com.au',       'bigcommerce', 'low',    '2026-10-24', 1, 10, 'https://brandsurge.com.au/terms-conditions/', '2026-10-24', 0, 'robots: AI bots crawl-delay 10.');
