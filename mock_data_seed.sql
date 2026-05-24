-- ONE-TIME MOCK DATA SEED FOR ANIMEALS.
-- RUN THIS ONCE IN PHPMYADMIN AFTER IMPORTING phpmyadmin_schema.sql.
-- IT REPLACES ONLY THE MOCK RECORDS THAT USE @example.test EMAILS OR ANIMEALS MOCK SHOP NAMES.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- MAKE SURE THE NEWER COLUMNS EXIST BEFORE THE MOCK ROWS USE THEM.
ALTER TABLE animeals.orders ADD COLUMN IF NOT EXISTS deliveryLAT DECIMAL(10,8) NULL;
ALTER TABLE animeals.orders ADD COLUMN IF NOT EXISTS deliveryLNG DECIMAL(11,8) NULL;
ALTER TABLE animeals.orders ADD COLUMN IF NOT EXISTS deliveryADDRESS VARCHAR(255) NULL;
ALTER TABLE animeals.user_details ADD COLUMN IF NOT EXISTS sellerApprovalStatus VARCHAR(20) NOT NULL DEFAULT 'approved';
ALTER TABLE animeals.user_details ADD COLUMN IF NOT EXISTS sellerReviewNote VARCHAR(500) NULL;
ALTER TABLE animeals.user_details ADD COLUMN IF NOT EXISTS sellerReviewedAt DATETIME NULL;
ALTER TABLE animeals.user_details ADD COLUMN IF NOT EXISTS sellerReviewedBy INT UNSIGNED NULL;
ALTER TABLE animeals.user_details ADD COLUMN IF NOT EXISTS isBanned TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE seller_data.seller_shops ADD COLUMN IF NOT EXISTS shopType VARCHAR(80) NULL;
ALTER TABLE seller_data.seller_shops ADD COLUMN IF NOT EXISTS shopLAT DECIMAL(10,8) NULL;
ALTER TABLE seller_data.seller_shops ADD COLUMN IF NOT EXISTS shopLNG DECIMAL(11,8) NULL;
ALTER TABLE seller_data.seller_shops ADD COLUMN IF NOT EXISTS shopADDRESS VARCHAR(255) NULL;
ALTER TABLE seller_data.seller_shops ADD COLUMN IF NOT EXISTS isApproved TINYINT(1) NOT NULL DEFAULT 0;

-- LET OLDER ADMIN QUERIES READ SHOPS FROM THE MAIN DATABASE WITHOUT DUPLICATING SHOP DATA.
SET @animeals_seller_shops_exists = (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = 'animeals' AND table_name = 'seller_shops'
);
SET @create_shop_view_sql = IF(
    @animeals_seller_shops_exists = 0,
    'CREATE VIEW animeals.seller_shops AS SELECT shopID, sellerID, shopName, shopDescription, shopLogo, shopStatus, shopType, shopLAT, shopLNG, shopADDRESS, isApproved, createdAt FROM seller_data.seller_shops',
    'SELECT 1'
);
PREPARE create_shop_view_stmt FROM @create_shop_view_sql;
EXECUTE create_shop_view_stmt;
DEALLOCATE PREPARE create_shop_view_stmt;

START TRANSACTION;

-- CLEAN UP THE PREVIOUS MOCK RUN SO THIS FILE CAN BE RUN AGAIN DURING TESTING.
DELETE FROM animeals.shop_reviews
WHERE studentID IN (SELECT userID FROM animeals.user_details WHERE userEMAIL LIKE '%@example.test')
   OR shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %');

DELETE FROM animeals.order_items
WHERE orderID IN (
    SELECT orderID FROM animeals.orders
    WHERE studentID IN (SELECT userID FROM animeals.user_details WHERE userEMAIL LIKE '%@example.test')
       OR shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %')
);

DELETE FROM animeals.orders
WHERE studentID IN (SELECT userID FROM animeals.user_details WHERE userEMAIL LIKE '%@example.test')
   OR shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %');

DELETE FROM animeals.cart
WHERE studentID IN (SELECT userID FROM animeals.user_details WHERE userEMAIL LIKE '%@example.test');

