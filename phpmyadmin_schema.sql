-- THIS FILE BUILDS THE MAIN ANIMEALS MYSQL DATABASE TABLES FOR PHPMYADMIN IMPORTS.
-- MYSQL / PHPMYADMIN SCHEMA FOR ANIMEALS
-- Use this file to create the three databases required by the app.

CREATE DATABASE IF NOT EXISTS `animeals` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `seller_data` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `animeals_posts` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `animeals`;

CREATE TABLE IF NOT EXISTS `user_details` (
  `userID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userNAME` VARCHAR(120) NOT NULL,
  `userPASSWORD` VARCHAR(255) NOT NULL,
  `userEMAIL` VARCHAR(180) NOT NULL,
  `userROLE` ENUM('student','seller','admin') NOT NULL DEFAULT 'student',
  `userPHONE` VARCHAR(20) DEFAULT NULL,
  `userGENDER` VARCHAR(20) DEFAULT NULL,
  `userCOLLEGE` VARCHAR(50) DEFAULT NULL,
  `userSTUDENTNUM` VARCHAR(50) DEFAULT NULL,
  `userSHOPNAME` VARCHAR(150) DEFAULT NULL,
  `userPROFILEPIC` VARCHAR(500) DEFAULT NULL,
  `userBUSINESSPERMIT` VARCHAR(500) DEFAULT NULL,
  `userVALIDID` VARCHAR(500) DEFAULT NULL,
  `userADMINDOC` VARCHAR(500) DEFAULT NULL,
  `userADDRESS` VARCHAR(255) DEFAULT NULL,
  `userCITY` VARCHAR(100) DEFAULT NULL,
  `sellerApprovalStatus` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `sellerReviewNote` VARCHAR(500) DEFAULT NULL,
  `sellerReviewedAt` DATETIME DEFAULT NULL,
  `sellerReviewedBy` INT UNSIGNED DEFAULT NULL,
  `isBanned` TINYINT(1) NOT NULL DEFAULT 0,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`userID`),
  UNIQUE KEY `ux_user_details_email` (`userEMAIL`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart` (
  `cartID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studentID` INT UNSIGNED NOT NULL,
  `itemID` INT UNSIGNED NOT NULL,
  `shopID` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `lineNote` VARCHAR(800) DEFAULT NULL,
  PRIMARY KEY (`cartID`),
  KEY `idx_cart_student` (`studentID`),
  KEY `idx_cart_shop` (`shopID`),
  KEY `idx_cart_item` (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `orderID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studentID` INT UNSIGNED NOT NULL,
  `shopID` INT UNSIGNED NOT NULL,
  `orderStatus` ENUM('pending','accepted','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `paymentMethod` VARCHAR(30) NOT NULL,
  `deliveryMethod` ENUM('pickup','delivery','takeout') NOT NULL DEFAULT 'pickup',
  `deliveryLocation` VARCHAR(255) DEFAULT NULL,
  `deliveryLAT` DECIMAL(10,8) DEFAULT NULL,
  `deliveryLNG` DECIMAL(11,8) DEFAULT NULL,
  `deliveryADDRESS` VARCHAR(255) DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `totalAmount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `orderNote` VARCHAR(500) DEFAULT NULL,
  `orderedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acceptedAt` DATETIME DEFAULT NULL,
  `completedAt` DATETIME DEFAULT NULL,
  PRIMARY KEY (`orderID`),
  KEY `idx_orders_student` (`studentID`),
  KEY `idx_orders_shop` (`shopID`),
  KEY `idx_orders_status` (`orderStatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `orderItemID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `orderID` INT UNSIGNED NOT NULL,
  `itemID` INT UNSIGNED NOT NULL,
  `itemName` VARCHAR(150) NOT NULL,
  `quantity` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `lineNote` VARCHAR(800) DEFAULT NULL,
  PRIMARY KEY (`orderItemID`),
  KEY `idx_order_items_order` (`orderID`),
  KEY `idx_order_items_item` (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_reviews` (
  `reviewID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `orderID` INT UNSIGNED NOT NULL,
  `shopID` INT UNSIGNED NOT NULL,
  `studentID` INT UNSIGNED NOT NULL,
  `rating` DECIMAL(3,1) NOT NULL,
  `reviewText` VARCHAR(800) DEFAULT NULL,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reviewID`),
  UNIQUE KEY `ux_shop_reviews_order` (`orderID`),
  KEY `idx_shop_reviews_shop` (`shopID`),
  KEY `idx_shop_reviews_student` (`studentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `signup_pending` (
  `pendingEMAIL` VARCHAR(255) NOT NULL,
  `pendingNAME` VARCHAR(255) DEFAULT NULL,
  `pendingPASS` VARCHAR(255) DEFAULT NULL,
  `pendingCODE` VARCHAR(10) DEFAULT NULL,
  `expiresAt` DATETIME DEFAULT NULL,
  `createdAt` DATETIME DEFAULT NULL,
  PRIMARY KEY (`pendingEMAIL`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `resetID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userEMAIL` VARCHAR(180) NOT NULL,
  `tokenHash` CHAR(64) NOT NULL,
  `expiresAt` DATETIME NOT NULL,
  `usedAt` DATETIME DEFAULT NULL,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`resetID`),
  UNIQUE KEY `ux_password_resets_token` (`tokenHash`),
  KEY `idx_password_resets_email` (`userEMAIL`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_audit_log` (
  `auditID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actorID` INT UNSIGNED DEFAULT NULL,
  `actorEmail` VARCHAR(180) DEFAULT NULL,
  `targetUserID` INT UNSIGNED DEFAULT NULL,
  `targetEmail` VARCHAR(180) DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `details` VARCHAR(800) DEFAULT NULL,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`auditID`),
  KEY `idx_user_audit_created` (`createdAt`),
  KEY `idx_user_audit_target` (`targetUserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

USE `seller_data`;

CREATE TABLE IF NOT EXISTS `user_details` (
  `userID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userNAME` VARCHAR(120) NOT NULL,
  `userPASSWORD` VARCHAR(255) NOT NULL,
  `userEMAIL` VARCHAR(180) NOT NULL,
  `userROLE` ENUM('student','seller','admin') NOT NULL DEFAULT 'student',
  `userPHONE` VARCHAR(20) DEFAULT NULL,
  `userGENDER` VARCHAR(20) DEFAULT NULL,
  `userCOLLEGE` VARCHAR(50) DEFAULT NULL,
  `userSTUDENTNUM` VARCHAR(50) DEFAULT NULL,
  `userSHOPNAME` VARCHAR(150) DEFAULT NULL,
  `userPROFILEPIC` VARCHAR(500) DEFAULT NULL,
  `userBUSINESSPERMIT` VARCHAR(500) DEFAULT NULL,
  `userVALIDID` VARCHAR(500) DEFAULT NULL,
  `userADMINDOC` VARCHAR(500) DEFAULT NULL,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`userID`),
  UNIQUE KEY `ux_seller_user_email` (`userEMAIL`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_shops` (
  `shopID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sellerID` INT UNSIGNED NOT NULL,
  `shopName` VARCHAR(150) NOT NULL,
  `shopDescription` VARCHAR(500) DEFAULT NULL,
  `shopLogo` VARCHAR(500) DEFAULT NULL,
  `shopStatus` ENUM('open','closed','paused') NOT NULL DEFAULT 'open',
  `shopType` VARCHAR(80) DEFAULT NULL,
  `shopLAT` DECIMAL(10,8) DEFAULT NULL,
  `shopLNG` DECIMAL(11,8) DEFAULT NULL,
  `shopADDRESS` VARCHAR(255) DEFAULT NULL,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`shopID`),
  KEY `idx_seller_shops_seller` (`sellerID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_items` (
  `itemID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shopID` INT UNSIGNED NOT NULL,
  `itemName` VARCHAR(150) NOT NULL,
  `itemDescription` VARCHAR(500) DEFAULT NULL,
  `itemCategory` VARCHAR(80) DEFAULT NULL,
  `itemPrice` DECIMAL(10,2) NOT NULL,
  `itemImage` VARCHAR(500) DEFAULT NULL,
  `itemStock` INT NOT NULL DEFAULT 0,
  `isAvailable` TINYINT(1) NOT NULL DEFAULT 1,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` DATETIME DEFAULT NULL,
  PRIMARY KEY (`itemID`),
  KEY `idx_menu_items_shop` (`shopID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `orderID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studentID` INT UNSIGNED NOT NULL,
  `shopID` INT UNSIGNED NOT NULL,
  `orderStatus` ENUM('pending','accepted','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `paymentMethod` VARCHAR(30) NOT NULL,
  `deliveryMethod` ENUM('pickup','delivery','takeout') NOT NULL DEFAULT 'pickup',
  `deliveryLocation` VARCHAR(255) DEFAULT NULL,
  `deliveryLAT` DECIMAL(10,8) DEFAULT NULL,
  `deliveryLNG` DECIMAL(11,8) DEFAULT NULL,
  `deliveryADDRESS` VARCHAR(255) DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `totalAmount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `orderNote` VARCHAR(500) DEFAULT NULL,
  `orderedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acceptedAt` DATETIME DEFAULT NULL,
  `completedAt` DATETIME DEFAULT NULL,
  PRIMARY KEY (`orderID`),
  KEY `idx_seller_orders_shop` (`shopID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `orderItemID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `orderID` INT UNSIGNED NOT NULL,
  `itemID` INT UNSIGNED NOT NULL,
  `itemName` VARCHAR(150) NOT NULL,
  `quantity` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `lineNote` VARCHAR(800) DEFAULT NULL,
  PRIMARY KEY (`orderItemID`),
  KEY `idx_seller_order_items_order` (`orderID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reviews` (
  `reviewID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `orderID` INT UNSIGNED NOT NULL,
  `studentID` INT UNSIGNED NOT NULL,
  `shopID` INT UNSIGNED NOT NULL,
  `rating` DECIMAL(2,1) NOT NULL,
  `reviewText` VARCHAR(500) DEFAULT NULL,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reviewID`),
  UNIQUE KEY `ux_reviews_order` (`orderID`),
  KEY `idx_reviews_shop` (`shopID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

USE `animeals_posts`;

CREATE TABLE IF NOT EXISTS `posts` (
  `postID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userEMAIL` VARCHAR(180) NOT NULL,
  `postCONTENT` TEXT DEFAULT NULL,
  `postIMAGE` VARCHAR(500) DEFAULT NULL,
  `postLAT` DECIMAL(10,8) DEFAULT NULL,
  `postLNG` DECIMAL(11,8) DEFAULT NULL,
  `postADDRESS` VARCHAR(255) DEFAULT NULL,
  `postLIKES` INT NOT NULL DEFAULT 0,
  `postSHARES` INT NOT NULL DEFAULT 0,
  `postDATE` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`postID`),
  KEY `idx_posts_email` (`userEMAIL`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comments` (
  `commentID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `postID` INT UNSIGNED NOT NULL,
  `userEMAIL` VARCHAR(180) NOT NULL,
  `commentCONTENT` TEXT DEFAULT NULL,
  `commentLIKES` INT NOT NULL DEFAULT 0,
  `commentDATE` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`commentID`),
  KEY `idx_comments_post` (`postID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_images` (
  `imageID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `postID` INT UNSIGNED NOT NULL,
  `imagePath` VARCHAR(500) NOT NULL,
  `displayOrder` INT UNSIGNED NOT NULL DEFAULT 0,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`imageID`),
  KEY `idx_post_images_post` (`postID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_likes` (
  `postLikeID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `postID` INT UNSIGNED NOT NULL,
  `userEMAIL` VARCHAR(180) NOT NULL,
  PRIMARY KEY (`postLikeID`),
  KEY `idx_post_likes_post` (`postID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comment_likes` (
  `commentLikeID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `commentID` INT UNSIGNED NOT NULL,
  `userEMAIL` VARCHAR(180) NOT NULL,
  PRIMARY KEY (`commentLikeID`),
  KEY `idx_comment_likes_comment` (`commentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
