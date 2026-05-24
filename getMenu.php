<?php
// THIS FILE RETURNS MENU ITEMS FOR A SELECTED SHOP SO THE FRONT END CAN LOAD FOOD OPTIONS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['items' => []]);
    exit();
}

// CONNECT TO SELLER_DATA BECAUSE MENU ITEMS LIVE WITH THE SELLER SHOP TABLES.
$conn = db_connect(DB_NAME_SELLER_DATA);

$shopID = (int)($_GET['shopID'] ?? 0);

// ONLY RETURN AVAILABLE ITEMS SO STUDENTS CANNOT ORDER HIDDEN MENU ENTRIES.
$stmt = db_query($conn,
    "SELECT * FROM menu_items WHERE shopID = ? AND isAvailable = 1 ORDER BY itemName",
    [$shopID]
);

$rows = $stmt ? db_fetch_all($stmt) : [];
// SHAPE DATABASE ROWS INTO THE SMALL JSON OBJECT THE SHOP MODAL EXPECTS.
$items = array_map(function($row) {
    return [
        'itemID'          => $row['itemID'],
        'itemName'        => $row['itemName'],
        'itemPrice'       => $row['itemPrice'],
        'itemImage'       => $row['itemImage'],
        'itemCategory'    => $row['itemCategory'],
        'itemDescription' => $row['itemDescription'] ?? '',
        'shopID'          => $row['shopID'],
    ];
}, $rows);

echo json_encode(['items' => $items]);