DELETE FROM seller_data.reviews
WHERE studentID IN (SELECT userID FROM animeals.user_details WHERE userEMAIL LIKE '%@example.test')
   OR shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %');

DELETE FROM seller_data.order_items
WHERE orderID IN (
    SELECT orderID FROM seller_data.orders
    WHERE shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %')
);

DELETE FROM seller_data.orders
WHERE shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %');

DELETE FROM seller_data.menu_items
WHERE shopID IN (SELECT shopID FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %');

DELETE FROM seller_data.seller_shops
WHERE shopName LIKE 'Animeals Mock %';

DELETE FROM seller_data.user_details
WHERE userEMAIL LIKE '%@example.test';

DELETE FROM animeals.user_audit_log
WHERE targetEmail LIKE '%@example.test'
   OR actorEmail LIKE '%@example.test'
   OR details LIKE '%mock seed%';

DELETE FROM animeals.user_details
WHERE userEMAIL LIKE '%@example.test';

-- CREATE MOCK USERS. PASSWORD FOR ALL MOCK ACCOUNTS IS: Password123!
SET @mock_password = '$2y$10$vcEGREJpgVjin6QvQ6zjNuUPAbKh1Xfp8QxzUYN7X3No9zmEQY/kS';

INSERT INTO animeals.user_details
    (userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER, userCOLLEGE, userSTUDENTNUM, userADDRESS, userCITY, sellerApprovalStatus, isBanned, createdAt)
VALUES
    ('Mika Santos', @mock_password, 'mika.student@example.test', 'student', '09170000001', 'Female', 'CCS', '2026-0001', 'Main Library, NEU Campus', 'Quezon City', 'approved', 0, DATE_SUB(NOW(), INTERVAL 18 DAY)),
    ('Noah Reyes', @mock_password, 'noah.student@example.test', 'student', '09170000002', 'Male', 'CBA', '2026-0002', 'College of Business lobby', 'Quezon City', 'approved', 0, DATE_SUB(NOW(), INTERVAL 16 DAY)),
    ('Lia Cruz', @mock_password, 'lia.student@example.test', 'student', '09170000003', 'Female', 'CAS', '2026-0003', 'Student Center', 'Quezon City', 'approved', 0, DATE_SUB(NOW(), INTERVAL 14 DAY)),
    ('Arvin Dela Cruz', @mock_password, 'arvin.student@example.test', 'student', '09170000004', 'Male', 'COE', '2026-0004', 'Engineering building entrance', 'Quezon City', 'approved', 0, DATE_SUB(NOW(), INTERVAL 12 DAY));

INSERT INTO animeals.user_details
    (userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER, userSHOPNAME, userBUSINESSPERMIT, userVALIDID, userADMINDOC, userADDRESS, userCITY, sellerApprovalStatus, sellerReviewedAt, isBanned, createdAt)
VALUES
    ('Tala Mercado', @mock_password, 'tala.seller@example.test', 'seller', '09180000001', 'Female', 'Animeals Mock Bento Bar', 'uploads/mock/business_permit_bento.pdf', 'uploads/mock/valid_id_tala.pdf', 'uploads/mock/admin_doc_bento.pdf', 'Central Avenue', 'Quezon City', 'approved', DATE_SUB(NOW(), INTERVAL 10 DAY), 0, DATE_SUB(NOW(), INTERVAL 21 DAY)),
    ('Marco Lim', @mock_password, 'marco.seller@example.test', 'seller', '09180000002', 'Male', 'Animeals Mock Sip Station', 'uploads/mock/business_permit_sip.pdf', 'uploads/mock/valid_id_marco.pdf', 'uploads/mock/admin_doc_sip.pdf', 'Commonwealth Avenue', 'Quezon City', 'approved', DATE_SUB(NOW(), INTERVAL 9 DAY), 0, DATE_SUB(NOW(), INTERVAL 19 DAY)),
    ('Rina Villanueva', @mock_password, 'rina.seller@example.test', 'seller', '09180000003', 'Female', 'Animeals Mock Sweet Corner', 'uploads/mock/business_permit_sweet.pdf', 'uploads/mock/valid_id_rina.pdf', 'uploads/mock/admin_doc_sweet.pdf', 'New Era campus gate', 'Quezon City', 'pending', NULL, 0, DATE_SUB(NOW(), INTERVAL 3 DAY));

SELECT @student_mika := userID FROM animeals.user_details WHERE userEMAIL = 'mika.student@example.test';
SELECT @student_noah := userID FROM animeals.user_details WHERE userEMAIL = 'noah.student@example.test';
SELECT @student_lia := userID FROM animeals.user_details WHERE userEMAIL = 'lia.student@example.test';
SELECT @student_arvin := userID FROM animeals.user_details WHERE userEMAIL = 'arvin.student@example.test';
SELECT @seller_tala := userID FROM animeals.user_details WHERE userEMAIL = 'tala.seller@example.test';
SELECT @seller_marco := userID FROM animeals.user_details WHERE userEMAIL = 'marco.seller@example.test';
SELECT @seller_rina := userID FROM animeals.user_details WHERE userEMAIL = 'rina.seller@example.test';

-- MIRROR SELLERS INTO SELLER_DATA USING THE SAME USER IDS SO ADMIN JOINS SHOW OWNER NAMES.
INSERT INTO seller_data.user_details
    (userID, userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER, userSHOPNAME, userPROFILEPIC, userBUSINESSPERMIT, userVALIDID, userADMINDOC, createdAt)
VALUES
    (@seller_tala, 'Tala Mercado', @mock_password, 'tala.seller@example.test', 'seller', '09180000001', 'Female', 'Animeals Mock Bento Bar', 'uploads/mock/tala.jpg', 'uploads/mock/business_permit_bento.pdf', 'uploads/mock/valid_id_tala.pdf', 'uploads/mock/admin_doc_bento.pdf', DATE_SUB(NOW(), INTERVAL 21 DAY)),
    (@seller_marco, 'Marco Lim', @mock_password, 'marco.seller@example.test', 'seller', '09180000002', 'Male', 'Animeals Mock Sip Station', 'uploads/mock/marco.jpg', 'uploads/mock/business_permit_sip.pdf', 'uploads/mock/valid_id_marco.pdf', 'uploads/mock/admin_doc_sip.pdf', DATE_SUB(NOW(), INTERVAL 19 DAY)),
    (@seller_rina, 'Rina Villanueva', @mock_password, 'rina.seller@example.test', 'seller', '09180000003', 'Female', 'Animeals Mock Sweet Corner', 'uploads/mock/rina.jpg', 'uploads/mock/business_permit_sweet.pdf', 'uploads/mock/valid_id_rina.pdf', 'uploads/mock/admin_doc_sweet.pdf', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- CREATE SHOPS WITH REALISTIC CAMPUS-AREA MAP PINS.
INSERT INTO seller_data.seller_shops
    (sellerID, shopName, shopDescription, shopLogo, shopStatus, shopType, shopLAT, shopLNG, shopADDRESS, isApproved, createdAt)
VALUES
    (@seller_tala, 'Animeals Mock Bento Bar', 'Rice meals, bento boxes, and quick lunch sets for students between classes.', 'uploads/mock/bento_bar.jpg', 'open', 'Rice Meals', 14.66751100, 121.06244800, 'Near main canteen, New Era University', 1, DATE_SUB(NOW(), INTERVAL 20 DAY)),
    (@seller_marco, 'Animeals Mock Sip Station', 'Iced coffee, fruit tea, and cold drinks for afternoon study sessions.', 'uploads/mock/sip_station.jpg', 'open', 'Drinks', 14.66813700, 121.06183500, 'Beside student lounge, New Era University', 1, DATE_SUB(NOW(), INTERVAL 18 DAY)),
    (@seller_rina, 'Animeals Mock Sweet Corner', 'Cookies, brownies, and dessert cups waiting for admin verification.', 'uploads/mock/sweet_corner.jpg', 'paused', 'Desserts', 14.66703200, 121.06321100, 'Gate 2 kiosk area, New Era University', 0, DATE_SUB(NOW(), INTERVAL 3 DAY));

SELECT @shop_bento := shopID FROM seller_data.seller_shops WHERE shopName = 'Animeals Mock Bento Bar';
SELECT @shop_sip := shopID FROM seller_data.seller_shops WHERE shopName = 'Animeals Mock Sip Station';
SELECT @shop_sweet := shopID FROM seller_data.seller_shops WHERE shopName = 'Animeals Mock Sweet Corner';

-- ADD MENU ITEMS SO STUDENT AND SELLER PAGES HAVE PRODUCTS TO SHOW.
INSERT INTO seller_data.menu_items
    (shopID, itemName, itemDescription, itemCategory, itemPrice, itemImage, itemStock, isAvailable, createdAt)
VALUES
    (@shop_bento, 'Chicken Teriyaki Bento', 'Grilled chicken, rice, corn, and cucumber side.', 'Bento', 129.00, 'uploads/mock/chicken_teriyaki_bento.jpg', 28, 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
    (@shop_bento, 'Pork Katsudon Bowl', 'Crispy pork cutlet over rice with savory egg sauce.', 'Rice Bowl', 145.00, 'uploads/mock/katsudon_bowl.jpg', 18, 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
    (@shop_bento, 'Tuna Mayo Onigiri', 'Handheld rice snack with tuna mayo filling.', 'Snack', 55.00, 'uploads/mock/tuna_onigiri.jpg', 35, 1, DATE_SUB(NOW(), INTERVAL 13 DAY)),
    (@shop_sip, 'Iced Spanish Latte', 'Creamy espresso drink with sweet milk over ice.', 'Coffee', 95.00, 'uploads/mock/spanish_latte.jpg', 40, 1, DATE_SUB(NOW(), INTERVAL 12 DAY)),
    (@shop_sip, 'Mango Fruit Tea', 'Bright mango tea with popping pearls.', 'Fruit Tea', 85.00, 'uploads/mock/mango_fruit_tea.jpg', 34, 1, DATE_SUB(NOW(), INTERVAL 12 DAY)),
    (@shop_sip, 'Matcha Cloud Latte', 'Matcha milk drink topped with light cream.', 'Coffee', 105.00, 'uploads/mock/matcha_cloud_latte.jpg', 24, 1, DATE_SUB(NOW(), INTERVAL 11 DAY)),
    (@shop_sweet, 'Classic Brownie Box', 'Four fudgy brownies packed for sharing.', 'Dessert', 120.00, 'uploads/mock/brownie_box.jpg', 12, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (@shop_sweet, 'Mango Graham Cup', 'Cold mango graham dessert in a single cup.', 'Dessert', 75.00, 'uploads/mock/mango_graham_cup.jpg', 20, 1, DATE_SUB(NOW(), INTERVAL 2 DAY));

SELECT @item_teriyaki := itemID FROM seller_data.menu_items WHERE shopID = @shop_bento AND itemName = 'Chicken Teriyaki Bento';
SELECT @item_katsudon := itemID FROM seller_data.menu_items WHERE shopID = @shop_bento AND itemName = 'Pork Katsudon Bowl';
SELECT @item_onigiri := itemID FROM seller_data.menu_items WHERE shopID = @shop_bento AND itemName = 'Tuna Mayo Onigiri';
SELECT @item_spanish := itemID FROM seller_data.menu_items WHERE shopID = @shop_sip AND itemName = 'Iced Spanish Latte';
SELECT @item_mango := itemID FROM seller_data.menu_items WHERE shopID = @shop_sip AND itemName = 'Mango Fruit Tea';
SELECT @item_matcha := itemID FROM seller_data.menu_items WHERE shopID = @shop_sip AND itemName = 'Matcha Cloud Latte';

-- ADD ORDERS ACROSS THE LAST SEVEN DAYS SO ADMIN REVENUE, STATUS, AND COMMISSION CHARTS HAVE DATA.
INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_mika, @shop_bento, 'completed', 'Cash', 'delivery', 'Main Library entrance', 14.66845100, 121.06209500, 'Main Library entrance, New Era University', 313.00, 313.00, 'Please include extra sauce.', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 5 MINUTE, DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 38 MINUTE);
SET @order_1 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_1, @item_teriyaki, 'Chicken Teriyaki Bento', 2, 129.00, 'Extra sauce'),
    (@order_1, @item_onigiri, 'Tuna Mayo Onigiri', 1, 55.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_noah, @shop_sip, 'completed', 'GCash', 'delivery', 'CBA lobby', 14.66790800, 121.06149600, 'College of Business lobby, New Era University', 180.00, 180.00, 'Less ice if possible.', DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 2 HOUR, DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 125 MINUTE, DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 163 MINUTE);
SET @order_2 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_2, @item_spanish, 'Iced Spanish Latte', 1, 95.00, 'Less ice'),
    (@order_2, @item_mango, 'Mango Fruit Tea', 1, 85.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_lia, @shop_bento, 'ready', 'Cash', 'pickup', 'Student Center', 14.66772100, 121.06289100, 'Student Center, New Era University', 290.00, 290.00, 'Pickup after class.', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 8 MINUTE, NULL);
SET @order_3 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_3, @item_katsudon, 'Pork Katsudon Bowl', 2, 145.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_arvin, @shop_sip, 'completed', 'Maya', 'delivery', 'Engineering building', 14.66721100, 121.06197700, 'Engineering building entrance, New Era University', 295.00, 295.00, 'Call when nearby.', DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 3 HOUR, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 190 MINUTE, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 225 MINUTE);
SET @order_4 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_4, @item_matcha, 'Matcha Cloud Latte', 2, 105.00, NULL),
    (@order_4, @item_mango, 'Mango Fruit Tea', 1, 85.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_mika, @shop_bento, 'completed', 'Cash', 'delivery', 'Main gate waiting shed', 14.66691200, 121.06250500, 'Main gate waiting shed, New Era University', 200.00, 200.00, '', DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY) + INTERVAL 7 MINUTE, DATE_SUB(NOW(), INTERVAL 4 DAY) + INTERVAL 34 MINUTE);
