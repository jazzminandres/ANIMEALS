<?php
// THIS FILE RUNS THE SELLER DASHBOARD, MENU MANAGEMENT, ORDER STATUS UPDATES, AND SALES VIEWS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'], $_SESSION['email'])) {
    header("Location: index.php");
    exit();
}

$conn = db_connect(DB_NAME_SELLER_DATA);
$connAnimeals = db_connect(DB_NAME_ANIMEALS);

if ($conn === false) {
    die("Database connection failed. Please make sure the SELLER_DATA database exists.");
}

require_once __DIR__ . '/schema_bootstrap.php';
// MAKE SURE SELLER SHOP TYPES AND ADMIN APPROVAL FIELDS EXIST BEFORE LOADING THE DASHBOARD.
seller_data_ensure_shop_type($conn);
if ($connAnimeals) {
    animeals_ensure_extensions($connAnimeals);
}

$approvalStmt = db_query($connAnimeals, "SELECT sellerApprovalStatus FROM user_details WHERE userEMAIL = ? AND userROLE = 'seller' LIMIT 1", [$_SESSION['email']]);
$approvalUser = $approvalStmt ? db_fetch_assoc($approvalStmt) : null;
if ($approvalUser && strtolower(trim((string) ($approvalUser['sellerApprovalStatus'] ?? 'pending'))) !== 'approved') {
    // BLOCK SELLERS FROM THE DASHBOARD UNTIL AN ADMIN APPROVES THEIR DOCUMENTS.
    header('Location: seller_pending.php');
    exit();
}

function ensureSellerDataUser(mysqli $connSeller, mysqli $connAnimeals, string $email): void
{
    // MIRROR THE SELLER ACCOUNT INTO SELLER_DATA IF IT EXISTS ONLY IN THE MAIN DATABASE.
    $sellerStmt = db_query($connSeller, "SELECT userID FROM user_details WHERE userEMAIL = ?", [$email]);
    if ($sellerStmt && db_fetch_assoc($sellerStmt)) {
        return;
    }

    $animealsStmt = db_query($connAnimeals, "SELECT * FROM user_details WHERE userEMAIL = ?", [$email]);
    $animealsUser = $animealsStmt ? db_fetch_assoc($animealsStmt) : null;
    if (!$animealsUser || strtolower(trim((string) ($animealsUser['userROLE'] ?? ''))) !== 'seller') {
        return;
    }

    db_query(
        $connSeller,
        "INSERT INTO user_details (
            userID, userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER,
            userCOLLEGE, userSTUDENTNUM, userSHOPNAME, userPROFILEPIC, userBUSINESSPERMIT,
            userVALIDID, userADMINDOC
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            userNAME = VALUES(userNAME),
            userPASSWORD = VALUES(userPASSWORD),
            userROLE = VALUES(userROLE),
            userPHONE = VALUES(userPHONE),
            userGENDER = VALUES(userGENDER),
            userCOLLEGE = VALUES(userCOLLEGE),
            userSTUDENTNUM = VALUES(userSTUDENTNUM),
            userSHOPNAME = VALUES(userSHOPNAME),
            userPROFILEPIC = VALUES(userPROFILEPIC),
            userBUSINESSPERMIT = VALUES(userBUSINESSPERMIT),
            userVALIDID = VALUES(userVALIDID),
            userADMINDOC = VALUES(userADMINDOC)",
        [
            (int) ($animealsUser['userID'] ?? 0),
            $animealsUser['userNAME'] ?? '',
            $animealsUser['userPASSWORD'] ?? '',
            $animealsUser['userEMAIL'] ?? '',
            $animealsUser['userROLE'] ?? 'seller',
            $animealsUser['userPHONE'] ?? '',
            $animealsUser['userGENDER'] ?? '',
            $animealsUser['userCOLLEGE'] ?? '',
            $animealsUser['userSTUDENTNUM'] ?? '',
            $animealsUser['userSHOPNAME'] ?? '',
            $animealsUser['userPROFILEPIC'] ?? '',
            $animealsUser['userBUSINESSPERMIT'] ?? '',
            $animealsUser['userVALIDID'] ?? '',
            $animealsUser['userADMINDOC'] ?? '',
        ]
    );
}

ensureSellerDataUser($conn, $connAnimeals, (string) ($_SESSION['email'] ?? ''));

$stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$_SESSION['email']]);
$user = $stmt ? db_fetch_assoc($stmt) : null;

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if (($user['userROLE'] ?? '') !== 'seller') {
    header("Location: " . (($user['userROLE'] ?? '') === 'admin' ? 'admin.php' : 'student.php'));
    exit();
}

function h($value) {
    // ESCAPE VALUES BEFORE PRINTING THEM INTO HTML.
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    // FORMAT PESO AMOUNTS CONSISTENTLY ACROSS SELLER CARDS AND TABLES.
    return '&#8369;' . number_format((float) $value, 2);
}

function fetchAllRows($stmt) {
    // RETURN AN EMPTY ARRAY WHEN A QUERY FAILS SO THE PAGE CAN STILL RENDER.
    return $stmt ? db_fetch_all($stmt) : [];
}

function uploadSellerImage($fieldName, $prefix) {
    // SAVE SELLER-UPLOADED MENU OR SHOP IMAGES AND RETURN THE RELATIVE FILE PATH.
    if (empty($_FILES[$fieldName]['name']) || !is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
        return '';
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return '';
    }

    $uploadDir = 'uploads/seller/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = $prefix . '_' . uniqid('', true) . '.' . $ext;
    $target = $uploadDir . $filename;

    return move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target) ? $target : '';
}

$sellerID = (int) $user['userID'];
$shopStmt = db_query($conn, "SELECT * FROM seller_shops WHERE sellerID = ? LIMIT 1", [$sellerID]);
$shop = $shopStmt ? db_fetch_assoc($shopStmt) : null;

if (!$shop) {
    // CREATE A DEFAULT SHOP RECORD THE FIRST TIME AN APPROVED SELLER OPENS THE DASHBOARD.
    db_query(
        $conn,
        "INSERT INTO seller_shops (sellerID, shopName, shopLogo) VALUES (?, ?, ?)",
        [$sellerID, $user['userSHOPNAME'] ?: (($user['userNAME'] ?? 'Seller') . "'s Shop"), $user['userPROFILEPIC'] ?? '']
    );
    $shopStmt = db_query($conn, "SELECT * FROM seller_shops WHERE sellerID = ? LIMIT 1", [$sellerID]);
    $shop = $shopStmt ? db_fetch_assoc($shopStmt) : null;
}

$shopID = (int) ($shop['shopID'] ?? 0);



$flash = '';
$flashType = 'success';

if (isset($_GET['saved'])) {
    $savedLabels = [
        'item' => 'Menu item saved.',
        'profile' => 'Profile saved.',
        'password' => 'Password updated.',
        'store' => 'Store info saved.',
    ];
    $flash = $savedLabels[$_GET['saved']] ?? 'Changes saved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // HANDLE SELLER FORM ACTIONS, THEN REDIRECT BACK TO A CLEAN DASHBOARD URL.
    $action = $_POST['action'] ?? '';

    if ($action === 'add_menu_item') {
        // ADD A NEW MENU ITEM WITH OPTIONAL VARIANTS AND IMAGE.
        $itemName = trim($_POST['itemName'] ?? '');
        $category = trim($_POST['itemCategory'] ?? '');
        $price = (float) ($_POST['itemPrice'] ?? 0);
        $variants = trim($_POST['itemVariants'] ?? '');
        $description = trim($_POST['itemDescription'] ?? '');
        $available = isset($_POST['isAvailable']) ? 1 : 0;
        $imagePath = uploadSellerImage('itemImage', 'item');

        if ($itemName !== '' && $price >= 0 && $shopID > 0) {
            $fullDescription = trim($variants !== '' ? "Variants: {$variants}\n{$description}" : $description);
            db_query(
                $conn,
                "INSERT INTO menu_items (shopID, itemName, itemDescription, itemCategory, itemPrice, itemImage, itemStock, isAvailable)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$shopID, $itemName, $fullDescription, $category, $price, $imagePath, 999, $available]
            );
            header("Location: seller.php?panel=menu&saved=item");
            exit();
        }

        $flash = 'Please enter an item name and valid price.';
        $flashType = 'danger';
    }

    if ($action === 'toggle_item') {
        $itemID = (int) ($_POST['itemID'] ?? 0);
        $isAvailable = (int) ($_POST['isAvailable'] ?? 0);
        db_query($conn, "UPDATE menu_items SET isAvailable = ?, updatedAt = NOW() WHERE itemID = ? AND shopID = ?", [$isAvailable, $itemID, $shopID]);
        header("Location: seller.php?panel=menu");
        exit();
    }

    if ($action === 'update_order') {
        $orderID = (int) ($_POST['orderID'] ?? 0);
        $status = $_POST['status'] ?? '';
        $orderDb = $_POST['orderDb'] ?? 'animeals';
        $allowedStatuses = ['accepted', 'preparing', 'ready', 'completed', 'cancelled'];
        if (in_array($status, $allowedStatuses, true) && $orderID > 0 && $shopID > 0) {
            if ($orderDb === 'legacy') {
                db_query(
                    $conn,
                    "UPDATE orders SET orderStatus = ? WHERE orderID = ? AND shopID = ?",
                    [$status, $orderID, $shopID]
                );
            } else {
                $sql = "UPDATE animeals.orders SET orderStatus = ?";
                $params = [$status];
                if ($status === 'accepted') {
                    $sql .= ", acceptedAt = COALESCE(acceptedAt, NOW())";
                }
                if ($status === 'completed') {
                    $sql .= ", completedAt = COALESCE(completedAt, NOW())";
                }
                $sql .= " WHERE orderID = ? AND shopID = ?";
                $params[] = $orderID;
                $params[] = $shopID;
                db_query($conn, $sql, $params);
            }
        }
        header("Location: seller.php?panel=orders");
        exit();
    }

    if ($action === 'save_profile') {
        $name = trim($_POST['userName'] ?? '');
        $email = trim($_POST['userEmail'] ?? '');
        $phone = trim($_POST['userPhone'] ?? '');
        $newPic = uploadSellerImage('profilePic', 'profile');
        $picToSave = $newPic ?: ($user['userPROFILEPIC'] ?? '');

        if ($name !== '' && $email !== '') {
            db_query(
                $conn,
                "UPDATE user_details SET userNAME = ?, userEMAIL = ?, userPHONE = ?, userPROFILEPIC = ? WHERE userID = ?",
                [$name, $email, $phone, $picToSave, $sellerID]
            );
            if ($connAnimeals) {
                db_query(
                    $connAnimeals,
                    "UPDATE user_details SET userNAME = ?, userEMAIL = ?, userPHONE = ?, userPROFILEPIC = ? WHERE userEMAIL = ?",
                    [$name, $email, $phone, $picToSave, $_SESSION['email']]
                );
            }
            $_SESSION['user'] = $name;
            $_SESSION['email'] = $email;
            header("Location: seller.php?panel=settings&saved=profile");
            exit();
        }
        $flash = 'Name and email are required.';
        $flashType = 'danger';
    }

    if ($action === 'update_password') {
        $currentPassword = $_POST['currentPassword'] ?? '';
        $newPassword = $_POST['newPassword'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        if ($currentPassword === ($user['userPASSWORD'] ?? '') && $newPassword !== '' && $newPassword === $confirmPassword) {
            db_query($conn, "UPDATE user_details SET userPASSWORD = ? WHERE userID = ?", [$newPassword, $sellerID]);
            if ($connAnimeals) {
                db_query($connAnimeals, "UPDATE user_details SET userPASSWORD = ? WHERE userEMAIL = ?", [$newPassword, $_SESSION['email']]);
            }
            header("Location: seller.php?panel=settings&saved=password");
            exit();
        }
        $flash = 'Password was not updated. Check your current password and confirmation.';
        $flashType = 'danger';
    }

    if ($action === 'save_store') {
        $storeName = trim($_POST['storeName'] ?? '');
        $storeDescription = trim($_POST['storeDescription'] ?? '');
        $storeCategory = trim($_POST['storeCategory'] ?? '');
        $storeType = trim($_POST['storeType'] ?? '');
        $storeLat = isset($_POST['storeLat']) && is_numeric($_POST['storeLat']) ? (float) $_POST['storeLat'] : null;
        $storeLng = isset($_POST['storeLng']) && is_numeric($_POST['storeLng']) ? (float) $_POST['storeLng'] : null;
        $storeAddress = trim((string) ($_POST['storeAddress'] ?? ''));
        if ($storeCategory !== '') {
            $storeDescription = '[Store category: ' . $storeCategory . ']' . ($storeDescription !== '' ? "\n" . $storeDescription : '');
        }
        $newLogo = uploadSellerImage('storeLogo', 'store');
        $logoToSave = $newLogo ?: ($shop['shopLogo'] ?? '');

        if ($storeName !== '' && $shopID > 0) {
            db_query(
                $conn,
                "UPDATE seller_shops SET shopName = ?, shopDescription = ?, shopLogo = ?, shopType = ?, shopLAT = ?, shopLNG = ?, shopADDRESS = ? WHERE shopID = ? AND sellerID = ?",
                [$storeName, $storeDescription, $logoToSave, $storeType !== '' ? $storeType : null, $storeLat, $storeLng, $storeAddress !== '' ? $storeAddress : null, $shopID, $sellerID]
            );
            db_query($conn, "UPDATE user_details SET userSHOPNAME = ? WHERE userID = ?", [$storeName, $sellerID]);
            if ($connAnimeals) {
                db_query($connAnimeals, "UPDATE user_details SET userSHOPNAME = ? WHERE userEMAIL = ?", [$storeName, $_SESSION['email']]);
            }
            header("Location: seller.php?panel=settings&saved=store");
            exit();
        }
        $flash = 'Store name is required.';
        $flashType = 'danger';
    }
}

$shopStmt = db_query($conn, "SELECT * FROM seller_shops WHERE sellerID = ? LIMIT 1", [$sellerID]);
$shop = $shopStmt ? db_fetch_assoc($shopStmt) : $shop;
$shopID = (int) ($shop['shopID'] ?? 0);

$menuItems = fetchAllRows(db_query($conn, "SELECT * FROM menu_items WHERE shopID = ? ORDER BY createdAt DESC", [$shopID]));
$menuCategoryRows = fetchAllRows(db_query(
    $conn,
    "SELECT DISTINCT TRIM(itemCategory) AS c
     FROM menu_items
     WHERE shopID = ? AND NULLIF(TRIM(itemCategory), '') IS NOT NULL
     ORDER BY c",
    [$shopID]
));

$ordersAnimeals = fetchAllRows(db_query(
    $conn,
    "SELECT o.*, u.userNAME, u.userPROFILEPIC, 'animeals' AS _orderDb
     FROM animeals.orders o
     LEFT JOIN animeals.user_details u ON u.userID = o.studentID
     WHERE o.shopID = ?",
    [$shopID]
));
$ordersLegacy = fetchAllRows(db_query(
    $conn,
    "SELECT o.*, u.userNAME, u.userPROFILEPIC, 'legacy' AS _orderDb
     FROM orders o
     LEFT JOIN animeals.user_details u ON u.userID = o.studentID
     WHERE o.shopID = ?",
    [$shopID]
));

$idsInAnimeals = [];
foreach ($ordersAnimeals as $o) {
    $idsInAnimeals[(int) ($o['orderID'] ?? 0)] = true;
}
$orders = $ordersAnimeals;
foreach ($ordersLegacy as $o) {
    $oid = (int) ($o['orderID'] ?? 0);
    if ($oid && empty($idsInAnimeals[$oid])) {
        $orders[] = $o;
    }
}
usort($orders, static function ($a, $b) {
    $ta = ($a['orderedAt'] ?? null) instanceof DateTimeInterface ? $a['orderedAt']->getTimestamp() : 0;
    $tb = ($b['orderedAt'] ?? null) instanceof DateTimeInterface ? $b['orderedAt']->getTimestamp() : 0;
    return $tb <=> $ta;
});

$pendingOrders = array_values(array_filter($orders, fn($order) => in_array(strtolower((string) ($order['orderStatus'] ?? '')), ['pending', 'accepted', 'preparing', 'ready'], true)));

$orderItemsByOrder = [];
if ($orders) {
    foreach ($orders as $order) {
        $oid = (int) $order['orderID'];
        $src = ($order['_orderDb'] ?? 'animeals') === 'legacy' ? 'legacy' : 'animeals';
        if ($src === 'legacy') {
            $orderItemsByOrder[$oid] = fetchAllRows(db_query(
                $conn,
                "SELECT oi.*, mi.itemName
                 FROM order_items oi
                 LEFT JOIN menu_items mi ON mi.itemID = oi.itemID
                 WHERE oi.orderID = ?",
                [$oid]
            ));
        } else {
            $orderItemsByOrder[$oid] = fetchAllRows(db_query(
                $conn,
                "SELECT oi.*, mi.itemName
                 FROM animeals.order_items oi
                 LEFT JOIN menu_items mi ON mi.itemID = oi.itemID
                 WHERE oi.orderID = ?",
                [$oid]
            ));
        }
    }
}

$completedOrders = array_values(array_filter($orders, fn($order) => in_array(($order['orderStatus'] ?? ''), ['completed', 'ready'], true)));
$totalSales = array_sum(array_map(fn($order) => in_array(($order['orderStatus'] ?? ''), ['completed', 'ready'], true) ? (float) $order['totalAmount'] : 0, $orders));
$totalOrders = count($orders);
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;
$reviewRows = fetchAllRows(db_query(
    $conn,
    "SELECT r.reviewID, r.orderID, r.shopID, r.studentID, r.rating, r.reviewText, r.createdAt,
            u.userNAME, u.userPROFILEPIC
     FROM animeals.shop_reviews r
     LEFT JOIN animeals.user_details u ON u.userID = r.studentID
     WHERE r.shopID = ?
     ORDER BY r.createdAt DESC",
    [$shopID]
));
$avgRating = count($reviewRows) > 0 ? array_sum(array_map(fn($review) => (float) $review['rating'], $reviewRows)) / count($reviewRows) : 0;
$topItems = fetchAllRows(db_query(
    $conn,
    "SELECT mi.itemName, mi.itemImage, SUM(oi.quantity) AS soldQty
     FROM animeals.order_items oi
     INNER JOIN menu_items mi ON mi.itemID = oi.itemID
     INNER JOIN animeals.orders o ON o.orderID = oi.orderID
     WHERE o.shopID = ? AND o.orderStatus IN ('completed', 'ready')
     GROUP BY mi.itemName, mi.itemImage
     ORDER BY soldQty DESC
     LIMIT 3",
    [$shopID]
));

$categorySales = fetchAllRows(db_query(
    $conn,
    "SELECT COALESCE(NULLIF(TRIM(mi.itemCategory), ''), 'Other') AS catName,
            SUM(oi.quantity * COALESCE(oi.price, mi.itemPrice)) AS catRevenue
     FROM animeals.order_items oi
     INNER JOIN menu_items mi ON mi.itemID = oi.itemID
     INNER JOIN animeals.orders o ON o.orderID = oi.orderID
     WHERE o.shopID = ? AND o.orderStatus IN ('completed', 'ready')
     GROUP BY COALESCE(NULLIF(TRIM(mi.itemCategory), ''), 'Other')",
    [$shopID]
));
$categoryTotalRev = array_sum(array_map(fn ($r) => (float) ($r['catRevenue'] ?? 0), $categorySales));

$categoryChartData = array_map(static fn ($r) => [
    'name' => (string) ($r['catName'] ?? 'Other'),
    'revenue' => (float) ($r['catRevenue'] ?? 0),
], $categorySales);

$ratingDist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($reviewRows as $rr) {
    $st = (int) round((float) ($rr['rating'] ?? 0));
    if ($st >= 1 && $st <= 5) {
        $ratingDist[$st]++;
    }
}
$ratingReviewCount = count($reviewRows);
$ratingChartData = [];
for ($s = 5; $s >= 1; $s--) {
    $ratingChartData[] = ['stars' => $s, 'count' => (int) ($ratingDist[$s] ?? 0)];
}

$weeklyRevByDate = [];
$wkStmt = db_query(
    $conn,
    "SELECT DATE(o.orderedAt) AS d, SUM(o.totalAmount) AS amt
     FROM animeals.orders o
     WHERE o.shopID = ? AND o.orderStatus IN ('completed', 'ready')
       AND o.orderedAt >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(o.orderedAt)",
    [$shopID]
);
if ($wkStmt) {
    $wkRows = db_fetch_all($wkStmt);
    foreach ($wkRows as $r) {
        $dkey = $r['d'] instanceof DateTimeInterface ? $r['d']->format('Y-m-d') : (string) $r['d'];
        $weeklyRevByDate[$dkey] = (float) ($r['amt'] ?? 0);
    }
}
$weeklySeries = [];
for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-{$i} days");
    $key = date('Y-m-d', $ts);
    $weeklySeries[] = [
        'date' => $key,
        'label' => date('D', $ts),
        'revenue' => round($weeklyRevByDate[$key] ?? 0, 2),
    ];
}

