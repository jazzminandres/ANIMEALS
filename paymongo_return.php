<?php
// THIS FILE HANDLES THE PAYMENT RETURN PAGE AFTER PAYMONGO SENDS THE USER BACK.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit();
}

$pending = $_SESSION['paymongo_pending'] ?? null;
$token = $_GET['token'] ?? '';
if (!$pending || !is_array($pending) || $pending['returnToken'] !== $token) {
    // REJECT RETURNS THAT DO NOT MATCH THE TOKEN SAVED WHEN CHECKOUT STARTED.
    header('Location: payment.php');
    exit();
}

$serverName = "SatanaelLG\\MSSQLSERVER01";
$connOptions = ["Database" => "ANIMEALS", "Uid" => "", "PWD" => ""];
$conn = mysqlsrv_connect($serverName, $connOptions);
if ($conn === false) {
    die(print_r(mysqlsrv_errors(), true));
}
animeals_ensure_extensions($conn);

// LOAD THE STUDENT AND THEIR CURRENT CART AFTER PAYMONGO SENDS THEM BACK.
$stmt = mysqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$_SESSION['email']]);
$user = mysqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$studentID = (int)($user['userID'] ?? 0);

$cartStmt = mysqlsrv_query($conn,
    "SELECT c.*, mi.itemName, mi.itemPrice, mi.itemImage, ss.shopName, ss.shopID
     FROM CART c
     JOIN SELLER_DATA.dbo.MENU_ITEMS mi ON mi.itemID = c.itemID
     JOIN SELLER_DATA.dbo.SELLER_SHOPS ss ON ss.shopID = c.shopID
     WHERE c.studentID = ?",
    [$studentID]
);
$cartItems = [];
while ($row = mysqlsrv_fetch_array($cartStmt, SQLSRV_FETCH_ASSOC)) {
    $cartItems[] = $row;
}

if (empty($cartItems)) {
    unset($_SESSION['paymongo_pending']);
    header('Location: student.php');
    exit();
}

$orderNote = trim((string) ($pending['orderNote'] ?? ''));
$paymentMethod = in_array($pending['paymentMethod'] ?? '', ['GCash', 'Card'], true) ? $pending['paymentMethod'] : 'GCash';
$deliveryLat = isset($pending['deliveryLat']) && is_numeric($pending['deliveryLat']) ? (float) $pending['deliveryLat'] : null;
$deliveryLng = isset($pending['deliveryLng']) && is_numeric($pending['deliveryLng']) ? (float) $pending['deliveryLng'] : null;
$deliveryAddress = trim((string) ($pending['deliveryAddress'] ?? ''));

$byShop = [];
foreach ($cartItems as $item) {
    // GROUP CART ITEMS BY SHOP SO EACH SELLER GETS THEIR OWN ORDER.
    $byShop[(int)$item['shopID']][] = $item;
}

foreach ($byShop as $shopID => $items) {
    // CREATE THE ORDER HEADER, THEN COPY EACH CART LINE INTO ORDER_ITEMS.
    $shopTotal = array_sum(array_map(fn($i) => (float)$i['itemPrice'] * (int)$i['quantity'], $items));

    mysqlsrv_query($conn,
        "INSERT INTO ORDERS (studentID, shopID, totalAmount, paymentMethod, orderNote, deliveryLAT, deliveryLNG, deliveryADDRESS, orderStatus)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
        [$studentID, $shopID, $shopTotal, $paymentMethod, $orderNote, $deliveryLat, $deliveryLng, $deliveryAddress !== '' ? $deliveryAddress : null]
    );

    $newOrderStmt = mysqlsrv_query($conn,
        "SELECT TOP 1 orderID FROM ORDERS WHERE studentID = ? AND shopID = ? ORDER BY orderedAt DESC",
        [$studentID, $shopID]
    );
    $newOrder = mysqlsrv_fetch_array($newOrderStmt, SQLSRV_FETCH_ASSOC);
    $orderID  = (int)($newOrder['orderID'] ?? 0);

    foreach ($items as $item) {
        $lineNote = trim((string) ($item['lineNote'] ?? ''));
        mysqlsrv_query(
            $conn,
            "INSERT INTO ORDER_ITEMS (orderID, itemID, itemName, quantity, price, lineNote)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $orderID,
                $item['itemID'],
                $item['itemName'],
                $item['quantity'],
                $item['itemPrice'],
                $lineNote !== '' ? $lineNote : null,
            ]
        );
    }
}

// CLEAR THE CART ONLY AFTER ALL PAYMONGO ORDERS HAVE BEEN WRITTEN.
mysqlsrv_query($conn, "DELETE FROM CART WHERE studentID = ?", [$studentID]);
unset($_SESSION['paymongo_pending']);
$_SESSION['checkout_success'] = true;
$_SESSION['checkout_message'] = 'Your PayMongo payment was completed successfully. Thank you!';
header('Location: student.php');
exit();