SET @order_5 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_5, @item_teriyaki, 'Chicken Teriyaki Bento', 1, 129.00, NULL),
    (@order_5, @item_onigiri, 'Tuna Mayo Onigiri', 1, 55.00, NULL),
    (@order_5, @item_onigiri, 'Tuna Mayo Onigiri', 1, 16.00, 'Mock discount adjustment');

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_noah, @shop_sip, 'cancelled', 'Cash', 'pickup', 'CBA lobby', 14.66790800, 121.06149600, 'College of Business lobby, New Era University', 95.00, 95.00, 'Cancelled test order.', DATE_SUB(NOW(), INTERVAL 4 DAY) + INTERVAL 90 MINUTE, NULL, NULL);
SET @order_6 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_6, @item_spanish, 'Iced Spanish Latte', 1, 95.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_lia, @shop_bento, 'completed', 'GCash', 'delivery', 'Student Center', 14.66772100, 121.06289100, 'Student Center, New Era University', 274.00, 274.00, 'No cucumber please.', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 6 MINUTE, DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 41 MINUTE);
SET @order_7 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_7, @item_katsudon, 'Pork Katsudon Bowl', 1, 145.00, NULL),
    (@order_7, @item_teriyaki, 'Chicken Teriyaki Bento', 1, 129.00, 'No cucumber');

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_arvin, @shop_sip, 'completed', 'Cash', 'delivery', 'Engineering building', 14.66721100, 121.06197700, 'Engineering building entrance, New Era University', 275.00, 275.00, '', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 12 MINUTE, DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 50 MINUTE);
SET @order_8 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_8, @item_mango, 'Mango Fruit Tea', 2, 85.00, NULL),
    (@order_8, @item_matcha, 'Matcha Cloud Latte', 1, 105.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_mika, @shop_bento, 'accepted', 'Cash', 'delivery', 'Main Library entrance', 14.66845100, 121.06209500, 'Main Library entrance, New Era University', 184.00, 184.00, 'Currently accepted order.', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 4 MINUTE, NULL);
