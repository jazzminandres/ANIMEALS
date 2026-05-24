<?php
// THIS FILE RETURNS ITEM DETAILS FOR THE SELLER MENU AND ORDERING SCREENS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user'])) {
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

// USE BOTH DATABASES BECAUSE ITEM DETAILS LIVE IN SELLER_DATA AND REVIEWS LIVE IN ANIMEALS.
$connSeller = db_connect(DB_NAME_SELLER_DATA);
$conn = db_connect(DB_NAME_ANIMEALS);

if ($conn === false || $connSeller === false) {
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}

require_once __DIR__ . '/schema_bootstrap.php';
// MAKE SURE SHOP REVIEWS EXIST BEFORE TRYING TO LOAD ITEM REVIEW HISTORY.
animeals_ensure_extensions($conn);

$itemID = (int) ($_GET['itemID'] ?? 0);
$shopID = (int) ($_GET['shopID'] ?? 0);

if ($itemID <= 0 || $shopID <= 0) {
    // ITEM DETAIL REQUESTS MUST INCLUDE BOTH IDS SO A STUDENT CANNOT FETCH RANDOM ITEMS.
    echo json_encode(['ok' => false, 'error' => 'params']);
    exit;
}

$stmt = db_query($connSeller,
    // LOAD ONLY AVAILABLE MENU ITEMS FROM THE REQUESTED SHOP.
    "SELECT itemID, shopID, itemName, itemDescription, itemCategory, itemPrice, itemImage
     FROM menu_items WHERE itemID = ? AND shopID = ? AND isAvailable = 1",
    [$itemID, $shopID]
);
$item = $stmt ? db_fetch_assoc($stmt) : null;
if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$revStmt = db_query($conn,
    // SHOW REVIEWS FROM COMPLETED ORDERS THAT ACTUALLY INCLUDED THIS MENU ITEM.
    "SELECT DISTINCT r.reviewID, r.rating, r.reviewText, r.createdAt, u.userNAME
     FROM shop_reviews r
     INNER JOIN order_items oi ON oi.orderID = r.orderID AND oi.itemID = ?
     INNER JOIN orders o ON o.orderID = r.orderID AND o.orderStatus = 'completed'
     LEFT JOIN user_details u ON u.userID = r.studentID
     WHERE r.shopID = ?
     ORDER BY r.createdAt DESC",
    [$itemID, $shopID]
);
$reviews = [];
if ($revStmt) {
    while ($row = mysqlsrv_fetch_array($revStmt, SQLSRV_FETCH_ASSOC)) {
        $reviews[] = [
            'rating' => (float) ($row['rating'] ?? 0),
            'reviewText' => (string) ($row['reviewText'] ?? ''),
            'userNAME' => (string) ($row['userNAME'] ?? 'Student'),
            'createdAt' => $row['createdAt'] instanceof DateTimeInterface ? $row['createdAt']->format('M j, Y') : '',
        ];
    }
}

echo json_encode([
    'ok' => true,
    'item' => [
        'itemID' => (int) $item['itemID'],
        'shopID' => (int) $item['shopID'],
        'itemName' => $item['itemName'],
        'itemDescription' => (string) ($item['itemDescription'] ?? ''),
        'itemCategory' => (string) ($item['itemCategory'] ?? ''),
        'itemPrice' => (float) $item['itemPrice'],
        'itemImage' => (string) ($item['itemImage'] ?? ''),
    ],
    'reviews' => $reviews,
]);
