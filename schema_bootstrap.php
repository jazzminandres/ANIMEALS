<?php
// THIS FILE MAKES SURE THE MYSQL TABLES AND COLUMNS NEEDED BY NEW FEATURES EXIST.
/**
 * IDEMPOTENT DDL FOR OPTIONAL COLUMNS/TABLES USED BY CHECKOUT, REVIEWS,
 * SELLER APPROVALS, PASSWORD RESETS, AUDITS, AND MULTI-IMAGE POSTS.
 * SAFE TO CALL ON EVERY REQUEST BECAUSE EACH FUNCTION HAS A STATIC GUARD.
 */

function animeals_ensure_extensions(mysqli $conn): void
{
    // ONLY RUN THIS DATABASE PATCHER ONCE PER REQUEST EVEN IF MANY PAGES INCLUDE IT.
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // CREATE SHOP REVIEWS SO STUDENTS CAN RATE COMPLETED SHOP ORDERS.
    $conn->query("CREATE TABLE IF NOT EXISTS shop_reviews (
        reviewID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        orderID INT UNSIGNED NOT NULL,
        shopID INT UNSIGNED NOT NULL,
        studentID INT UNSIGNED NOT NULL,
        rating DECIMAL(3,1) NOT NULL,
        reviewText VARCHAR(800) NULL,
        createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_shop_reviews_order (orderID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ADD OPTIONAL CART NOTES FOR CUSTOM INSTRUCTIONS ON EACH LINE ITEM.
    $conn->query("ALTER TABLE cart ADD COLUMN lineNote VARCHAR(800) NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // COPY LINE NOTES INTO ORDER ITEMS AFTER CHECKOUT.
    $conn->query("ALTER TABLE order_items ADD COLUMN lineNote VARCHAR(800) NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // SAVE THE STUDENT DELIVERY PIN ON EACH ORDER SO SELLERS CAN SEE WHERE TO BRING IT.
    $conn->query("ALTER TABLE orders ADD COLUMN deliveryLAT DECIMAL(10,8) NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // SAVE THE STUDENT DELIVERY LONGITUDE ON EACH ORDER.
    $conn->query("ALTER TABLE orders ADD COLUMN deliveryLNG DECIMAL(11,8) NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // SAVE A READABLE DELIVERY ADDRESS OR MAP LABEL WITH THE ORDER.
    $conn->query("ALTER TABLE orders ADD COLUMN deliveryADDRESS VARCHAR(255) NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // TRACK WHETHER SELLER ACCOUNTS ARE PENDING, APPROVED, OR REJECTED BY ADMIN.
    $conn->query("ALTER TABLE user_details ADD COLUMN sellerApprovalStatus VARCHAR(20) NOT NULL DEFAULT 'approved'");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // STORE THE ADMIN NOTE THAT EXPLAINS A SELLER REVIEW DECISION.
    $conn->query("ALTER TABLE user_details ADD COLUMN sellerReviewNote VARCHAR(500) NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // REMEMBER WHEN THE SELLER APPLICATION WAS REVIEWED.
    $conn->query("ALTER TABLE user_details ADD COLUMN sellerReviewedAt DATETIME NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // REMEMBER WHICH ADMIN MADE THE SELLER REVIEW DECISION.
    $conn->query("ALTER TABLE user_details ADD COLUMN sellerReviewedBy INT UNSIGNED NULL");
    if ($conn->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // KEEP NON-SELLER ACCOUNTS FROM GETTING STUCK IN A SELLER-ONLY APPROVAL STATE.
    $conn->query("UPDATE user_details SET sellerApprovalStatus = 'approved' WHERE userROLE <> 'seller' AND (sellerApprovalStatus IS NULL OR sellerApprovalStatus = '')");

    // STORE ONE-TIME RESET TOKENS WITHOUT SAVING THE RAW TOKEN VALUE.
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        resetID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        userEMAIL VARCHAR(180) NOT NULL,
        tokenHash CHAR(64) NOT NULL,
        expiresAt DATETIME NOT NULL,
        usedAt DATETIME NULL,
        createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_password_resets_token (tokenHash),
        KEY idx_password_resets_email (userEMAIL)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // STORE ADMIN ACTION HISTORY SO ACCOUNT CHANGES CAN BE REVIEWED LATER.
    $conn->query("CREATE TABLE IF NOT EXISTS user_audit_log (
        auditID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actorID INT UNSIGNED NULL,
        actorEmail VARCHAR(180) NULL,
        targetUserID INT UNSIGNED NULL,
        targetEmail VARCHAR(180) NULL,
        action VARCHAR(80) NOT NULL,
        details VARCHAR(800) NULL,
        createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_audit_created (createdAt),
        KEY idx_user_audit_target (targetUserID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seller_data_ensure_shop_type(mysqli $connSeller): void
{
    // ONLY PATCH SELLER_DATA ONCE PER REQUEST.
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // LET SELLER SHOPS SAVE A CATEGORY OR SHOP TYPE WITHOUT BREAKING OLDER ROWS.
    $connSeller->query("ALTER TABLE seller_shops ADD COLUMN shopType VARCHAR(80) NULL");
    if ($connSeller->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // SAVE THE SELLER SHOP MAP PIN SO STUDENTS CAN TRACK ROUTES TO THE STORE.
    $connSeller->query("ALTER TABLE seller_shops ADD COLUMN shopLAT DECIMAL(10,8) NULL");
    if ($connSeller->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // SAVE THE SELLER SHOP LONGITUDE FOR MAP ROUTES.
    $connSeller->query("ALTER TABLE seller_shops ADD COLUMN shopLNG DECIMAL(11,8) NULL");
    if ($connSeller->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // SAVE A READABLE SHOP ADDRESS OR LOCATION NOTE.
    $connSeller->query("ALTER TABLE seller_shops ADD COLUMN shopADDRESS VARCHAR(255) NULL");
    if ($connSeller->errno !== 1060) {
        // IGNORE DUPLICATE COLUMN ERRORS BECAUSE HOSTED DATABASES MAY ALREADY BE UPDATED.
    }

    // MIRROR DELIVERY LOCATION COLUMNS FOR ANY LEGACY SELLER_DATA ORDERS TABLE.
    $connSeller->query("ALTER TABLE orders ADD COLUMN deliveryLAT DECIMAL(10,8) NULL");
    if ($connSeller->errno !== 1060 && $connSeller->errno !== 1146) {
    }
    $connSeller->query("ALTER TABLE orders ADD COLUMN deliveryLNG DECIMAL(11,8) NULL");
    if ($connSeller->errno !== 1060 && $connSeller->errno !== 1146) {
    }
    $connSeller->query("ALTER TABLE orders ADD COLUMN deliveryADDRESS VARCHAR(255) NULL");
    if ($connSeller->errno !== 1060 && $connSeller->errno !== 1146) {
    }
}

function animeals_posts_ensure_extensions(mysqli $connPosts): void
{
    // ONLY PATCH THE POSTS DATABASE ONCE PER REQUEST.
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // SAVE LATITUDE WHEN A POST IS PINNED ON THE MAP.
    $connPosts->query("ALTER TABLE posts ADD COLUMN postLAT DECIMAL(10,8) NULL");
    if ($connPosts->errno !== 1060) {
    }

    // SAVE LONGITUDE WHEN A POST IS PINNED ON THE MAP.
    $connPosts->query("ALTER TABLE posts ADD COLUMN postLNG DECIMAL(11,8) NULL");
    if ($connPosts->errno !== 1060) {
    }

    // SAVE THE HUMAN-READABLE ADDRESS SHOWN ON POSTS WITH LOCATIONS.
    $connPosts->query("ALTER TABLE posts ADD COLUMN postADDRESS VARCHAR(255) NULL");
    if ($connPosts->errno !== 1060) {
    }

    // COUNT POST SHARES WITHOUT NEEDING A SEPARATE TABLE.
    $connPosts->query("ALTER TABLE posts ADD COLUMN postSHARES INT NOT NULL DEFAULT 0");
    if ($connPosts->errno !== 1060) {
    }

    // STORE EXTRA POST IMAGES SO A SINGLE POST CAN HAVE A SCROLLABLE PHOTO GALLERY.
    $connPosts->query("CREATE TABLE IF NOT EXISTS post_images (
        imageID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        postID INT UNSIGNED NOT NULL,
        imagePath VARCHAR(500) NOT NULL,
        displayOrder INT UNSIGNED NOT NULL DEFAULT 0,
        createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_post_images_post (postID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
