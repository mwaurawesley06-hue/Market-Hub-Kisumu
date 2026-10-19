USE mpids;

INSERT INTO commodities (name, category, unit_of_measure, min_price, max_price, is_active) VALUES
    ('Maize', 'Cereals', 'KG', 20.00, 100.00, 1),
    ('Wheat', 'Cereals', 'KG', 30.00, 150.00, 1),
    ('Rice', 'Cereals', 'KG', 40.00, 200.00, 1),
    ('Beans', 'Pulses', 'KG', 50.00, 300.00, 1),
    ('Tomatoes', 'Vegetables', 'KG', 10.00, 120.00, 1),
    ('Onions', 'Vegetables', 'KG', 15.00, 150.00, 1)
ON DUPLICATE KEY UPDATE category = VALUES(category), unit_of_measure = VALUES(unit_of_measure), min_price = VALUES(min_price), max_price = VALUES(max_price), is_active = 1;

INSERT INTO markets (name, county, sub_county, is_active) VALUES
    ('Kisumu Central Market', 'Kisumu', NULL, 1),
    ('Kibuye Market', 'Kisumu', NULL, 1),
    ('Mamboleo Market', 'Kisumu', NULL, 1),
    ('Ahero Market', 'Kisumu', NULL, 1)
ON DUPLICATE KEY UPDATE county = VALUES(county), is_active = 1;

SET @maize_id = (SELECT id FROM commodities WHERE name = 'Maize' LIMIT 1);
SET @market_id = (SELECT id FROM markets WHERE name = 'Kisumu Central Market' LIMIT 1);

INSERT INTO price_records (commodity_id, market_id, date_observed, price, source, status, captured_by, approved_by)
SELECT @maize_id, @market_id, CURDATE() - INTERVAL 1 DAY, 58.00, 'Kisumu Central Market', 'published', id, id
FROM users
WHERE email = 'admin@mpids.local'
LIMIT 1
ON DUPLICATE KEY UPDATE price = VALUES(price), status = 'published', approved_by = VALUES(approved_by);
