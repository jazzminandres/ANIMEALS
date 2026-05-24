-- THIS FILE BUILDS THE SELLER_DATA MYSQL TABLES USED BY SELLER SHOPS, MENUS, AND DASHBOARDS.
-- SELLER_DATA SELLER DASHBOARD DATABASE DESIGN FOR MYSQL / PHPMYADMIN.
-- Run this in phpMyAdmin or the MySQL CLI before opening seller.php.

CREATE DATABASE IF NOT EXISTS `seller_data` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `seller_data`;

CREATE TABLE IF NOT EXISTS `user_details` (
  `userID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userNAME` VARCHAR(120) NOT NULL,
  `userPASSWORD` VARCHAR(255) NOT NULL,
  `userEMAIL` VARCHAR(180) NOT NULL,
  `userROLE` ENUM('student','seller','admin') NOT NULL DEFAULT 'seller',
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
  `isApproved` TINYINT(1) NOT NULL DEFAULT 0,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`shopID`),
  KEY `idx_seller_shops_seller` (`sellerID`),
  CONSTRAINT `fk_seller_shops_user`
    FOREIGN KEY (`sellerID`) REFERENCES `user_details` (`userID`)
    ON DELETE CASCADE
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
  KEY `idx_menu_items_shop` (`shopID`),
  CONSTRAINT `fk_menu_items_shop`
    FOREIGN KEY (`shopID`) REFERENCES `seller_shops` (`shopID`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `orderID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studentID` INT UNSIGNED NOT NULL,
  `shopID` INT UNSIGNED NOT NULL,
  `orderStatus` ENUM('pending','accepted','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `paymentMethod` ENUM('cash','gcash','card','maya') NOT NULL DEFAULT 'cash',
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
  KEY `idx_orders_shop_status_time` (`shopID`, `orderStatus`, `orderedAt`),
  CONSTRAINT `fk_orders_shop`
    FOREIGN KEY (`shopID`) REFERENCES `seller_shops` (`shopID`)
    ON DELETE CASCADE
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
  KEY `idx_order_items_item` (`itemID`),
  CONSTRAINT `fk_order_items_order`
    FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`)
    ON DELETE CASCADE
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
  KEY `idx_reviews_shop` (`shopID`),
  CONSTRAINT `fk_reviews_order`
    FOREIGN KEY (`orderID`) REFERENCES `orders` (`orderID`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_shop`
    FOREIGN KEY (`shopID`) REFERENCES `seller_shops` (`shopID`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