SET @order_9 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_9, @item_teriyaki, 'Chicken Teriyaki Bento', 1, 129.00, NULL),
    (@order_9, @item_onigiri, 'Tuna Mayo Onigiri', 1, 55.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_noah, @shop_sip, 'preparing', 'GCash', 'delivery', 'CBA lobby', 14.66790800, 121.06149600, 'College of Business lobby, New Era University', 190.00, 190.00, 'Preparing test order.', DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 3 HOUR, DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 187 MINUTE, NULL);
SET @order_10 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_10, @item_spanish, 'Iced Spanish Latte', 2, 95.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_lia, @shop_bento, 'pending', 'Cash', 'delivery', 'Student Center', 14.66772100, 121.06289100, 'Student Center, New Era University', 145.00, 145.00, 'Pending test order.', NOW() - INTERVAL 6 HOUR, NULL, NULL);
SET @order_11 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_11, @item_katsudon, 'Pork Katsudon Bowl', 1, 145.00, NULL);

INSERT INTO animeals.orders
    (studentID, shopID, orderStatus, paymentMethod, deliveryMethod, deliveryLocation, deliveryLAT, deliveryLNG, deliveryADDRESS, subtotal, totalAmount, orderNote, orderedAt, acceptedAt, completedAt)
VALUES
    (@student_arvin, @shop_sip, 'ready', 'Maya', 'pickup', 'Engineering building', 14.66721100, 121.06197700, 'Engineering building entrance, New Era University', 200.00, 200.00, 'Ready test order.', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 110 MINUTE, NULL);