$statusCounts = ['all' => count($orders), 'pending' => 0, 'accepted' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($orders as $order) {
    $status = $order['orderStatus'] ?? '';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$firstOrderRow = $orders[0] ?? null;
$pollTs = ($firstOrderRow && ($firstOrderRow['orderedAt'] ?? null) instanceof DateTimeInterface)
    ? $firstOrderRow['orderedAt']->format('c')
    : '';
$orderPollSig = $shopID > 0
    ? md5($shopID . '|' . count($orders) . '|' . (int) ($firstOrderRow['orderID'] ?? 0) . '|' . $pollTs . '|' . $statusCounts['pending'])
    : '';

if (($_GET['ajax'] ?? '') === 'orders_poll') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['sig' => $orderPollSig, 'pending' => (int) $statusCounts['pending']]);
    exit;
}

$activePanel = $_GET['panel'] ?? 'dashboard';
$activePage = match ($activePanel) {
    'menu' => 'menu-page',
    'orders' => 'orders-page',
    'transaction' => 'transaction-page',
    'settings' => 'settings-page',
    default => 'dashboard',
};

$profilePic = !empty($user['userPROFILEPIC'])
    ? h($user['userPROFILEPIC'])
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
$displayName = h($user['userNAME'] ?? $_SESSION['user']);
$shopName = h(($shop['shopName'] ?? '') ?: ($user['userSHOPNAME'] ?: $user['userEMAIL']));
$shopDescription = h($shop['shopDescription'] ?? '');
$shopLogo = !empty($shop['shopLogo']) ? h($shop['shopLogo']) : $profilePic;
$shortName = h(strtok($user['userNAME'] ?? $_SESSION['user'], ' ') ?: $_SESSION['user']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Seller Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Outfit',sans-serif; }
        body { background:#0f7a4a; height:100vh; overflow:hidden; color:#2d2d2d; }
        .dashboard-container { display:grid; grid-template-columns:240px 1fr; height:100vh; }

        /* ===== SIDEBAR ===== */
        .sidebar {
            background:#fff; color:#0f7a4a; padding:25px 18px;
            display:flex; flex-direction:column;
            border-top-right-radius:40px; border-bottom-right-radius:40px;
            box-shadow:12px 0 40px rgba(0,0,0,0.15); z-index:100;
        }
        .brand { display:flex; align-items:center; gap:12px; font-size:24px; font-weight:800; margin-bottom:25px; color:#0f7a4a; }
        .brand img { width:32px; height:32px; object-fit:contain; margin-bottom:10px; }
        .profile { text-align:center; margin-bottom:25px; }
        .profile img { width:75px; height:75px; border-radius:50%; border:3px solid #d0ede0; object-fit:cover; }
        .profile h4 { font-size:15px; margin-top:12px; font-weight:600; color:#0f7a4a; }
        .profile small { font-size:12px; color:#6ab890; }
        .nav-menu { display:flex; flex-direction:column; gap:8px; }
        .nav-menu a {
            text-decoration:none; color:#5a9e7a; padding:12px 16px; border-radius:18px;
            display:flex; align-items:center; justify-content:space-between;
            font-size:14px; transition:0.3s; cursor:pointer;
        }
        .nav-menu a .menu-left { display:flex; align-items:center; }
        .nav-menu a i { margin-right:14px; font-size:20px; }
        .nav-menu a:hover { background:#f0faf7; }
        .nav-menu a.active {
            background:linear-gradient(135deg,#0f7a4a,#1faa6c);
            color:#fff; font-weight:700; box-shadow:0 10px 20px rgba(15,122,74,0.2);
        }
        .badge-red {
            background:#ff4d4d; color:white; padding:2px 8px; border-radius:10px;
            font-size:11px; font-weight:bold; box-shadow:0 0 10px rgba(255,77,77,0.6);
            animation:pulse-glow 2s infinite; border:none; flex-shrink:0;
        }
        @keyframes pulse-glow {
            0%   { box-shadow:0 0 5px rgba(255,77,77,0.6); }
            50%  { box-shadow:0 0 15px rgba(255,77,77,0.9); }
            100% { box-shadow:0 0 5px rgba(255,77,77,0.6); }
        }
        .sidebar-bottom { margin-top:auto; display:flex; flex-direction:column; gap:8px; }
        .settings-link {
            text-decoration:none; color:#5a9e7a; padding:12px 16px; border-radius:18px;
            display:flex; align-items:center; font-size:14px; transition:0.3s; cursor:pointer;
        }
        .settings-link i { margin-right:14px; font-size:20px; }
        .settings-link:hover { background:#f0faf7; }
        .settings-link.active {
            background:linear-gradient(135deg,#0f7a4a,#1faa6c);
            color:#fff; font-weight:700; box-shadow:0 10px 20px rgba(15,122,74,0.2);
        }
        .logout-btn {
            background:#f0faf7; text-align:center; padding:12px; border-radius:18px;
            color:#0f7a4a; text-decoration:none; font-weight:700; transition:0.3s ease;
            display:block; border:1.5px solid #d0ede0; cursor:pointer;
        }
        .logout-btn:hover { background:#dc3545; color:#fff; border-color:#dc3545; box-shadow:0 8px 20px rgba(220,53,69,0.3); transform:translateY(-2px); }

        /* ===== MAIN ===== */
        .main { padding:25px; height:100vh; display:flex; flex-direction:column; overflow:hidden; box-sizing:border-box; }
        .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
        .topbar-left { display:flex; align-items:center; gap:18px; flex:1; min-width:0; margin-right:20px; }
        .page-title { font-size:32px; font-weight:800; color:#fff; letter-spacing:1px; white-space:nowrap; }

        /* CHANGE 3: Search bar width fixed to match live orders (left column = 1.5fr of content-grid)
           The content area minus sidebar is: 100% - 240px. Left col = 1.5/(1.5+1) = 60% of grid minus gap.
           We approximate by capping max-width and letting it stretch within topbar-left. */
        .search-box {
            background:rgba(255,255,255,0.15); padding:11px 18px; border-radius:20px;
            display:flex; align-items:center; border:1.5px solid rgba(255,255,255,0.25);
            backdrop-filter:blur(4px); flex:0 0 auto; width:calc(60% - 60px); max-width:520px;
        }
        .search-box i { color:#fff; flex-shrink:0; }
        .search-box input {
            border:none; outline:none; padding-left:10px; width:100%;
            font-size:14px; font-family:'Outfit',sans-serif; background:transparent; color:#fff;
        }
        .search-box input::placeholder { color:rgba(255,255,255,0.65); }

        .topbar-right { display:flex; align-items:center; gap:20px; flex-shrink:0; }
        .topbar-icons { display:flex; align-items:center; gap:20px; }
        .topbar-icons .icon-wrap {
            position:relative; cursor:pointer; color:#fff; font-size:22px;
            transition:0.3s; display:flex; align-items:center;
        }
        .topbar-icons .icon-wrap:hover { transform:scale(1.1); opacity:0.8; }
        .topbar-icons .icon-wrap .badge-red { position:absolute; top:-5px; right:-10px; font-size:9px; padding:1px 5px; }
        .profile-btn {
            background:rgba(255,255,255,0.15); color:#fff;
            border:1.5px solid rgba(255,255,255,0.25); padding:10px 20px; border-radius:20px;
            display:flex; align-items:center; gap:10px; cursor:pointer; transition:0.3s;
            font-weight:600; font-size:13px; text-decoration:none; backdrop-filter:blur(4px);
        }
        .profile-btn:hover { background:rgba(255,255,255,0.25); transform:translateY(-2px); }
        .profile-btn img { width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.4); }
        .profile-btn-text { display:flex; flex-direction:column; text-align:left; }
        .profile-btn-name { font-weight:700; font-size:13px; color:#fff; }
        .profile-btn-sub { font-size:11px; color:rgba(255,255,255,0.75); }

        /* ===== PAGE VIEWS ===== */
        .page-view { display:none; flex:1; min-height:0; flex-direction:column; overflow:hidden; }
        .page-view.active { display:flex; }

        /* ===== DASHBOARD ===== */
        .content-grid { display:grid; grid-template-columns:1.5fr 1fr; gap:25px; flex:1; min-height:0; }
        .left-col { display:flex; flex-direction:column; min-height:0; }
        .orders-container {
            background:#fff; border-radius:28px; padding:20px;
            box-shadow:0 10px 40px rgba(0,0,0,0.15); display:flex; flex-direction:column; flex:1; min-height:0;
        }
        .orders-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; }
        .orders-list { overflow-y:auto; padding-right:5px; flex:1; scrollbar-width:thin; scrollbar-color:#158f5f transparent; }
        .orders-list::-webkit-scrollbar { width:6px; }
        .orders-list::-webkit-scrollbar-thumb { background:#158f5f; border-radius:10px; }
        .order-card {
            background:#f9fdfb; border:1px solid #eef2f0; border-radius:18px; padding:15px;
            margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; transition:0.3s;
        }
        .order-card:hover { box-shadow:0 8px 20px rgba(15,122,74,0.1); transform:translateY(-2px); }
        .order-user { display:flex; gap:15px; align-items:center; }
        .order-user img { width:50px; height:50px; border-radius:12px; object-fit:cover; }
        .order-details strong { font-size:15px; color:#333; display:block; font-weight:700; }
        .order-details p { font-size:13px; color:#777; margin:2px 0; }
        .order-details p b { color:#0f7a4a; }
        .order-details .price { font-size:16px; font-weight:800; color:#0f7a4a; }
        .order-actions { display:flex; flex-direction:column; gap:8px; }
        .accept-btn {
            background:#0f7a4a; color:white; border:none; padding:9px 20px; border-radius:12px;
            font-weight:700; cursor:pointer; transition:0.3s; font-size:13px;
            font-family:'Outfit',sans-serif; box-shadow:0 4px 12px rgba(15,122,74,0.2);
        }
        .accept-btn:hover { background:#0c633c; transform:scale(1.05); }
        .cancel-btn {
            background:#fff; color:#dc3545; border:1.5px solid #f5c6cb; padding:9px 20px;
            border-radius:12px; font-weight:700; cursor:pointer; transition:0.3s;
            font-size:13px; font-family:'Outfit',sans-serif;
        }
        .cancel-btn:hover { background:#dc3545; color:#fff; border-color:#dc3545; transform:scale(1.05); }
        .right-col { display:flex; flex-direction:column; gap:20px; min-height:0; }
        .top-selling {
            background:#fff; padding:20px 24px; border-radius:28px;
            box-shadow:0 10px 40px rgba(0,0,0,0.15); flex-shrink:0; overflow:hidden;
        }
        .top-selling h3 { font-size:13px; font-weight:800; color:#b0b0b0; letter-spacing:1px; text-transform:uppercase; margin-bottom:4px; }
        .product-item { display:flex; align-items:center; gap:15px; margin-top:14px; padding:6px 0; }
        .product-item > span { font-size:13px; font-weight:800; color:#b0b0b0; width:16px; }
        .product-img { width:48px; height:48px; border-radius:14px; object-fit:cover; }
        .product-info b { display:block; font-size:14px; font-weight:700; }
        .product-info span { font-size:12px; color:#999; }
        .stats-grid { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:14px; flex:1; }
        .stat-box {
            background:#fff; padding:18px 20px; border-radius:22px;
            box-shadow:0 10px 30px rgba(0,0,0,0.12); border-left:5px solid #0f7a4a;
            transition:0.3s; display:flex; flex-direction:column; justify-content:center;
        }
        .stat-box:hover { transform:translateY(-3px); box-shadow:0 16px 32px rgba(0,0,0,0.18); }
        .stat-box h2 { font-size:20px; font-weight:800; color:#0f7a4a; }
        .stat-box p { font-size:10px; color:#b0b0b0; text-transform:uppercase; letter-spacing:1px; font-weight:800; margin-top:4px; }
        .stats-grid .stat-box:nth-of-type(n+5) { display:none; }

        /* ===== SHARED PAGE CARD ===== */
        .page-card {
            background:#fff; border-radius:28px; padding:24px;
            box-shadow:0 10px 40px rgba(0,0,0,0.15); flex:1; min-height:200px;
            display:flex; flex-direction:column; overflow:hidden;
        }
        .page-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-shrink:0; min-height:48px; overflow:visible; }
        .page-card-title { font-size:18px; font-weight:800; color:#0f7a4a; }
        .green-btn {
            background:#0f7a4a; color:#fff; border:none; padding:10px 22px; border-radius:14px;
            font-weight:700; font-size:13px; cursor:pointer; font-family:'Outfit',sans-serif;
            transition:0.3s; display:flex; align-items:center; gap:8px;
        }
        .green-btn:hover { background:#0c633c; transform:translateY(-1px); box-shadow:0 6px 18px rgba(15,122,74,0.3); }
        .scrollable { overflow-y:auto; flex:1; padding-right:4px; scrollbar-width:thin; scrollbar-color:#158f5f transparent; }
        .scrollable::-webkit-scrollbar { width:6px; }
        .scrollable::-webkit-scrollbar-thumb { background:#158f5f; border-radius:10px; }

        /* ===== MENU PAGE ===== */
        .menu-filters { display:flex; gap:10px; margin-bottom:18px; flex-shrink:0; flex-wrap:wrap; }
        .filter-chip {
            padding:7px 18px; border-radius:20px; border:1.5px solid #d0ede0;
            font-size:13px; font-weight:600; color:#5a9e7a; cursor:pointer; transition:0.3s; background:#f0faf7;
        }
        .filter-chip.active, .filter-chip:hover { background:#0f7a4a; color:#fff; border-color:#0f7a4a; }
        .menu-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; }
        .menu-item-card { background:#f9fdfb; border:1px solid #eef2f0; border-radius:20px; overflow:hidden; transition:0.3s; }
        .menu-item-card:hover { box-shadow:0 8px 24px rgba(15,122,74,0.12); transform:translateY(-2px); }
        .menu-item-card img { width:100%; height:120px; object-fit:cover; }
        .menu-item-info { padding:12px; }
        .menu-item-info b { display:block; font-size:14px; font-weight:700; color:#222; }
        .menu-item-info span { font-size:12px; color:#999; }
        .menu-item-footer { padding:10px 12px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #eef2f0; }
        .menu-item-price { font-size:15px; font-weight:800; color:#0f7a4a; }
        .item-toggle {
            width:38px; height:22px; border-radius:11px; background:#d0ede0; border:none;
            cursor:pointer; position:relative; transition:0.3s;
        }
        .item-toggle.on { background:#0f7a4a; }
        .item-toggle::after {
            content:''; position:absolute; width:16px; height:16px; border-radius:50%;
            background:#fff; top:3px; left:3px; transition:0.3s; box-shadow:0 1px 4px rgba(0,0,0,0.2);
        }
        .item-toggle.on::after { left:19px; }

        /* ===== ORDERS PAGE ===== */
        .orders-tabs { display:flex; gap:10px; margin-bottom:18px; flex-shrink:0; flex-wrap:wrap; }
        .otab {
            padding:8px 22px; border-radius:20px; font-size:13px; font-weight:700;
            cursor:pointer; border:1.5px solid #d0ede0; color:#5a9e7a; background:#f0faf7; transition:0.3s;
        }
        .otab.active { background:#0f7a4a; color:#fff; border-color:#0f7a4a; }
        .otab:hover:not(.active) { background:#e0f5ec; }
        .full-order-card {
            background:#f9fdfb; border:1px solid #eef2f0; border-radius:18px; padding:16px;
            margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; transition:0.3s;
        }
        .full-order-card:hover { box-shadow:0 6px 18px rgba(15,122,74,0.1); transform:translateY(-1px); }
        .order-meta { display:flex; gap:14px; align-items:center; }
        .order-meta img { width:52px; height:52px; border-radius:12px; object-fit:cover; }
        .order-meta-info strong { font-size:15px; font-weight:700; color:#222; }
        .order-meta-info p { font-size:13px; color:#777; margin:2px 0; }
        .order-meta-info .amt { font-size:15px; font-weight:800; color:#0f7a4a; }
        .order-status { padding:5px 14px; border-radius:12px; font-size:12px; font-weight:700; }
        .status-pending  { background:#fff3cd; color:#856404; }
        .status-accepted { background:#d1f5e0; color:#0a6832; }
        .status-cancelled{ background:#fde8e8; color:#a51c1c; }
        .status-delivered { background:#d0e8ff; color:#0d4d8a; }

        /* ===== TRANSACTION PAGE ===== */
        .trans-tabs { display:flex; gap:12px; margin-bottom:20px; flex-shrink:0; }
        .ttab {
            padding:10px 28px; border-radius:20px; font-size:14px; font-weight:700;
            cursor:pointer; border:1.5px solid #d0ede0; color:#5a9e7a; background:#f0faf7; transition:0.3s;
        }
        .ttab.active { background:#0f7a4a; color:#fff; border-color:#0f7a4a; box-shadow:0 4px 14px rgba(15,122,74,0.25); }
        .ttab:hover:not(.active) { background:#e0f5ec; }
        .trans-sub { display:none; flex:1; min-height:0; flex-direction:column; overflow:hidden; }
        .trans-sub.active { display:flex; }

        /* Analytics */
        .analytics-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; flex-shrink:0; }
        .ana-stat { background:#f0faf7; border-radius:18px; padding:16px 18px; border-left:4px solid #0f7a4a; }
        .analytics-grid .ana-stat:nth-of-type(n+5) { display:none; }
        .ana-stat h3 { font-size:20px; font-weight:800; color:#0f7a4a; }
        .ana-stat p { font-size:11px; color:#999; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-top:3px; }
        .ana-stat .change { font-size:12px; font-weight:700; margin-top:5px; }
        .ana-stat .change.up { color:#1faa6c; }
        .ana-stat .change.down { color:#dc3545; }
        .chart-row { display:grid; grid-template-columns:1.4fr 1fr; gap:16px; flex:1; min-height:0; }
        .chart-box { background:#f0faf7; border-radius:20px; padding:18px; overflow:hidden; display:flex; flex-direction:column; }
        .chart-box h4 { font-size:13px; font-weight:800; color:#0f7a4a; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:12px; }
        .bar-chart { display:flex; align-items:flex-end; gap:10px; flex:1; padding-top:8px; }
        .bar-wrap { display:flex; flex-direction:column; align-items:center; flex:1; gap:6px; }
        .bar { width:100%; border-radius:8px 8px 0 0; background:linear-gradient(180deg,#1faa6c,#0f7a4a); transition:0.3s; min-height:4px; }
        .bar:hover { background:linear-gradient(180deg,#26cc82,#158f5f); }
        .bar-label { font-size:10px; color:#999; font-weight:700; }
        .donut-wrap { display:flex; flex-direction:column; align-items:center; justify-content:center; flex:1; gap:12px; }
        .donut-wrap svg { width:120px; height:120px; }
        .donut-legend { width:100%; display:flex; flex-direction:column; gap:6px; }
        .legend-item { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; color:#555; }
        .legend-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

        .chart-toolbar {
            display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px;
            margin-bottom:10px;
        }
        .chart-toolbar label { font-size:11px; font-weight:700; color:#5a9e7a; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px; }
        .chart-sort-select {
            padding:6px 10px; border-radius:10px; border:1.5px solid #d0ede0; background:#fff;
            font-size:12px; font-weight:600; color:#0f7a4a; cursor:pointer;
        }

        /* Transaction table */
        .trans-table-wrap { flex:1; overflow-y:auto; scrollbar-width:thin; scrollbar-color:#158f5f transparent; }
        .trans-table-wrap::-webkit-scrollbar { width:6px; }
        .trans-table-wrap::-webkit-scrollbar-thumb { background:#158f5f; border-radius:10px; }
        .trans-table { width:100%; border-collapse:collapse; }
        .trans-table th {
            text-align:left; font-size:11px; font-weight:800; color:#999;
            text-transform:uppercase; letter-spacing:0.8px; padding:8px 12px;
            border-bottom:2px solid #eef2f0; position:sticky; top:0; background:#fff; z-index:1;
        }
        .trans-table td { padding:12px; font-size:13px; color:#555; border-bottom:1px solid #f2f2f2; }
        .trans-table tr:hover td { background:#f9fdfb; }
        .trans-table td strong { color:#222; font-weight:700; }

        /* Reviews */
        .reviews-header-row { display:flex; gap:20px; margin-bottom:20px; flex-shrink:0; }
        .review-summary-card {
            background:#f0faf7; border-radius:20px; padding:20px 24px;
            display:flex; flex-direction:column; align-items:center; justify-content:center; min-width:150px;
        }
        .review-big-rating { font-size:48px; font-weight:800; color:#0f7a4a; line-height:1; }
        .review-stars { font-size:22px; margin:4px 0; }
        .review-count { font-size:12px; color:#999; font-weight:600; }
        .rating-bars { flex:1; display:flex; flex-direction:column; gap:8px; justify-content:center; }
        .rating-bar-row { display:flex; align-items:center; gap:10px; font-size:12px; font-weight:700; color:#666; }
        .rating-bar-track { flex:1; height:8px; background:#e8e8e8; border-radius:4px; overflow:hidden; }
        .rating-bar-fill { height:100%; background:linear-gradient(90deg,#1faa6c,#0f7a4a); border-radius:4px; }
        .review-card { background:#f9fdfb; border:1px solid #eef2f0; border-radius:18px; padding:16px; margin-bottom:12px; }
        .review-card-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; }
        .reviewer { display:flex; gap:12px; align-items:center; }
        .reviewer img { width:40px; height:40px; border-radius:50%; object-fit:cover; }
        .reviewer-info strong { font-size:14px; font-weight:700; color:#222; }
        .reviewer-info span { font-size:12px; color:#999; display:block; }
        .review-stars-sm { font-size:14px; color:#f5a623; }
        .review-card p { font-size:13px; color:#666; line-height:1.6; }
        .review-badge { font-size:11px; font-weight:700; color:#0f7a4a; background:#d1f5e0; padding:3px 10px; border-radius:10px; }

        /* ===== SETTINGS PAGE — CHANGE 1: Sidebar nav layout ===== */
        .settings-layout {
            display:grid;
            grid-template-columns:220px 1fr;
            gap:0;
            flex:1;
            min-height:0;
            overflow:hidden;
            border-radius:20px;
            border:1px solid #eef2f0;
        }

        /* Settings left nav */
        .settings-nav {
            background:#f9fdfb;
            border-right:1px solid #eef2f0;
            display:flex;
            flex-direction:column;
            padding:8px 0;
            overflow-y:auto;
            border-radius:20px 0 0 20px;
        }
        .settings-nav-item {
            display:flex;
            align-items:center;
            gap:12px;
            padding:13px 20px;
            font-size:14px;
            font-weight:600;
            color:#777;
            cursor:pointer;
            transition:0.25s;
            border-left:3px solid transparent;
            position:relative;
        }
        .settings-nav-item i { font-size:18px; color:#aaa; transition:0.25s; }
        .settings-nav-item:hover { background:#f0faf7; color:#0f7a4a; }
        .settings-nav-item:hover i { color:#0f7a4a; }
        .settings-nav-item.active {
            background:#f0faf7;
            color:#0f7a4a;
            font-weight:700;
            border-left:3px solid #0f7a4a;
        }
        .settings-nav-item.active i { color:#0f7a4a; }

        /* Settings right content */
        .settings-content {
            overflow-y:auto;
            padding:28px 32px;
            scrollbar-width:thin;
            scrollbar-color:#158f5f transparent;
            background:#fff;
            border-radius:0 20px 20px 0;
        }
        .settings-content::-webkit-scrollbar { width:5px; }
        .settings-content::-webkit-scrollbar-thumb { background:#158f5f; border-radius:10px; }

        /* Settings sub-panels */
        .settings-panel { display:none; flex-direction:column; gap:20px; }
        .settings-panel.active { display:flex; }

        .settings-panel-title {
            font-size:22px;
            font-weight:800;
            color:#222;
            margin-bottom:2px;
        }
        .settings-panel-subtitle {
            font-size:13px;
            color:#999;
            margin-bottom:4px;
        }
        .settings-divider {
            font-size:11px;
            font-weight:800;
            color:#0f7a4a;
            text-transform:uppercase;
            letter-spacing:1.2px;
            margin-bottom:4px;
            margin-top:4px;
        }

        .settings-section { background:#f9fdfb; border-radius:18px; padding:22px; border:1px solid #eef2f0; display:flex; flex-direction:column; gap:16px; }
        .settings-section-title { font-size:13px; font-weight:800; color:#0f7a4a; text-transform:uppercase; letter-spacing:1px; }
        .settings-field label { font-size:12px; font-weight:700; color:#888; margin-bottom:5px; display:block; text-transform:uppercase; letter-spacing:0.5px; }
        .settings-field input, .settings-field select, .settings-field textarea {
            width:100%; padding:10px 14px; border-radius:12px; border:1.5px solid #d0ede0;
            font-size:14px; font-family:'Outfit',sans-serif; color:#333; outline:none; transition:0.3s; background:#fff;
        }
        .settings-field input:focus, .settings-field select:focus, .settings-field textarea:focus {
            border-color:#0f7a4a; box-shadow:0 0 0 3px rgba(15,122,74,0.1);
        }
        .settings-field textarea { resize:vertical; min-height:70px; }
        .seller-map-picker { height:260px; border-radius:16px; border:1px solid #dbe7e1; overflow:hidden; background:#edf7f2; }
        .seller-map-hint { font-size:12px; color:#777; line-height:1.45; margin-top:8px; }
        .seller-open-map-btn { background:none; border:none; color:#0f7a4a; font-weight:800; font-family:'Outfit',sans-serif; cursor:pointer; padding:0; text-decoration:none; }
        .seller-open-map-btn:hover { text-decoration:underline; }
        .seller-delivery-map-modal {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:3200;
            align-items:center; justify-content:center; padding:18px; backdrop-filter:blur(4px);
        }
        .seller-delivery-map-modal.open { display:flex; }
        .seller-delivery-map-card {
            width:min(92vw, 780px); background:#fff; border-radius:24px; padding:20px;
            box-shadow:0 24px 70px rgba(0,0,0,0.28);
        }
        .seller-delivery-map-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin-bottom:12px; }
        .seller-delivery-map-head h3 { color:#0f7a4a; font-size:18px; font-weight:800; margin-bottom:4px; }
        .seller-delivery-map-head p { color:#64748b; font-size:13px; line-height:1.45; }
        .seller-delivery-map-close { width:36px; height:36px; border-radius:50%; border:none; background:#f0faf7; color:#0f7a4a; cursor:pointer; font-size:22px; line-height:1; }
        .seller-delivery-map-close:hover { background:#dc3545; color:#fff; }
        #sellerDeliveryMap { height:min(58vh, 440px); min-height:300px; border-radius:18px; border:1px solid #dbe7e1; overflow:hidden; background:#edf7f2; }
        .seller-delivery-map-legend { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; font-size:12px; color:#475569; }
        .seller-delivery-map-legend span { display:inline-flex; align-items:center; gap:6px; font-weight:700; }
        .seller-delivery-map-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
        .toggle-row { display:flex; justify-content:space-between; align-items:center; }
        .toggle-row span { font-size:14px; font-weight:600; color:#444; }
        .toggle-switch {
            width:44px; height:24px; border-radius:12px; background:#d0ede0; border:none;
            cursor:pointer; position:relative; transition:0.3s;
        }
        .toggle-switch.on { background:#0f7a4a; }
        .toggle-switch::after {
            content:''; position:absolute; width:18px; height:18px; border-radius:50%;
            background:#fff; top:3px; left:3px; transition:0.3s; box-shadow:0 1px 4px rgba(0,0,0,0.2);
        }
        .toggle-switch.on::after { left:23px; }
        .save-btn {
            background:linear-gradient(135deg,#0f7a4a,#1faa6c); color:#fff; border:none;
            padding:12px; border-radius:14px; font-weight:800; font-size:14px; cursor:pointer;
            font-family:'Outfit',sans-serif; transition:0.3s; width:100%; box-shadow:0 6px 18px rgba(15,122,74,0.3);
        }
        .save-btn:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,122,74,0.4); }
        .avatar-upload { display:flex; align-items:center; gap:16px; }
        .avatar-upload img { width:64px; height:64px; border-radius:50%; object-fit:cover; border:3px solid #d0ede0; }
        .upload-btn {
            background:#f0faf7; border:1.5px solid #d0ede0; color:#0f7a4a;
            padding:9px 18px; border-radius:12px; font-weight:700; font-size:13px;
            cursor:pointer; font-family:'Outfit',sans-serif; transition:0.3s;
        }
        .upload-btn:hover { background:#0f7a4a; color:#fff; border-color:#0f7a4a; }

        /* ===== CHANGE 4: Add Item Modal ===== */
        .modal-overlay {
            display:none;
            position:fixed; inset:0;
            background:rgba(0,0,0,0.45);
            backdrop-filter:blur(4px);
            z-index:999;
            align-items:center;
            justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:#fff;
            border-radius:28px;
            padding:30px;
            width:520px;
            max-width:92vw;
            max-height:88vh;
            overflow-y:auto;
            box-shadow:0 20px 60px rgba(0,0,0,0.25);
            animation:modal-in 0.25s ease;
        }
        @keyframes modal-in {
            from { transform:scale(0.94) translateY(12px); opacity:0; }
            to   { transform:scale(1) translateY(0); opacity:1; }
        }
        .modal-header {
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom:22px;
        }
        .modal-title { font-size:20px; font-weight:800; color:#0f7a4a; }
        .modal-close {
            background:#f0faf7; border:none; width:36px; height:36px; border-radius:50%;
            cursor:pointer; font-size:18px; color:#0f7a4a; display:flex; align-items:center; justify-content:center;
            transition:0.2s;
        }
        .modal-close:hover { background:#dc3545; color:#fff; }

        /* Image upload area */
        .img-upload-area {
            border:2px dashed #d0ede0; border-radius:18px; padding:28px;
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:8px; cursor:pointer; transition:0.3s; background:#f9fdfb; margin-bottom:18px;
            min-height:130px;
        }
        .img-upload-area:hover { border-color:#0f7a4a; background:#f0faf7; }
        .img-upload-area i { font-size:32px; color:#b0d8c4; }
        .img-upload-area span { font-size:13px; color:#999; font-weight:600; }
        .img-upload-area small { font-size:11px; color:#bbb; }
        .img-upload-preview { width:100%; border-radius:14px; object-fit:cover; max-height:140px; display:none; }

        .modal-field { margin-bottom:14px; }
        .modal-field label { font-size:12px; font-weight:700; color:#888; margin-bottom:6px; display:block; text-transform:uppercase; letter-spacing:0.5px; }
        .modal-field input,
        .modal-field select,
        .modal-field textarea {
            width:100%; padding:11px 14px; border-radius:12px; border:1.5px solid #d0ede0;
            font-size:14px; font-family:'Outfit',sans-serif; color:#333; outline:none; transition:0.3s; background:#fff;
        }
        .modal-field input:focus,
        .modal-field select:focus,
        .modal-field textarea:focus { border-color:#0f7a4a; box-shadow:0 0 0 3px rgba(15,122,74,0.1); }
        .modal-field textarea { resize:vertical; min-height:72px; }

        .modal-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

        /* Flavors/variants tags */
        .tag-input-wrap {
            display:flex; flex-wrap:wrap; gap:8px; padding:10px 14px;
            border-radius:12px; border:1.5px solid #d0ede0; background:#fff; cursor:text; min-height:44px;
        }
        .tag-input-wrap:focus-within { border-color:#0f7a4a; box-shadow:0 0 0 3px rgba(15,122,74,0.1); }
        .tag-chip {
            background:#d1f5e0; color:#0a6832; padding:3px 10px 3px 12px; border-radius:20px;
            font-size:12px; font-weight:700; display:flex; align-items:center; gap:6px;
        }
        .tag-chip button { background:none; border:none; cursor:pointer; font-size:13px; color:#0a6832; padding:0; line-height:1; }
        .tag-input {
            border:none; outline:none; font-size:13px; font-family:'Outfit',sans-serif;
            color:#333; flex:1; min-width:80px; background:transparent;
        }

        .modal-footer { display:flex; gap:12px; margin-top:22px; }
        .modal-cancel-btn {
            flex:1; background:#f0faf7; color:#0f7a4a; border:1.5px solid #d0ede0;
            padding:12px; border-radius:14px; font-weight:700; font-size:14px; cursor:pointer;
            font-family:'Outfit',sans-serif; transition:0.3s;
        }
        .modal-cancel-btn:hover { background:#fde8e8; color:#dc3545; border-color:#f5c6cb; }
        .modal-submit-btn {
            flex:2; background:linear-gradient(135deg,#0f7a4a,#1faa6c); color:#fff; border:none;
            padding:12px; border-radius:14px; font-weight:800; font-size:14px; cursor:pointer;
            font-family:'Outfit',sans-serif; transition:0.3s; box-shadow:0 6px 18px rgba(15,122,74,0.3);
        }
        .modal-submit-btn:hover { transform:translateY(-1px); box-shadow:0 10px 24px rgba(15,122,74,0.4); }

        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-thumb { background:#ddd; border-radius:10px; }

        .seller-text-modal-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:3000;
            align-items:center; justify-content:center; padding:20px;
        }
        .seller-text-modal-overlay.open { display:flex; }
        .seller-text-modal {
            background:#fff; border-radius:18px; max-width:520px; width:100%; max-height:80vh; overflow:auto;
            padding:22px 24px; box-shadow:0 20px 50px rgba(0,0,0,0.2);
        }
        .seller-text-modal h3 { font-size:18px; color:#0f7a4a; margin-bottom:10px; }
        .seller-text-modal p { font-size:14px; color:#444; line-height:1.65; margin-bottom:10px; }
        .seller-text-modal .close-x {
            float:right; background:none; border:none; font-size:22px; cursor:pointer; color:#888; line-height:1;
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- ===== SIDEBAR (static, never re-renders) ===== -->
    <div class="sidebar">
        <div class="brand">
            <img src="logoo.png" alt="Logo">
            ANIMEALS
        </div>
        <div class="profile">
            <img src="<?= $profilePic ?>" alt="Profile">
            <h4><?= $displayName ?></h4>
            <small><?= $shopName ?></small>
        </div>
        <div class="nav-menu">
            <a id="nav-dashboard" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" onclick="navigate('dashboard','DASHBOARD',this)">
                <span class="menu-left"><i class="bi bi-grid-1x2-fill"></i> Dashboard</span>
            </a>
            <a id="nav-menu-page" class="<?= $activePage === 'menu-page' ? 'active' : '' ?>" onclick="navigate('menu-page','MENU',this)">
                <span class="menu-left"><i class="bi bi-journal-text"></i> Menu</span>
            </a>
            <a id="nav-orders-page" class="<?= $activePage === 'orders-page' ? 'active' : '' ?>" onclick="navigate('orders-page','ORDERS',this)">
                <span class="menu-left"><i class="bi bi-bag"></i> Orders</span>
                <?php if ($statusCounts['pending'] > 0): ?><span class="badge-red"><?= $statusCounts['pending'] ?></span><?php endif; ?>
            </a>
            <a id="nav-transaction-page" class="<?= $activePage === 'transaction-page' ? 'active' : '' ?>" onclick="navigate('transaction-page','TRANSACTION',this)">
                <span class="menu-left"><i class="bi bi-receipt"></i> Transaction</span>
            </a>
        </div>
        <div class="sidebar-bottom">
            <a id="nav-settings-page" class="settings-link <?= $activePage === 'settings-page' ? 'active' : '' ?>" onclick="navigate('settings-page','SETTINGS',this,true)">
                <i class="bi bi-gear"></i> Settings
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i> Log out
            </a>
        </div>
    </div>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="page-title" id="page-title"><?= h(strtoupper($activePanel === 'dashboard' ? 'dashboard' : $activePanel)) ?></div>
                <!-- CHANGE 3: search box width constrained to match live orders left column -->
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="sellerGlobalSearch" placeholder="Search menu, orders…" oninput="sellerGlobalFilter(this.value)">
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-icons">
                    <div class="icon-wrap" title="Community Feed (reviews)" role="button" tabindex="0" onclick="sellerOpenReviewsTab()">
                        <i class="bi bi-chat-square-dots-fill"></i>
                    </div>
                    <div class="icon-wrap" title="Orders &amp; alerts" role="button" tabindex="0" onclick="navigate('orders-page','ORDERS',document.getElementById('nav-orders-page'))">
                        <i class="bi bi-bell"></i>
                        <?php if ($statusCounts['pending'] > 0): ?><span class="badge-red"><?= $statusCounts['pending'] ?></span><?php endif; ?>
                    </div>
                    <div class="icon-wrap" title="Email support" role="button" tabindex="0" onclick="window.location.href='mailto:support@animeals.ph?subject=ANIMEALS%20Seller%20Support'">
                        <i class="bi bi-envelope"></i>
                    </div>
                </div>
                <a href="profile.php" class="profile-btn">
                    <img src="<?= $profilePic ?>" alt="Profile">
                    <div class="profile-btn-text">
                        <div class="profile-btn-name"><?= $shortName ?></div>
                        <div class="profile-btn-sub"><?= $shopName ?></div>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div style="background:<?= $flashType === 'danger' ? '#fde8e8' : '#d1f5e0' ?>;color:<?= $flashType === 'danger' ? '#a51c1c' : '#0a6832' ?>;padding:10px 16px;border-radius:14px;margin-bottom:14px;font-weight:700;font-size:13px;">
                <?= h($flash) ?>
            </div>
        <?php endif; ?>

        <!-- ========== DASHBOARD ========== -->
        <div class="page-view <?= $activePage === 'dashboard' ? 'active' : '' ?>" id="dashboard">
            <div class="content-grid">
                <div class="left-col">
                    <div class="orders-container">
                        <div class="orders-header">
                            <button onclick="navigate('orders-page','ORDERS',document.getElementById('nav-orders-page'))" style="background:none;border:none;font-size:18px;font-weight:800;cursor:pointer;font-family:'Outfit',sans-serif;color:#0f7a4a;">
                                Live Orders <?php if ($statusCounts['pending'] > 0): ?><span class="badge-red"><?= $statusCounts['pending'] ?></span><?php endif; ?>
                            </button>
                        </div>
                        <div class="orders-list">
                            <?php if (empty($pendingOrders)): ?>
                                <div style="color:#777;font-size:14px;padding:20px;text-align:center;">No active live orders yet.</div>
                            <?php endif; ?>
                            <?php foreach ($pendingOrders as $order): ?>
                                <?php
                                    $items = $orderItemsByOrder[(int) $order['orderID']] ?? [];
                                    $summary = $items ? implode(', ', array_map(fn($item) => ((int) $item['quantity']) . 'x ' . ($item['itemName'] ?? 'Item'), $items)) : ($order['orderNote'] ?: 'Order items');
                                    $orderedAt = $order['orderedAt'] instanceof DateTimeInterface ? $order['orderedAt']->format('h:i A') : '';
                                    $customerPic = !empty($order['userPROFILEPIC']) ? h($order['userPROFILEPIC']) : 'https://ui-avatars.com/api/?name=' . rawurlencode($order['userNAME'] ?? 'Customer') . '&background=d0ede0&color=0f7a4a';
                                ?>
                                <div class="order-card">
                                    <div class="order-user"><img src="<?= $customerPic ?>" alt="" onerror="this.style.background='#d0ede0'">
                                        <div class="order-details">
                                            <strong><?= h($order['userNAME'] ?? 'Customer') ?></strong>
                                            <p>Ordered: <b><?= h($summary) ?></b></p>
                                            <p><i class="bi bi-wallet2"></i> <?= h($order['paymentMethod']) ?> | <i class="bi bi-clock"></i> <?= h($orderedAt) ?></p>
                                            <?php if (!empty($order['deliveryADDRESS']) || (!empty($order['deliveryLAT']) && !empty($order['deliveryLNG']))): ?>
                                            <p><i class="bi bi-geo-alt"></i> <?= h($order['deliveryADDRESS'] ?: 'Pinned delivery location') ?>
                                                <?php if (!empty($order['deliveryLAT']) && !empty($order['deliveryLNG'])): ?>
                                                    <button type="button"
                                                            class="seller-open-map-btn"
                                                            data-order-map="1"
                                                            data-order-id="<?= (int) ($order['orderID'] ?? 0) ?>"
                                                            data-student-name="<?= h($order['userNAME'] ?? 'Customer') ?>"
                                                            data-student-lat="<?= h($order['deliveryLAT']) ?>"
                                                            data-student-lng="<?= h($order['deliveryLNG']) ?>"
                                                            data-student-address="<?= h($order['deliveryADDRESS'] ?: 'Pinned delivery location') ?>">Open map</button>
                                                <?php endif; ?>
                                            </p>
                                            <?php endif; ?>
                                            <span class="price"><?= money($order['totalAmount']) ?></span>
                                        </div>
                                    </div>
                                    <div class="order-actions">
                                        <form method="POST"><input type="hidden" name="action" value="update_order"><input type="hidden" name="orderDb" value="<?= h($order['_orderDb'] ?? 'animeals') ?>"><input type="hidden" name="orderID" value="<?= (int) $order['orderID'] ?>"><input type="hidden" name="status" value="accepted"><button class="accept-btn" type="submit">Accept</button></form>
                                        <form method="POST"><input type="hidden" name="action" value="update_order"><input type="hidden" name="orderDb" value="<?= h($order['_orderDb'] ?? 'animeals') ?>"><input type="hidden" name="orderID" value="<?= (int) $order['orderID'] ?>"><input type="hidden" name="status" value="cancelled"><button class="cancel-btn" type="submit">Cancel</button></form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div><!-- /orders-list -->
                    </div><!-- /orders-container -->
                </div><!-- /left-col -->
                <div class="right-col">
                    <div class="top-selling">
                        <h3>TOP SELLING PRODUCTS</h3>
                        <?php if (empty($topItems)): ?>
                            <div style="color:#777;font-size:13px;margin-top:12px;">Top sellers appear after completed orders.</div>
                        <?php endif; ?>
                        <?php foreach ($topItems as $index => $item): ?>
                            <div class="product-item" style="background:#f9fdfb;border-radius:14px;padding:8px;">
                                <span><?= $index + 1 ?></span>
                                <img src="<?= h($item['itemImage'] ?: 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=100') ?>" class="product-img">
                                <div class="product-info"><b><?= h($item['itemName']) ?></b><span><?= (int) $item['soldQty'] ?> sold</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-box" style="cursor:pointer;" title="Open analytics" onclick="navigate('transaction-page','TRANSACTION',document.getElementById('nav-transaction-page'));setTransTab(document.getElementById('ttab-analytics'),'analytics');"><h2><?= money($totalSales) ?></h2><p>Total Sales</p></div>
                        <div class="stat-box" style="cursor:pointer;" title="Open analytics" onclick="navigate('transaction-page','TRANSACTION',document.getElementById('nav-transaction-page'));setTransTab(document.getElementById('ttab-analytics'),'analytics');"><h2><?= money($avgSales) ?></h2><p>Avg Sales</p></div>
                        <div class="stat-box" style="cursor:pointer;" title="Open reviews" onclick="sellerOpenReviewsTab();"><h2><?= number_format($avgRating, 1) ?> &#9733;</h2><p>Avg Rating</p></div>
                        <div class="stat-box" style="cursor:pointer;" title="Open order history" onclick="navigate('transaction-page','TRANSACTION',document.getElementById('nav-transaction-page'));setTransTab(document.getElementById('ttab-history'),'history');"><h2><?= $totalOrders ?></h2><p>Total Orders</p></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== MENU PAGE ========== -->
        <div class="page-view <?= $activePage === 'menu-page' ? 'active' : '' ?>" id="menu-page">
            <div class="page-card">
                <div class="page-card-header">
                    <span class="page-card-title">My Menu Items</span>
                    <!-- CHANGE 4: opens Add Item modal -->
                    <button class="green-btn" onclick="openAddItemModal()"><i class="bi bi-plus-lg"></i> Add Item</button>
                </div>
                <div class="menu-filters" id="menu-filter-chips">
                    <span class="filter-chip active" data-cat="" onclick="setFilter(this)">All</span>
                    <?php foreach ($menuCategoryRows as $cr):
                        $cname = trim((string) ($cr['c'] ?? ''));
                        if ($cname === '') {
                            continue;
                        }
                    ?>
                    <span class="filter-chip" data-cat="<?= h($cname) ?>" onclick="setFilter(this)"><?= h($cname) ?></span>
                    <?php endforeach; ?>
                    <span class="filter-chip" data-cat="__other__" onclick="setFilter(this)">Other</span>
                </div>
                <div class="scrollable">
                    <div class="menu-grid">
                        <?php if (empty($menuItems)): ?>
                            <div style="grid-column:1/-1;color:#777;font-size:14px;text-align:center;padding:30px;">No menu items yet. Add your first item.</div>
                        <?php endif; ?>
                        <?php foreach ($menuItems as $item): ?>
                            <?php
                                $desc = trim((string) ($item['itemDescription'] ?? ''));
                                $variantText = preg_match('/Variants:\s*([^\n]+)/', $desc, $matches) ? $matches[1] : ($item['itemCategory'] ?? '');
                            ?>
                            <div class="menu-item-card" data-category="<?= h($item['itemCategory'] ?: 'Other') ?>">
                                <img src="<?= h($item['itemImage'] ?: 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=300') ?>" alt="">
                                <div class="menu-item-info"><b><?= h($item['itemName']) ?></b><span><?= h($variantText) ?></span></div>
                                <div class="menu-item-footer">
                                    <span class="menu-item-price"><?= money($item['itemPrice']) ?></span>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_item">
                                        <input type="hidden" name="itemID" value="<?= (int) $item['itemID'] ?>">
                                        <input type="hidden" name="isAvailable" value="<?= (int) !$item['isAvailable'] ?>">
                                        <button class="item-toggle <?= $item['isAvailable'] ? 'on' : '' ?>" type="submit" title="<?= $item['isAvailable'] ? 'Available' : 'Unavailable' ?>"></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div><!-- /menu-grid -->
                </div><!-- /scrollable -->
            </div><!-- /page-card -->
        </div><!-- /menu page-view -->

        <!-- ========== ORDERS PAGE ========== -->
        <div class="page-view <?= $activePage === 'orders-page' ? 'active' : '' ?>" id="orders-page">
            <div class="page-card">
                <div class="page-card-header">
                    <span class="page-card-title">All Orders</span>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <span style="font-size:13px;color:#999;font-weight:600;"><?= date('M j, Y') ?></span>
                        <button type="button" class="green-btn" onclick="location.href='export.php?report=orders&amp;format=csv'"><i class="bi bi-download"></i> CSV</button>
                        <button type="button" class="green-btn" onclick="location.href='export.php?report=orders&amp;format=pdf'"><i class="bi bi-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="orders-tabs">
                    <span class="otab active" onclick="setOrderTab(this,'all')">All <span style="background:#e8e8e8;color:#555;padding:1px 7px;border-radius:8px;font-size:11px;margin-left:4px;"><?= $statusCounts['all'] ?></span></span>
                    <span class="otab" onclick="setOrderTab(this,'pending')">Pending <?php if ($statusCounts['pending'] > 0): ?><span class="badge-red" style="margin-left:4px;animation:none;"><?= $statusCounts['pending'] ?></span><?php endif; ?></span>
                    <span class="otab" onclick="setOrderTab(this,'accepted')">Accepted</span>
                    <span class="otab" onclick="setOrderTab(this,'completed')">Completed</span>
                    <span class="otab" onclick="setOrderTab(this,'cancelled')">Cancelled</span>
                </div>
                <div class="scrollable" id="orders-list-full">
                    <?php if (empty($orders)): ?>
                        <div style="color:#777;font-size:14px;text-align:center;padding:30px;">No orders yet.</div>
                    <?php endif; ?>
                    <?php foreach ($orders as $order): ?>
                        <?php
                            $items = $orderItemsByOrder[(int) $order['orderID']] ?? [];
                            $summary = $items ? implode(', ', array_map(fn($item) => ((int) $item['quantity']) . 'x ' . ($item['itemName'] ?? 'Item'), $items)) : ($order['orderNote'] ?: 'Order items');
                            $orderedAt = $order['orderedAt'] instanceof DateTimeInterface ? $order['orderedAt']->format('M j, Y h:i A') : '';
                            $status = $order['orderStatus'] ?? 'pending';
                            $statusClassName = match ($status) {
                                'accepted', 'preparing', 'ready' => 'status-accepted',
                                'completed' => 'status-delivered',
                                'cancelled' => 'status-cancelled',
                                default => 'status-pending',
                            };
                            $customerPic = !empty($order['userPROFILEPIC']) ? h($order['userPROFILEPIC']) : 'https://ui-avatars.com/api/?name=' . rawurlencode($order['userNAME'] ?? 'Customer') . '&background=d0ede0&color=0f7a4a';
                        ?>
                        <div class="full-order-card" data-status="<?= h($status) ?>">
                            <div class="order-meta">
                                <img src="<?= $customerPic ?>" alt="" onerror="this.style.background='#d0ede0'">
                                <div class="order-meta-info">
                                    <strong><?= h($order['userNAME'] ?? 'Customer') ?></strong>
                                    <p>#<?= str_pad((string) $order['orderID'], 4, '0', STR_PAD_LEFT) ?> &nbsp;&middot;&nbsp; <b><?= h($summary) ?></b></p>
                                    <p><i class="bi bi-credit-card"></i> <?= h($order['paymentMethod']) ?> &nbsp;&middot;&nbsp; <i class="bi bi-clock"></i> <?= h($orderedAt) ?></p>
                                    <?php if (!empty($order['deliveryADDRESS']) || (!empty($order['deliveryLAT']) && !empty($order['deliveryLNG']))): ?>
                                    <p><i class="bi bi-geo-alt"></i> <?= h($order['deliveryADDRESS'] ?: 'Pinned delivery location') ?>
                                        <?php if (!empty($order['deliveryLAT']) && !empty($order['deliveryLNG'])): ?>
                                            &nbsp;&middot;&nbsp;<button type="button"
                                                    class="seller-open-map-btn"
                                                    data-order-map="1"
                                                    data-order-id="<?= (int) ($order['orderID'] ?? 0) ?>"
                                                    data-student-name="<?= h($order['userNAME'] ?? 'Customer') ?>"
                                                    data-student-lat="<?= h($order['deliveryLAT']) ?>"
                                                    data-student-lng="<?= h($order['deliveryLNG']) ?>"
                                                    data-student-address="<?= h($order['deliveryADDRESS'] ?: 'Pinned delivery location') ?>">Open map</button>
                                        <?php endif; ?>
                                    </p>
                                    <?php endif; ?>
                                    <span class="amt"><?= money($order['totalAmount']) ?></span>
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                                <span class="order-status <?= $statusClassName ?>"><?= h(ucfirst($status)) ?></span>
                                <?php if ($status === 'pending'): ?>
                                    <div style="display:flex;gap:8px;">
                                        <form method="POST"><input type="hidden" name="action" value="update_order"><input type="hidden" name="orderDb" value="<?= h($order['_orderDb'] ?? 'animeals') ?>"><input type="hidden" name="orderID" value="<?= (int) $order['orderID'] ?>"><input type="hidden" name="status" value="accepted"><button class="accept-btn" style="padding:7px 16px;" type="submit">Accept</button></form>
                                        <form method="POST"><input type="hidden" name="action" value="update_order"><input type="hidden" name="orderDb" value="<?= h($order['_orderDb'] ?? 'animeals') ?>"><input type="hidden" name="orderID" value="<?= (int) $order['orderID'] ?>"><input type="hidden" name="status" value="cancelled"><button class="cancel-btn" style="padding:7px 16px;" type="submit">Cancel</button></form>
                                    </div>
                                <?php elseif (in_array($status, ['accepted', 'preparing', 'ready'], true)): ?>
                                    <form method="POST"><input type="hidden" name="action" value="update_order"><input type="hidden" name="orderDb" value="<?= h($order['_orderDb'] ?? 'animeals') ?>"><input type="hidden" name="orderID" value="<?= (int) $order['orderID'] ?>"><input type="hidden" name="status" value="completed"><button class="accept-btn" style="padding:7px 16px;" type="submit">Complete</button></form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ========== TRANSACTION PAGE ========== -->
        <div class="page-view <?= $activePage === 'transaction-page' ? 'active' : '' ?>" id="transaction-page">
            <div class="page-card">
                <div class="page-card-header">
                    <span class="page-card-title">Transaction</span>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="green-btn" onclick="location.href='export.php?report=transactions&amp;format=csv'"><i class="bi bi-download"></i> CSV</button>
                        <button type="button" class="green-btn" onclick="location.href='export.php?report=transactions&amp;format=pdf'"><i class="bi bi-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="trans-tabs">
                    <span class="ttab active" id="ttab-analytics" onclick="setTransTab(this,'analytics')"><i class="bi bi-bar-chart-fill" style="margin-right:6px;"></i>Analytics</span>
                    <span class="ttab" id="ttab-history" onclick="setTransTab(this,'history')"><i class="bi bi-clock-history" style="margin-right:6px;"></i>History</span>
                    <span class="ttab" id="ttab-reviews" onclick="setTransTab(this,'reviews')"><i class="bi bi-star-fill" style="margin-right:6px;"></i>Reviews</span>
                </div>

                <!-- Analytics -->
                <div class="trans-sub active" id="trans-analytics">
                    <div class="analytics-grid">
                        <div class="ana-stat"><h3><?= money($totalSales) ?></h3><p>Total Revenue</p><div class="change up"><i class="bi bi-arrow-up-short"></i>Live data</div></div>
                        <div class="ana-stat"><h3><?= $totalOrders ?></h3><p>Total Orders</p><div class="change up"><i class="bi bi-arrow-up-short"></i><?= $statusCounts['pending'] ?> pending</div></div>
                        <div class="ana-stat"><h3><?= money($avgSales) ?></h3><p>Avg Order Value</p><div class="change up"><i class="bi bi-arrow-up-short"></i>Per order</div></div>
                        <div class="ana-stat"><h3><?= number_format($avgRating, 1) ?> &#9733;</h3><p>Avg Rating</p><div class="change up"><i class="bi bi-arrow-up-short"></i><?= count($reviewRows) ?> reviews</div></div>
                    </div>
                    <div class="chart-row">
                        <div class="chart-box">
                            <div class="chart-toolbar">
                                <h4 style="margin:0;">Weekly Sales (₱)</h4>
                                <label>Sort
                                    <select id="sortWeeklySales" class="chart-sort-select" aria-label="Sort weekly sales chart">
                                        <option value="date_asc">Date (oldest first)</option>
                                        <option value="date_desc">Date (newest first)</option>
                                        <option value="rev_desc">Revenue (high → low)</option>
                                        <option value="rev_asc">Revenue (low → high)</option>
                                    </select>
                                </label>
                            </div>
                            <div style="position:relative;height:240px;width:100%;min-height:200px;">
                                <canvas id="sellerWeeklyChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-box">
                            <div class="chart-toolbar">
                                <h4 style="margin:0;">Sales by Category</h4>
                                <label>Sort
                                    <select id="sortCategorySales" class="chart-sort-select" aria-label="Sort category chart">
                                        <option value="rev_desc">Revenue (high → low)</option>
                                        <option value="rev_asc">Revenue (low → high)</option>
                                        <option value="name_asc">Name (A–Z)</option>
                                        <option value="name_desc">Name (Z–A)</option>
                                    </select>
                                </label>
                            </div>
                            <div style="position:relative;height:260px;width:100%;min-height:220px;">
                                <canvas id="sellerCategoryChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- History -->
                <div class="trans-sub" id="trans-history">
                    <div class="trans-table-wrap">
                        <table class="trans-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th><th>Customer</th><th>Item</th><th>Payment</th><th>Amount</th><th>Date</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                <tr><td colspan="7" style="text-align:center;color:#777;padding:24px;">No orders yet.</td></tr>
                                <?php else: ?>
                                <?php foreach ($orders as $hOrder):
                                    $hItems = $orderItemsByOrder[(int) $hOrder['orderID']] ?? [];
                                    $hSummary = $hItems ? implode(', ', array_map(fn ($it) => ((int) $it['quantity']) . 'x ' . ($it['itemName'] ?? 'Item'), $hItems)) : ($hOrder['orderNote'] ?: '—');
                                    $hDate = $hOrder['orderedAt'] instanceof DateTimeInterface ? $hOrder['orderedAt']->format('M j, Y') : '';
                                    $hStatus = $hOrder['orderStatus'] ?? 'pending';
                                    $hStatusClass = $hStatus === 'cancelled' ? 'status-cancelled' : (in_array($hStatus, ['completed', 'ready'], true) ? 'status-delivered' : 'status-accepted');
                                    $hStatusLabel = $hStatus === 'ready' ? 'Ready' : ucfirst($hStatus);
                                ?>
                                <tr>
                                    <td><strong>#<?= str_pad((string) $hOrder['orderID'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><?= h($hOrder['userNAME'] ?? 'Customer') ?></td>
                                    <td><?= h($hSummary) ?></td>
                                    <td><?= h($hOrder['paymentMethod'] ?? '') ?></td>
                                    <td style="color:#0f7a4a;font-weight:800;"><?= money($hOrder['totalAmount']) ?></td>
                                    <td><?= h($hDate) ?></td>
                                    <td><span class="order-status <?= h($hStatusClass) ?>"><?= h($hStatusLabel) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Reviews -->
                <div class="trans-sub" id="trans-reviews">
                    <div class="reviews-header-row">
                        <div class="review-summary-card">
                            <div class="review-big-rating"><?= number_format($avgRating, 1) ?></div>
                            <div class="review-stars">out of 5</div>
                            <div class="review-count"><?= count($reviewRows) ?> reviews</div>
                        </div>
                        <div>
                            <div class="chart-toolbar" style="margin-bottom:8px;">
                                <span style="font-size:11px;font-weight:700;color:#5a9e7a;text-transform:uppercase;letter-spacing:0.5px;">Rating spread</span>
                                <label style="margin:0;">Sort
                                    <select id="sortRatingBars" class="chart-sort-select" aria-label="Sort rating bars">
                                        <option value="stars_desc">Stars (5 → 1)</option>
                                        <option value="stars_asc">Stars (1 → 5)</option>
                                        <option value="count_desc">Count (high → low)</option>
                                        <option value="count_asc">Count (low → high)</option>
                                    </select>
                                </label>
                            </div>
                            <div style="position:relative;height:200px;width:100%;max-width:420px;">
                                <canvas id="sellerRatingChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="scrollable">
                        <?php if (empty($reviewRows)): ?>
                        <p style="color:#777;padding:16px;">No reviews yet.</p>
                        <?php else: ?>
                        <?php foreach ($reviewRows as $rev):
                            $rPic = !empty($rev['userPROFILEPIC']) ? h($rev['userPROFILEPIC']) : 'https://ui-avatars.com/api/?name=' . rawurlencode($rev['userNAME'] ?? 'User');
                            $rDate = $rev['createdAt'] instanceof DateTimeInterface ? $rev['createdAt']->format('M j, Y') : '';
                            $rStars = str_repeat('⭐', (int) round((float) ($rev['rating'] ?? 0)));
                        ?>
                        <div class="review-card">
                            <div class="review-card-top">
                                <div class="reviewer"><img src="<?= $rPic ?>" alt="" onerror="this.style.background='#d0ede0'"><div class="reviewer-info"><strong><?= h($rev['userNAME'] ?? 'Customer') ?></strong><span><?= h($rDate) ?></span></div></div>
                                <div style="display:flex;align-items:center;gap:10px;"><span class="review-stars-sm"><?= $rStars ?></span></div>
                            </div>
                            <p><?= h($rev['reviewText'] ?? '') ?></p>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== SETTINGS PAGE — CHANGE 1: sidebar nav layout ========== -->
        <div class="page-view <?= $activePage === 'settings-page' ? 'active' : '' ?>" id="settings-page">
            <div class="page-card" style="padding:0;">

                <div class="settings-layout">

                    <!-- Left nav -->
                    <div class="settings-nav">
                        <div class="settings-nav-item active" onclick="setSettingsPanel(this,'panel-account')">
                            <i class="bi bi-person-circle"></i> Account
                        </div>
                        <div class="settings-nav-item" onclick="setSettingsPanel(this,'panel-notifications')">
                            <i class="bi bi-bell"></i> Notifications
                        </div>
                        <div class="settings-nav-item" onclick="setSettingsPanel(this,'panel-privacy')">
                            <i class="bi bi-shield-lock"></i> Privacy
                        </div>
                        <div class="settings-nav-item" onclick="setSettingsPanel(this,'panel-store')">
                            <i class="bi bi-shop"></i> Store Info
                        </div>
                        <div class="settings-nav-item" onclick="setSettingsPanel(this,'panel-about')">
                            <i class="bi bi-info-circle"></i> About
                        </div>
                    </div>

                    <!-- Right content -->
                    <div class="settings-content">

                        <!-- Account panel -->
                        <div class="settings-panel active" id="panel-account">
                            <div>
                                <div class="settings-panel-title">Account Settings</div>
                                <div class="settings-panel-subtitle">Manage your personal info and security</div>
                            </div>
                            <div class="settings-divider">PERSONAL INFORMATION</div>
                            <form class="settings-section" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save_profile">
                                <div class="avatar-upload">
                                    <img src="<?= $profilePic ?>" alt="" onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($displayName) ?>&background=d0ede0&color=0f7a4a'">
                                    <div>
                                        <label class="upload-btn">Change Photo <input type="file" name="profilePic" accept="image/*" hidden></label>
                                        <div style="font-size:11px;color:#aaa;margin-top:5px;">JPG or PNG, max 2MB</div>
                                    </div>
                                </div>
                                <div class="settings-field"><label>Full Name</label><input type="text" name="userName" value="<?= h($user['userNAME'] ?? '') ?>"></div>
                                <div class="settings-field"><label>Email</label><input type="email" name="userEmail" value="<?= h($user['userEMAIL'] ?? '') ?>"></div>
                                <div class="settings-field"><label>Phone</label><input type="text" name="userPhone" value="<?= h($user['userPHONE'] ?? '') ?>"></div>
                                <button class="save-btn" type="submit">Save Profile</button>
                            </form>
                            <div class="settings-divider">SECURITY</div>
                            <form class="settings-section" method="POST">
                                <input type="hidden" name="action" value="update_password">
                                <div class="settings-field"><label>Current Password</label><input type="password" name="currentPassword" placeholder="••••••••"></div>
                                <div class="settings-field"><label>New Password</label><input type="password" name="newPassword" placeholder="••••••••"></div>
                                <div class="settings-field"><label>Confirm Password</label><input type="password" name="confirmPassword" placeholder="••••••••"></div>
                                <button class="save-btn" type="submit">Update Password</button>
                            </form>
                        </div>

                        <!-- Notifications panel -->
                        <div class="settings-panel" id="panel-notifications">
                            <div>
                                <div class="settings-panel-title">Notifications</div>
                                <div class="settings-panel-subtitle">Control how you receive alerts and updates</div>
                            </div>
                            <div class="settings-divider">PREFERENCES</div>
                            <div class="settings-section">
                                <div class="toggle-row"><span>Order Notifications</span><button type="button" class="toggle-switch on" data-pref="notify_orders" onclick="this.classList.toggle('on')"></button></div>
                                <div class="toggle-row"><span>SMS Alerts</span><button type="button" class="toggle-switch on" data-pref="notify_sms" onclick="this.classList.toggle('on')"></button></div>
                                <div class="toggle-row"><span>Auto-accept Orders</span><button type="button" class="toggle-switch" data-pref="auto_accept" onclick="this.classList.toggle('on')"></button></div>
                                <div class="toggle-row"><span>New Review Alerts</span><button type="button" class="toggle-switch on" data-pref="notify_reviews" onclick="this.classList.toggle('on')"></button></div>
                                <div class="toggle-row"><span>Promotional Emails</span><button type="button" class="toggle-switch" data-pref="notify_promo" onclick="this.classList.toggle('on')"></button></div>
                                <button type="button" class="save-btn" id="btnSaveNotifPrefs" onclick="sellerSaveNotifPrefs()">Save Preferences</button>
                            </div>
                        </div>

                        <!-- Privacy panel -->
                        <div class="settings-panel" id="panel-privacy">
                            <div>
                                <div class="settings-panel-title">Privacy</div>
                                <div class="settings-panel-subtitle">Manage your store visibility and data</div>
                            </div>
                            <div class="settings-divider">VISIBILITY</div>
                            <div class="settings-section">
                                <div class="toggle-row"><span>Store Visible to Buyers</span><button type="button" class="toggle-switch on" data-prefpriv="store_visible" onclick="this.classList.toggle('on')"></button></div>
                                <div class="toggle-row"><span>Delivery Available</span><button type="button" class="toggle-switch on" data-prefpriv="delivery" onclick="this.classList.toggle('on')"></button></div>
                                <div class="toggle-row"><span>Show Reviews Publicly</span><button type="button" class="toggle-switch on" data-prefpriv="reviews_public" onclick="this.classList.toggle('on')"></button></div>
                            </div>
                            <div class="settings-divider">DATA</div>
                            <div class="settings-section">
                                <div style="font-size:13px;color:#666;line-height:1.7;">Your data is stored securely and used only to operate your seller account on ANIMEALS. You may request account deletion at any time.</div>
                                <button type="button" class="save-btn" style="background:linear-gradient(135deg,#dc3545,#ff6b6b);box-shadow:0 6px 18px rgba(220,53,69,0.3);" onclick="sellerRequestDataDeletion()">Request Data Deletion</button>
                                <button type="button" class="save-btn" id="btnSavePrivacyPrefs" onclick="sellerSavePrivacyPrefs()">Save privacy settings</button>
                            </div>
                        </div>

                        <!-- Store Info panel -->
                        <div class="settings-panel" id="panel-store">
                            <div>
                                <div class="settings-panel-title">Store Info</div>
                                <div class="settings-panel-subtitle">Update your store details visible to buyers</div>
                            </div>
                            <div class="settings-divider">STORE DETAILS</div>
                            <form class="settings-section" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save_store">
                                <div class="settings-field"><label>Store Name</label><input type="text" name="storeName" value="<?= h($shop['shopName'] ?? '') ?>"></div>
                                <div class="settings-field"><label>Store Logo</label><input type="file" name="storeLogo" accept="image/*"></div>
                                <div class="settings-field"><label>Store type (student menu — sorting &amp; labels)</label>
                                    <?php $curType = trim((string) ($shop['shopType'] ?? '')); ?>
                                    <select name="storeType">
                                        <option value="" <?= $curType === '' ? 'selected' : '' ?>>— Not set —</option>
                                        <?php
                                        $typeOpts = ['Food & drinks', 'Snacks & convenience', 'Groceries', 'School supplies & merch', 'Services', 'Mixed / other'];
                                        foreach ($typeOpts as $opt) {
                                            $sel = strcasecmp($curType, $opt) === 0 ? ' selected' : '';
                                            echo '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="settings-field"><label>Store category (shown in description)</label>
                                    <select name="storeCategory">
                                        <option value="">— Select —</option>
                                        <option>Snacks &amp; Fries</option>
                                        <option>Rice meals</option>
                                        <option>Beverages</option>
                                        <option>Desserts</option>
                                        <option>Combos</option>
                                        <option>Other</option>
                                    </select>
                                </div>
                                <div class="settings-field"><label>Store Description</label><textarea name="storeDescription"><?= h($shop['shopDescription'] ?? '') ?></textarea></div>
                                <div class="settings-divider" style="margin:6px 0;">STORE LOCATION</div>
                                <div class="settings-field">
                                    <label>Store Address / Landmark</label>
                                    <input type="text" id="sellerStoreAddress" name="storeAddress" value="<?= h($shop['shopADDRESS'] ?? '') ?>" placeholder="e.g. Near main gate, beside library">
                                </div>
                                <input type="hidden" id="sellerStoreLat" name="storeLat" value="<?= h($shop['shopLAT'] ?? '') ?>">
                                <input type="hidden" id="sellerStoreLng" name="storeLng" value="<?= h($shop['shopLNG'] ?? '') ?>">
                                <div id="sellerStoreMap" class="seller-map-picker"></div>
                                <div class="seller-map-hint">Tap the map to pin your store location. Students will see a route line from their checkout location to this pin.</div>
                                <button type="button" class="save-btn" style="background:#f0faf7;color:#0f7a4a;box-shadow:none;border:1.5px solid #d0ede0;" onclick="sellerUseCurrentLocation()">Use my current location</button>
                                <button class="save-btn" type="submit">Save Store Info</button>
                            </form>
                        </div>

                        <!-- About panel -->
                        <div class="settings-panel" id="panel-about">
                            <div>
                                <div class="settings-panel-title">About</div>
                                <div class="settings-panel-subtitle">App information and support</div>
                            </div>
                            <div class="settings-section" style="gap:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;">
                                    <span style="color:#666;font-weight:600;">App Version</span>
                                    <span style="font-weight:700;color:#0f7a4a;">v2.4.1</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;">
                                    <span style="color:#666;font-weight:600;">Platform</span>
                                    <span style="font-weight:700;color:#333;">ANIMEALS Seller</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;">
                                    <span style="color:#666;font-weight:600;">Support Email</span>
                                    <span style="font-weight:700;color:#0f7a4a;">support@animeals.ph</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;">
                                    <span style="color:#666;font-weight:600;">Last Updated</span>
                                    <span style="font-weight:700;color:#333;">May 1, 2026</span>
                                </div>
                            </div>
                            <div class="settings-section" style="gap:10px;">
                                <button type="button" class="save-btn" style="background:#f0faf7;color:#0f7a4a;box-shadow:none;border:1.5px solid #d0ede0;" onclick="sellerShowTerms()">📄 Terms &amp; Conditions</button>
                                <button type="button" class="save-btn" style="background:#f0faf7;color:#0f7a4a;box-shadow:none;border:1.5px solid #d0ede0;" onclick="sellerShowPrivacyPolicy()">🔒 Privacy Policy</button>
                                <button type="button" class="save-btn" style="background:#f0faf7;color:#0f7a4a;box-shadow:none;border:1.5px solid #d0ede0;" onclick="sellerContactSupport()">📬 Contact Support</button>
                            </div>
                        </div>

                    </div><!-- /settings-content -->
                </div><!-- /settings-layout -->
            </div>
        </div>

    </div><!-- /main -->
</div><!-- /dashboard-container -->

<div class="seller-text-modal-overlay" id="seller-text-modal" onclick="if(event.target===this)sellerCloseTextModal()">
    <div class="seller-text-modal" onclick="event.stopPropagation()">
        <button type="button" class="close-x" onclick="sellerCloseTextModal()" aria-label="Close">&times;</button>
        <h3 id="seller-text-modal-title">Info</h3>
        <div id="seller-text-modal-body"></div>
        <button type="button" class="save-btn" style="margin-top:14px;width:100%;" onclick="sellerCloseTextModal()">Close</button>
    </div>
</div>

<div class="seller-delivery-map-modal" id="seller-delivery-map-modal" onclick="if(event.target===this)sellerCloseDeliveryMap()">
    <div class="seller-delivery-map-card" onclick="event.stopPropagation()">
        <div class="seller-delivery-map-head">
            <div>
                <h3 id="sellerDeliveryMapTitle">Delivery map</h3>
                <p id="sellerDeliveryMapText">Seller and student locations</p>
            </div>
            <button type="button" class="seller-delivery-map-close" onclick="sellerCloseDeliveryMap()" aria-label="Close">&times;</button>
        </div>
        <div id="sellerDeliveryMap"></div>
        <div class="seller-delivery-map-legend">
            <span><i class="seller-delivery-map-dot" style="background:#0f7a4a;"></i>Seller location</span>
            <span><i class="seller-delivery-map-dot" style="background:#2563eb;"></i>Student location</span>
            <span><i class="seller-delivery-map-dot" style="background:#f59e0b;"></i>Route line</span>
        </div>
    </div>
</div>

<!-- ========== CHANGE 4: Add Item Modal ========== -->
<div class="modal-overlay" id="add-item-modal" onclick="handleModalOverlayClick(event)">
    <form class="modal-box" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_menu_item">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-plus-circle-fill" style="margin-right:8px;font-size:18px;"></i>Add New Item</span>
            <button class="modal-close" onclick="closeAddItemModal()"><i class="bi bi-x"></i></button>
        </div>

        <!-- Image upload -->
        <div class="img-upload-area" id="img-upload-area" onclick="document.getElementById('img-file-input').click()">
            <img class="img-upload-preview" id="img-preview" alt="preview">
            <i class="bi bi-image" id="upload-icon"></i>
            <span id="upload-label">Click to upload item photo</span>
            <small id="upload-sub">PNG, JPG — max 5MB</small>
        </div>
        <input type="file" id="img-file-input" name="itemImage" accept="image/*" style="display:none" onchange="previewImage(this)">

        <div class="modal-field">
            <label>Item Name</label>
            <input type="text" name="itemName" placeholder="e.g. Tera Fries" required>
        </div>

        <div class="modal-row">
            <div class="modal-field" style="margin-bottom:0;">
                <label>Category</label>
                <select name="itemCategory">
                    <option value="">Select category</option>
                    <option>Fries</option>
                    <option>Drinks</option>
                    <option>Snacks</option>
                    <option>Combos</option>
                </select>
            </div>
            <div class="modal-field" style="margin-bottom:0;">
                <label>Price (₱)</label>
                <input type="number" name="itemPrice" placeholder="0.00" min="0" step="0.01" required>
            </div>
        </div>

        <div class="modal-field" style="margin-top:14px;">
            <label>Flavors / Variants <span style="color:#bbb;font-weight:500;text-transform:none;letter-spacing:0;">(press Enter to add)</span></label>
            <div class="tag-input-wrap" id="tag-wrap" onclick="document.getElementById('tag-input').focus()">
                <div class="tag-chip">Classic <button onclick="removeTag(this)">×</button></div>
                <div class="tag-chip">Cheese <button onclick="removeTag(this)">×</button></div>
                <input class="tag-input" id="tag-input" placeholder="Add flavor…" onkeydown="handleTagInput(event)">
            </div>
        </div>

        <div class="modal-field">
            <label>Description <span style="color:#bbb;font-weight:500;text-transform:none;letter-spacing:0;">(optional)</span></label>
            <input type="hidden" name="itemVariants" id="item-variants"><textarea name="itemDescription" placeholder="Describe this item..."></textarea>
        </div>

        <div class="modal-field">
            <label>Availability</label>
            <div style="display:flex;align-items:center;gap:12px;margin-top:4px;">
                <button class="toggle-switch on" id="avail-toggle" type="button" onclick="this.classList.toggle('on');document.getElementById('is-available-input').checked=this.classList.contains('on')"></button><input type="checkbox" name="isAvailable" id="is-available-input" checked hidden>
                <span style="font-size:13px;font-weight:600;color:#555;">Available for ordering</span>
            </div>
        </div>

        <div class="modal-footer">
            <button class="modal-cancel-btn" type="button" onclick="closeAddItemModal()">Cancel</button>
            <button class="modal-submit-btn" type="submit" onclick="syncVariants()"><i class="bi bi-check-lg" style="margin-right:6px;"></i>Add to Menu</button>
        </div>
    </form>
</div>

<script>
    const SELLER_STORE_LOCATION = {
        lat: <?= json_encode(isset($shop['shopLAT']) && $shop['shopLAT'] !== null ? (float) $shop['shopLAT'] : null) ?>,
        lng: <?= json_encode(isset($shop['shopLNG']) && $shop['shopLNG'] !== null ? (float) $shop['shopLNG'] : null) ?>,
        address: <?= json_encode((string) ($shop['shopADDRESS'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        name: <?= json_encode((string) ($shop['shopName'] ?? 'Your shop'), JSON_UNESCAPED_UNICODE) ?>
    };
    let sellerStoreMap = null;
    let sellerStoreMarker = null;
    let sellerDeliveryMap = null;

    function initSellerStoreMap() {
        if (!window.L || sellerStoreMap || !document.getElementById('sellerStoreMap')) return;
        const start = (SELLER_STORE_LOCATION.lat && SELLER_STORE_LOCATION.lng)
            ? [SELLER_STORE_LOCATION.lat, SELLER_STORE_LOCATION.lng]
            : [14.5995, 120.9842];
        sellerStoreMap = L.map('sellerStoreMap').setView(start, 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(sellerStoreMap);
        sellerStoreMap.on('click', function (e) {
            sellerSetStoreLocation(e.latlng.lat, e.latlng.lng);
        });
        if (SELLER_STORE_LOCATION.lat && SELLER_STORE_LOCATION.lng) {
            sellerSetStoreLocation(SELLER_STORE_LOCATION.lat, SELLER_STORE_LOCATION.lng);
        }
        setTimeout(function () { sellerStoreMap.invalidateSize(); }, 250);
    }

    function sellerSetStoreLocation(lat, lng) {
        document.getElementById('sellerStoreLat').value = Number(lat).toFixed(8);
        document.getElementById('sellerStoreLng').value = Number(lng).toFixed(8);
        if (!sellerStoreMap) return;
        const point = [lat, lng];
        if (!sellerStoreMarker) {
            sellerStoreMarker = L.marker(point, { draggable: true }).addTo(sellerStoreMap);
            sellerStoreMarker.on('dragend', function () {
                const p = sellerStoreMarker.getLatLng();
                sellerSetStoreLocation(p.lat, p.lng);
            });
        } else {
            sellerStoreMarker.setLatLng(point);
        }
        sellerStoreMap.setView(point, Math.max(sellerStoreMap.getZoom(), 16));
    }

    function sellerUseCurrentLocation() {
        initSellerStoreMap();
        if (!navigator.geolocation) return alert('Location is not available in this browser.');
        navigator.geolocation.getCurrentPosition(function (pos) {
            sellerSetStoreLocation(pos.coords.latitude, pos.coords.longitude);
        }, function () {
            alert('Could not get your location. You can tap the map instead.');
        }, { enableHighAccuracy: true, timeout: 10000 });
    }

    function sellerCloseDeliveryMap() {
        const modal = document.getElementById('seller-delivery-map-modal');
        if (modal) modal.classList.remove('open');
        if (sellerDeliveryMap) {
            sellerDeliveryMap.remove();
            sellerDeliveryMap = null;
        }
    }

    function sellerOpenDeliveryMap(order) {
        if (!window.L) {
            alert('Map tools are still loading. Please try again in a moment.');
            return;
        }
        const studentLat = parseFloat(order.studentLat);
        const studentLng = parseFloat(order.studentLng);
        const sellerLat = parseFloat(SELLER_STORE_LOCATION.lat);
        const sellerLng = parseFloat(SELLER_STORE_LOCATION.lng);
        if (!Number.isFinite(studentLat) || !Number.isFinite(studentLng)) {
            alert('This order does not have a pinned student location yet.');
            return;
        }

        const modal = document.getElementById('seller-delivery-map-modal');
        const title = document.getElementById('sellerDeliveryMapTitle');
        const text = document.getElementById('sellerDeliveryMapText');
        if (!modal) return;

        title.textContent = 'Order #' + String(order.orderID || '').padStart(4, '0') + ' delivery map';
        text.textContent = (SELLER_STORE_LOCATION.address || SELLER_STORE_LOCATION.name || 'Seller location') + ' to ' + (order.studentAddress || order.studentName || 'Student location');
        modal.classList.add('open');

        if (sellerDeliveryMap) {
            sellerDeliveryMap.remove();
            sellerDeliveryMap = null;
        }

        setTimeout(function () {
            const studentPoint = [studentLat, studentLng];
            const hasSellerPoint = Number.isFinite(sellerLat) && Number.isFinite(sellerLng);
            const sellerPoint = hasSellerPoint ? [sellerLat, sellerLng] : studentPoint;
            sellerDeliveryMap = L.map('sellerDeliveryMap', { zoomControl: true, attributionControl: false }).setView(studentPoint, 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(sellerDeliveryMap);
            if (hasSellerPoint) {
                L.marker(sellerPoint).addTo(sellerDeliveryMap).bindPopup(SELLER_STORE_LOCATION.address || SELLER_STORE_LOCATION.name || 'Seller location');
                L.polyline([sellerPoint, studentPoint], { color: '#f59e0b', weight: 5, opacity: 0.9 }).addTo(sellerDeliveryMap);
            }
            L.marker(studentPoint).addTo(sellerDeliveryMap).bindPopup(order.studentAddress || order.studentName || 'Student location');
            if (hasSellerPoint) {
                sellerDeliveryMap.fitBounds([sellerPoint, studentPoint], { padding: [36, 36] });
            }
            sellerDeliveryMap.invalidateSize();
        }, 120);
    }

    function sellerBindDeliveryMapButtons() {
        document.querySelectorAll('[data-order-map="1"]').forEach(function (button) {
            if (button.dataset.boundMap === '1') return;
            button.dataset.boundMap = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                sellerOpenDeliveryMap({
                    orderID: button.dataset.orderId || '',
                    studentName: button.dataset.studentName || 'Customer',
                    studentLat: button.dataset.studentLat || '',
                    studentLng: button.dataset.studentLng || '',
                    studentAddress: button.dataset.studentAddress || 'Pinned delivery location'
                });
            });
        });
    }

    /* ===== ORDERS DATA (JS filter for PHP-rendered cards) ===== */
    let currentOrderFilter = 'all';
    function setOrderTab(el, filter) {
        document.querySelectorAll('.otab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        currentOrderFilter = filter;
        renderOrders();
    }

    function renderOrders() {
        const needle = ((document.getElementById('sellerGlobalSearch') || {}).value || '').trim().toLowerCase();
        document.querySelectorAll('#orders-list-full .full-order-card').forEach(function (card) {
            const st = (card.dataset.status || '').toLowerCase();
            const statusOk = currentOrderFilter === 'all' || st === currentOrderFilter;
            const textOk = !needle || card.innerText.toLowerCase().indexOf(needle) !== -1;
            card.style.display = statusOk && textOk ? 'flex' : 'none';
        });
    }

    /* ===== NAVIGATION ===== */
    function navigate(pageId, title, clickedEl, isSettings = false) {
        document.querySelectorAll('.page-view').forEach(p => p.classList.remove('active'));
        document.getElementById(pageId).classList.add('active');

        document.querySelectorAll('.nav-menu a').forEach(a => a.classList.remove('active'));
        document.querySelectorAll('.settings-link').forEach(a => a.classList.remove('active'));

        if (isSettings) {
            document.querySelectorAll('.settings-link').forEach(a => a.classList.add('active'));
        } else if (clickedEl) {
            const anchor = clickedEl.closest ? (clickedEl.closest('.nav-menu a') || clickedEl) : clickedEl;
            if (anchor && anchor.classList) anchor.classList.add('active');
        }

        document.getElementById('page-title').textContent = title;
        if (pageId === 'orders-page') renderOrders();
        if (pageId === 'transaction-page' && typeof sellerChartsResize === 'function') sellerChartsResize();
    }

    /* ===== TRANSACTION TABS ===== */
    function setTransTab(el, sub) {
        document.querySelectorAll('.ttab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('.trans-sub').forEach(s => s.classList.remove('active'));
        document.getElementById('trans-' + sub).classList.add('active');
        if (typeof sellerChartsResize === 'function') sellerChartsResize();
    }

    /* ===== MENU FILTERS ===== */
    var sellerMenuCatMode = '';
    function setFilter(el) {
        const wrap = document.getElementById('menu-filter-chips');
        if (wrap) wrap.querySelectorAll('.filter-chip').forEach(function (c) { c.classList.remove('active'); });
        el.classList.add('active');
        const val = el.getAttribute('data-cat');
        sellerMenuCatMode = val === null ? '' : val;
        sellerApplyMenuVisibility();
    }

    function sellerMenuCardCategoryMatch(card) {
        const cat = (card.getAttribute('data-category') || '').trim().toLowerCase();
        if (sellerMenuCatMode === '') return true;
        if (sellerMenuCatMode === '__other__') return cat === 'other' || cat === '';
        return cat === String(sellerMenuCatMode).trim().toLowerCase();
    }

    function sellerApplyMenuVisibility() {
        const needle = ((document.getElementById('sellerGlobalSearch') || {}).value || '').trim().toLowerCase();
        const hit = function (text) { return !needle || (text || '').toLowerCase().indexOf(needle) !== -1; };
        document.querySelectorAll('.menu-item-card').forEach(function (card) {
            card.style.display = hit(card.innerText) && sellerMenuCardCategoryMatch(card) ? 'block' : 'none';
        });
    }

    /* ===== SETTINGS PANELS ===== */
    function setSettingsPanel(el, panelId) {
        document.querySelectorAll('.settings-nav-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(panelId).classList.add('active');
        if (panelId === 'panel-store') {
            setTimeout(function () {
                initSellerStoreMap();
                if (sellerStoreMap) sellerStoreMap.invalidateSize();
            }, 80);
        }
    }

    /* ===== ADD ITEM MODAL ===== */
    function openAddItemModal() {
        document.getElementById('add-item-modal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeAddItemModal() {
        document.getElementById('add-item-modal').classList.remove('open');
        document.body.style.overflow = '';
    }
    function handleModalOverlayClick(e) {
        if (e.target === document.getElementById('add-item-modal')) closeAddItemModal();
    }

    /* Image preview */
    function previewImage(input) {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('img-preview');
            preview.src = e.target.result;
            preview.style.display = 'block';
            document.getElementById('upload-icon').style.display = 'none';
            document.getElementById('upload-label').style.display = 'none';
            document.getElementById('upload-sub').style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    /* Tag/flavor chips */
    function handleTagInput(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const input = document.getElementById('tag-input');
            const val = input.value.trim();
            if (!val) return;
            const chip = document.createElement('div');
            chip.className = 'tag-chip';
            chip.innerHTML = `${val} <button onclick="removeTag(this)">×</button>`;
            document.getElementById('tag-wrap').insertBefore(chip, input);
            input.value = '';
        }
    }
    function removeTag(btn) {
        btn.parentElement.remove();
    }

    function submitAddItem() {
        syncVariants();
        closeAddItemModal();
    }

    function syncVariants() {
        const hidden = document.getElementById('item-variants');
        if (!hidden) return;
        hidden.value = Array.from(document.querySelectorAll('#tag-wrap .tag-chip'))
            .map((chip) => chip.childNodes[0].textContent.trim())
            .filter(Boolean)
            .join(', ');
    }

    /* ===== Analytics charts (Chart.js) ===== */
    const SELLER_WEEKLY = <?= json_encode($weeklySeries, JSON_UNESCAPED_UNICODE) ?>;
    const SELLER_CATEGORIES = <?= json_encode($categoryChartData, JSON_UNESCAPED_UNICODE) ?>;
    const SELLER_CAT_TOTAL = <?= json_encode((float) $categoryTotalRev) ?>;
    const SELLER_RATING_ROWS = <?= json_encode($ratingChartData, JSON_UNESCAPED_UNICODE) ?>;
    const SELLER_RATING_RC = <?= (int) max(1, $ratingReviewCount) ?>;
    const CAT_COLORS = ['#0f7a4a', '#1faa6c', '#6ab890', '#2d8a5e', '#5cbf7a', '#8fd4a6'];

    function sortWeeklyCopy(mode) {
        const rows = SELLER_WEEKLY.map(function (r) { return Object.assign({}, r); });
        if (mode === 'date_desc') rows.sort(function (a, b) { return b.date.localeCompare(a.date); });
        else if (mode === 'date_asc') rows.sort(function (a, b) { return a.date.localeCompare(b.date); });
        else if (mode === 'rev_desc') rows.sort(function (a, b) { return b.revenue - a.revenue; });
        else if (mode === 'rev_asc') rows.sort(function (a, b) { return a.revenue - b.revenue; });
        return rows;
    }

    function sortCategoryCopy(mode) {
        const rows = SELLER_CATEGORIES.map(function (r) { return Object.assign({}, r); });
        if (mode === 'rev_desc') rows.sort(function (a, b) { return b.revenue - a.revenue; });
        else if (mode === 'rev_asc') rows.sort(function (a, b) { return a.revenue - b.revenue; });
        else if (mode === 'name_asc') rows.sort(function (a, b) { return a.name.localeCompare(b.name); });
        else if (mode === 'name_desc') rows.sort(function (a, b) { return b.name.localeCompare(a.name); });
        return rows;
    }

    function sortRatingCopy(mode) {
        const rows = SELLER_RATING_ROWS.map(function (r) { return Object.assign({}, r); });
        if (mode === 'stars_asc') rows.sort(function (a, b) { return a.stars - b.stars; });
        else if (mode === 'stars_desc') rows.sort(function (a, b) { return b.stars - a.stars; });
        else if (mode === 'count_desc') rows.sort(function (a, b) { return b.count - a.count; });
        else if (mode === 'count_asc') rows.sort(function (a, b) { return a.count - b.count; });
        return rows;
    }

    let sellerWeeklyChart = null;
    let sellerCategoryChart = null;
    let sellerRatingChart = null;

    function sellerChartsResize() {
        requestAnimationFrame(function () {
            try {
                if (sellerWeeklyChart) sellerWeeklyChart.resize();
                if (sellerCategoryChart) sellerCategoryChart.resize();
                if (sellerRatingChart) sellerRatingChart.resize();
            } catch (e) {}
        });
    }

    function buildWeeklyChart() {
        const el = document.getElementById('sellerWeeklyChart');
        if (!el || typeof Chart === 'undefined') return;
        const mode = (document.getElementById('sortWeeklySales') || {}).value || 'date_asc';
        const rows = sortWeeklyCopy(mode);
        if (sellerWeeklyChart) sellerWeeklyChart.destroy();
        sellerWeeklyChart = new Chart(el.getContext('2d'), {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    label: 'Completed / ready sales (₱)',
                    data: rows.map(function (r) { return r.revenue; }),
                    backgroundColor: '#1faa6c',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } },
                plugins: { legend: { display: false } }
            }
        });
    }

    function buildCategoryChart() {
        const el = document.getElementById('sellerCategoryChart');
        if (!el || typeof Chart === 'undefined') return;
        const mode = (document.getElementById('sortCategorySales') || {}).value || 'rev_desc';
        const rows = sortCategoryCopy(mode);
        if (sellerCategoryChart) sellerCategoryChart.destroy();
        if (!rows.length || SELLER_CAT_TOTAL <= 0) {
            sellerCategoryChart = new Chart(el.getContext('2d'), {
                type: 'doughnut',
                data: { labels: ['No data'], datasets: [{ data: [1], backgroundColor: ['#e2e8f0'] }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom' } } }
            });
            return;
        }
        const cols = rows.map(function (_, i) { return CAT_COLORS[i % CAT_COLORS.length]; });
        sellerCategoryChart = new Chart(el.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: rows.map(function (r) { return r.name; }),
                datasets: [{
                    data: rows.map(function (r) { return r.revenue; }),
                    backgroundColor: cols,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
            }
        });
    }

    function buildRatingChart() {
        const el = document.getElementById('sellerRatingChart');
        if (!el || typeof Chart === 'undefined') return;
        const mode = (document.getElementById('sortRatingBars') || {}).value || 'stars_desc';
        const rows = sortRatingCopy(mode);
        if (sellerRatingChart) sellerRatingChart.destroy();
        sellerRatingChart = new Chart(el.getContext('2d'), {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return r.stars + ' ★'; }),
                datasets: [{
                    label: 'Reviews',
                    data: rows.map(function (r) { return r.count; }),
                    backgroundColor: '#0f7a4a',
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        });
    }

    (function initSellerCharts() {
        const wk = document.getElementById('sortWeeklySales');
        const cat = document.getElementById('sortCategorySales');
        const rt = document.getElementById('sortRatingBars');
        buildWeeklyChart();
        buildCategoryChart();
        buildRatingChart();
        if (wk) wk.addEventListener('change', buildWeeklyChart);
        if (cat) cat.addEventListener('change', buildCategoryChart);
        if (rt) rt.addEventListener('change', buildRatingChart);
    })();

    function sellerOpenReviewsTab() {
        navigate('transaction-page', 'TRANSACTION', document.getElementById('nav-transaction-page'));
        const tab = document.getElementById('ttab-reviews');
        if (tab) setTransTab(tab, 'reviews');
    }

    function sellerGlobalFilter(q) {
        const needle = (q || '').trim().toLowerCase();
        const dash = document.getElementById('dashboard');
        const menu = document.getElementById('menu-page');
        const orders = document.getElementById('orders-page');
        const hit = function (text) { return !needle || (text || '').toLowerCase().indexOf(needle) !== -1; };
        if (dash && dash.classList.contains('active')) {
            document.querySelectorAll('.order-card').forEach(function (card) {
                card.style.display = hit(card.innerText) ? '' : 'none';
            });
        }
        if (menu && menu.classList.contains('active')) {
            sellerApplyMenuVisibility();
        }
        if (orders && orders.classList.contains('active')) {
            renderOrders();
        }
    }

    var SELLER_PREFS_KEY = 'animeals_seller_prefs_v1';
    function sellerLoadPrefs() {
        try {
            var raw = localStorage.getItem(SELLER_PREFS_KEY);
            if (!raw) return;
            var o = JSON.parse(raw);
            if (o.notify) {
                Object.keys(o.notify).forEach(function (k) {
                    var b = document.querySelector('[data-pref="' + k + '"]');
                    if (b) b.classList.toggle('on', !!o.notify[k]);
                });
            }
            if (o.privacy) {
                Object.keys(o.privacy).forEach(function (k) {
                    var b = document.querySelector('[data-prefpriv="' + k + '"]');
                    if (b) b.classList.toggle('on', !!o.privacy[k]);
                });
            }
        } catch (e) {}
    }
    function sellerSaveNotifPrefs() {
        var notify = {};
        document.querySelectorAll('[data-pref]').forEach(function (b) {
            notify[b.getAttribute('data-pref')] = b.classList.contains('on');
        });
        try {
            var cur = JSON.parse(localStorage.getItem(SELLER_PREFS_KEY) || '{}');
            cur.notify = notify;
            localStorage.setItem(SELLER_PREFS_KEY, JSON.stringify(cur));
            alert('Notification preferences saved on this device.');
        } catch (e) { alert('Could not save preferences.'); }
    }
    function sellerSavePrivacyPrefs() {
        var privacy = {};
        document.querySelectorAll('[data-prefpriv]').forEach(function (b) {
            privacy[b.getAttribute('data-prefpriv')] = b.classList.contains('on');
        });
        try {
            var cur = JSON.parse(localStorage.getItem(SELLER_PREFS_KEY) || '{}');
            cur.privacy = privacy;
            localStorage.setItem(SELLER_PREFS_KEY, JSON.stringify(cur));
            alert('Privacy preferences saved on this device.');
        } catch (e) { alert('Could not save.'); }
    }
    function sellerShowText(title, html) {
        var ov = document.getElementById('seller-text-modal');
        if (!ov) return;
        document.getElementById('seller-text-modal-title').textContent = title;
        document.getElementById('seller-text-modal-body').innerHTML = html;
        ov.classList.add('open');
    }
    function sellerCloseTextModal() {
        var ov = document.getElementById('seller-text-modal');
        if (ov) ov.classList.remove('open');
    }
    function sellerShowTerms() {
        sellerShowText('Terms & Conditions', '<p>By using ANIMEALS Seller you agree to operate lawfully, keep menu prices accurate, fulfill or cancel orders promptly, and comply with campus and local food regulations.</p><p>ANIMEALS may suspend accounts that receive repeated complaints or policy violations.</p>');
    }
    function sellerShowPrivacyPolicy() {
        sellerShowText('Privacy Policy', '<p>We collect account, shop, order, and payout-related data needed to run the marketplace. Payment details are handled according to your chosen methods.</p><p>Preferences saved in this browser (notifications / privacy toggles) stay on this device until cleared.</p>');
    }
    function sellerContactSupport() {
        window.location.href = 'mailto:support@animeals.ph?subject=ANIMEALS%20Seller%20Support';
    }
    function sellerRequestDataDeletion() {
        if (!confirm('This will open an email to request account data deletion. Continue?')) return;
        var em = <?= json_encode($user['userEMAIL'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
        var body = 'Please delete my seller account and associated data. Registered email: ' + em;
        window.location.href = 'mailto:support@animeals.ph?subject=' + encodeURIComponent('Data deletion request') + '&body=' + encodeURIComponent(body);
    }

    /* ===== New checkouts: refresh when order list changes ===== */
    (function () {
        const sig = <?= json_encode($orderPollSig) ?>;
        if (!sig) return;
        let last = sig;
        setInterval(async function () {
            try {
                const r = await fetch('seller.php?ajax=orders_poll', { credentials: 'same-origin', cache: 'no-store' });
                if (!r.ok) return;
                const j = await r.json();
                if (j.sig && j.sig !== last) {
                    last = j.sig;
                    if (document.visibilityState === 'visible') {
                        location.reload();
                    }
                }
            } catch (e) {}
        }, 13000);
    })();

    document.addEventListener('DOMContentLoaded', function () {
        sellerLoadPrefs();
        initSellerStoreMap();
        sellerBindDeliveryMapButtons();
        document.querySelectorAll('#tag-wrap button').forEach(function (button) {
            button.type = 'button';
        });
        currentOrderFilter = 'all';
        renderOrders();
        if (document.getElementById('transaction-page') && document.getElementById('transaction-page').classList.contains('active')) {
            sellerChartsResize();
        }
    });
</script>
</body>
</html>