SET @order_12 = LAST_INSERT_ID();
INSERT INTO animeals.order_items (orderID, itemID, itemName, quantity, price, lineNote) VALUES
    (@order_12, @item_mango, 'Mango Fruit Tea', 1, 85.00, NULL),
    (@order_12, @item_spanish, 'Iced Spanish Latte', 1, 95.00, NULL),
    (@order_12, @item_onigiri, 'Tuna Mayo Onigiri', 1, 20.00, 'Mock bundle add-on');

-- ADD REVIEWS ON COMPLETED ORDERS SO SELLER RATINGS AND REVIEW PANELS HAVE CONTENT.
INSERT INTO animeals.shop_reviews
    (orderID, shopID, studentID, rating, reviewText, createdAt)
VALUES
    (@order_1, @shop_bento, @student_mika, 5.0, 'Good portions and still warm when it arrived.', DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 1 HOUR),
    (@order_2, @shop_sip, @student_noah, 4.5, 'Drinks were packed well and tasted great.', DATE_SUB(NOW(), INTERVAL 6 DAY) + INTERVAL 4 HOUR),
    (@order_4, @shop_sip, @student_arvin, 5.0, 'The matcha latte was smooth and not too sweet.', DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 5 HOUR),
    (@order_7, @shop_bento, @student_lia, 4.0, 'Fast delivery and they followed my note.', DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 2 HOUR),
    (@order_8, @shop_sip, @student_arvin, 4.5, 'Good afternoon drink set.', DATE_SUB(NOW(), INTERVAL 2 DAY) + INTERVAL 2 HOUR);

INSERT INTO animeals.user_audit_log
    (actorID, actorEmail, targetUserID, targetEmail, action, details, createdAt)
VALUES
    (NULL, 'mock.seed@example.test', @seller_tala, 'tala.seller@example.test', 'seller_approved', 'Created by mock seed so the admin audit table has a seller approval record.', DATE_SUB(NOW(), INTERVAL 10 DAY)),
    (NULL, 'mock.seed@example.test', @seller_marco, 'marco.seller@example.test', 'seller_approved', 'Created by mock seed so the admin audit table has another seller approval record.', DATE_SUB(NOW(), INTERVAL 9 DAY)),
    (NULL, 'mock.seed@example.test', @seller_rina, 'rina.seller@example.test', 'seller_pending', 'Created by mock seed to show a pending seller review.', DATE_SUB(NOW(), INTERVAL 3 DAY));

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

-- QUICK CHECKS AFTER RUNNING:
-- SELECT COUNT(*) AS mock_users FROM animeals.user_details WHERE userEMAIL LIKE '%@example.test';
-- SELECT COUNT(*) AS mock_shops FROM seller_data.seller_shops WHERE shopName LIKE 'Animeals Mock %';
-- SELECT orderStatus, COUNT(*) AS orders FROM animeals.orders GROUP BY orderStatus;
-- SELECT SUM(totalAmount) AS revenue_used_by_admin FROM animeals.orders WHERE orderStatus IN ('completed', 'ready');
