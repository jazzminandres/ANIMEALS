<?php
// THIS FILE RUNS THE STUDENT DASHBOARD, FEED, FOOD ORDERING, MAP CHOOSER, CART, AND CHECKOUT FLOW.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// STRIP ?ORDERED=1 FROM OLD LINKS AND SHOW THE SUCCESS TOAST ONCE THROUGH SESSION.
if (array_key_exists('ordered', $_GET)) {
    $_SESSION['checkout_success'] = true;
    header('Location: student.php');
    exit();
}

// CONNECT TO ANIMEALS FOR USERS/CART/ORDERS AND SELLER_DATA FOR SHOPS/MENUS.
$conn = db_connect(DB_NAME_ANIMEALS);
$connSeller = db_connect(DB_NAME_SELLER_DATA);

require_once __DIR__ . '/schema_bootstrap.php';
// MAKE SURE CHECKOUT NOTES, REVIEWS, AND SELLER SHOP TYPES EXIST BEFORE DASHBOARD ACTIONS.
animeals_ensure_extensions($conn);
if ($connSeller) {
    seller_data_ensure_shop_type($connSeller);
}

// LOAD THE CURRENT STUDENT PROFILE FROM THE SESSION EMAIL.
$stmt = mysqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$_SESSION['email']]);
if ($stmt === false || !($user = mysqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
    session_destroy();
    header('Location: index.php');
    exit();
}
$studentID = (int) ($user['userID'] ?? 0);

$checkoutToast = !empty($_SESSION['checkout_success']);
$checkoutMessage = '';
if ($checkoutToast) {
    // CONSUME THE CHECKOUT MESSAGE SO REFRESHING THE PAGE DOES NOT REPLAY THE TOAST.
    $checkoutMessage = trim((string) ($_SESSION['checkout_message'] ?? '')) ?: 'Order placed successfully. Thank you!';
    unset($_SESSION['checkout_success'], $_SESSION['checkout_message']);
}

$profilePic = !empty($user['userPROFILEPIC'])
    ? htmlspecialchars($user['userPROFILEPIC'])
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';

/* -- AJAX: Add to cart -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'addToCart') {
    // ADD THE REQUESTED QUANTITY TO THE STUDENT CART OR INCREASE IT IF IT IS ALREADY THERE.
    $itemID = (int) $_POST['itemID'];
    $shopID = (int) $_POST['shopID'];
    $quantity = max(1, min(20, (int) ($_POST['quantity'] ?? 1)));
    $lineNote = trim((string) ($_POST['lineNote'] ?? ''));

    $check = db_query($conn, "SELECT cartID, quantity FROM cart WHERE studentID = ? AND itemID = ?", [$studentID, $itemID]);
    $existing = $check ? db_fetch_assoc($check) : null;

    if ($existing) {
        if ($lineNote !== '') {
            db_query($conn, "UPDATE cart SET quantity = quantity + ?, lineNote = ? WHERE cartID = ?", [$quantity, $lineNote, $existing['cartID']]);
        } else {
            db_query($conn, "UPDATE cart SET quantity = quantity + ? WHERE cartID = ?", [$quantity, $existing['cartID']]);
        }
    } else {
        db_query($conn, "INSERT INTO cart (studentID, itemID, shopID, quantity, lineNote) VALUES (?, ?, ?, ?, ?)", [$studentID, $itemID, $shopID, $quantity, $lineNote !== '' ? $lineNote : null]);
    }

    $countStmt = db_query($conn, "SELECT SUM(quantity) AS total FROM cart WHERE studentID = ?", [$studentID]);
    $countRow = $countStmt ? db_fetch_assoc($countStmt) : null;
    echo json_encode(['success' => true, 'cartCount' => (int) ($countRow['total'] ?? 0)]);
    exit();
}

/* -- AJAX: Update per-line cart note -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'updateCartNote') {
    // SAVE CUSTOM INSTRUCTIONS FOR ONE CART LINE WITHOUT REFRESHING THE CART MODAL.
    $cartID = (int) ($_POST['cartID'] ?? 0);
    $lineNote = trim((string) ($_POST['lineNote'] ?? ''));
    if ($cartID > 0) {
            db_query($conn, "UPDATE cart SET lineNote = ? WHERE cartID = ? AND studentID = ?", [$lineNote !== '' ? $lineNote : null, $cartID, $studentID]);
    }
    echo json_encode(['success' => true]);
    exit();
}

/* -- AJAX: My orders (status + review eligibility) -- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'getMyOrders') {
    // RETURN ORDER HISTORY WITH REVIEW ELIGIBILITY FOR THE MY ORDERS MODAL.
    $sql = "SELECT o.orderID, o.shopID, ss.shopName, o.orderStatus, o.totalAmount, o.orderedAt, o.paymentMethod,
                   CASE WHEN EXISTS (SELECT 1 FROM dbo.SHOP_REVIEWS sr WHERE sr.orderID = o.orderID) THEN 1 ELSE 0 END AS hasReview
            FROM ORDERS o
            LEFT JOIN SELLER_DATA.dbo.SELLER_SHOPS ss ON ss.shopID = o.shopID
            WHERE o.studentID = ?
            ORDER BY o.orderedAt DESC";
    $st = db_query($conn, "SELECT o.orderID, o.shopID, ss.shopName, ss.shopLAT, ss.shopLNG, ss.shopADDRESS,
                   o.orderStatus, o.totalAmount, o.orderedAt, o.paymentMethod, o.deliveryLAT, o.deliveryLNG, o.deliveryADDRESS,
                   (EXISTS (SELECT 1 FROM shop_reviews sr WHERE sr.orderID = o.orderID)) AS hasReview
            FROM orders o
            LEFT JOIN seller_data.seller_shops ss ON ss.shopID = o.shopID
            WHERE o.studentID = ?
            ORDER BY o.orderedAt DESC", [$studentID]);
    $orders = [];
    $rows = $st ? db_fetch_all($st) : [];
    foreach ($rows as $row) {
        $orders[] = [
            'orderID' => (int) $row['orderID'],
            'shopID' => (int) $row['shopID'],
            'shopName' => (string) ($row['shopName'] ?? ''),
            'orderStatus' => (string) ($row['orderStatus'] ?? ''),
            'totalAmount' => (float) ($row['totalAmount'] ?? 0),
            'orderedAt' => isset($row['orderedAt']) ? $row['orderedAt'] : '',
            'paymentMethod' => (string) ($row['paymentMethod'] ?? ''),
            'hasReview' => !empty($row['hasReview']),
            'deliveryLat' => $row['deliveryLAT'] !== null ? (float) $row['deliveryLAT'] : null,
            'deliveryLng' => $row['deliveryLNG'] !== null ? (float) $row['deliveryLNG'] : null,
            'deliveryAddress' => (string) ($row['deliveryADDRESS'] ?? ''),
            'shopLat' => $row['shopLAT'] !== null ? (float) $row['shopLAT'] : null,
            'shopLng' => $row['shopLNG'] !== null ? (float) $row['shopLNG'] : null,
            'shopAddress' => (string) ($row['shopADDRESS'] ?? ''),
        ];
    }
    echo json_encode(['success' => true, 'orders' => $orders]);
    exit();
}

/* -- AJAX: Submit review (completed orders only, one per order) -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submitReview') {
    // ALLOW ONE REVIEW PER COMPLETED ORDER SO SHOP RATINGS STAY TIED TO REAL PURCHASES.
    header('Content-Type: application/json; charset=UTF-8');
    $orderID = (int) ($_POST['orderID'] ?? 0);
    $rating = (float) ($_POST['rating'] ?? 0);
    $reviewText = trim((string) ($_POST['reviewText'] ?? ''));

    if ($orderID <= 0 || $rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Invalid rating or order.']);
        exit();
    }

    $chk = db_query($conn, "SELECT orderID, shopID, orderStatus FROM orders WHERE orderID = ? AND studentID = ? LIMIT 1", [$orderID, $studentID]);
    $ord = $chk ? db_fetch_assoc($chk) : null;
    if (!$ord || strtolower((string) ($ord['orderStatus'] ?? '')) !== 'completed') {
        echo json_encode(['success' => false, 'message' => 'You can only review completed orders.']);
        exit();
    }

    $dup = db_query($conn, "SELECT reviewID FROM shop_reviews WHERE orderID = ? LIMIT 1", [$orderID]);
    if ($dup && db_fetch_assoc($dup)) {
        echo json_encode(['success' => false, 'message' => 'This order was already reviewed.']);
        exit();
    }

    $shopID = (int) ($ord['shopID'] ?? 0);
    db_query($conn, "INSERT INTO shop_reviews (orderID, shopID, studentID, rating, reviewText) VALUES (?, ?, ?, ?, ?)", [$orderID, $shopID, $studentID, $rating, $reviewText !== '' ? $reviewText : null]);

    echo json_encode(['success' => true]);
    exit();
}

/* -- AJAX: Get cart contents -- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'getCart') {
    // RETURN THE CART WITH JOINED MENU AND SHOP DETAILS FOR THE CART MODAL.
    $cartStmt = db_query($conn, "SELECT c.*, mi.itemName, mi.itemPrice, mi.itemImage, ss.shopName
         FROM cart c
         JOIN seller_data.menu_items mi ON mi.itemID = c.itemID
         JOIN seller_data.seller_shops ss ON ss.shopID = c.shopID
         WHERE c.studentID = ?", [$studentID]);
    $cartItems = $cartStmt ? db_fetch_all($cartStmt) : [];
    echo json_encode(['success' => true, 'items' => $cartItems]);
    exit();
}

/* -- AJAX: Remove from cart -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'removeFromCart') {
    // REMOVE ONLY THE CURRENT STUDENT'S CART ITEM.
    $cartID = (int)$_POST['cartID'];
    db_query($conn, "DELETE FROM cart WHERE cartID = ? AND studentID = ?", [$cartID, $studentID]);
    echo json_encode(['success' => true]);
    exit();
}

/* -- AJAX: Update profile -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'updateProfile') {
    // UPDATE BASIC STUDENT PROFILE DETAILS FROM THE SETTINGS MODAL.
    header('Content-Type: application/json; charset=UTF-8');
    $userName = trim((string) ($_POST['userNAME'] ?? ''));
    $userPhone = trim((string) ($_POST['userPHONE'] ?? ''));
    $userAddress = trim((string) ($_POST['userADDRESS'] ?? ''));
    $userCity = trim((string) ($_POST['userCITY'] ?? ''));

    if (empty($userName)) {
        echo json_encode(['success' => false, 'message' => 'Name is required.']);
        exit();
    }

    $updateResult = db_query($conn, "UPDATE user_details SET userNAME = ?, userPHONE = ?, userADDRESS = ?, userCITY = ? WHERE userID = ?", [
            $userName,
            $userPhone !== '' ? $userPhone : null,
            $userAddress !== '' ? $userAddress : null,
            $userCity !== '' ? $userCity : null,
            $studentID
        ]);

    if ($updateResult === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    }
    exit();
}

// Fetch all shops (store type first, then name)
$shopsStmt = db_query($connSeller, "SELECT * FROM seller_shops
     ORDER BY CASE WHEN NULLIF(TRIM(COALESCE(shopType,'')), '') IS NULL THEN 1 ELSE 0 END,
              TRIM(shopType), TRIM(shopName)");
$shops = $shopsStmt ? db_fetch_all($shopsStmt) : [];

// Cart count
$cartCountStmt = db_query($conn, "SELECT SUM(quantity) AS total FROM cart WHERE studentID = ?", [$studentID]);
$cartCountRow = $cartCountStmt ? db_fetch_assoc($cartCountStmt) : null;
$cartCount = (int)($cartCountRow['total'] ?? 0);

// Best sellers
$bestSellersStmt = db_query($connSeller,
    "SELECT mi.itemID, mi.itemName, mi.itemPrice, mi.itemImage, mi.shopID, SUM(oi.quantity) AS soldQty
     FROM order_items oi
     JOIN menu_items mi ON mi.itemID = oi.itemID
     JOIN orders o ON o.orderID = oi.orderID
     WHERE o.orderStatus IN ('completed','ready')
     GROUP BY mi.itemID, mi.itemName, mi.itemPrice, mi.itemImage, mi.shopID
     ORDER BY soldQty DESC
     LIMIT 3"
);
$bestSellers = $bestSellersStmt ? db_fetch_all($bestSellersStmt) : [];
// Fallback if no orders yet
if (empty($bestSellers)) {
    $fallbackStmt = db_query($connSeller, "SELECT * FROM menu_items WHERE isAvailable = 1 ORDER BY createdAt DESC LIMIT 3");
    $bestSellers = $fallbackStmt ? db_fetch_all($fallbackStmt) : [];
}

// Latest community posts (food feed preview)
$connPosts = db_connect(DB_NAME_ANIMEALS_POSTS);
$latestPosts = [];
if ($connPosts) {
    animeals_posts_ensure_extensions($connPosts);
    $postsStmt = db_query($connPosts,
        "SELECT p.postID, p.postCONTENT, p.postIMAGE, p.postDATE, p.userEMAIL,
                u.userNAME AS poster_name, u.userPROFILEPIC AS poster_pic
         FROM posts p
         LEFT JOIN animeals.user_details u ON u.userEMAIL = p.userEMAIL
         WHERE p.userEMAIL <> ?
         ORDER BY p.postDATE DESC
         LIMIT 3",
        [$_SESSION['email']]
    );
    $latestPosts = $postsStmt ? db_fetch_all($postsStmt) : [];
    $latestPostIds = array_values(array_filter(array_map(static fn ($p) => (int) ($p['postID'] ?? 0), $latestPosts)));
    $latestImagesByPost = [];
    if ($latestPostIds !== []) {
        $inList = implode(',', $latestPostIds);
        $imgStmt = db_query($connPosts, "SELECT postID, imagePath FROM post_images WHERE postID IN ($inList) ORDER BY postID ASC, displayOrder ASC, imageID ASC");
        $imgRows = $imgStmt ? db_fetch_all($imgStmt) : [];
        foreach ($imgRows as $imgRow) {
            $pid = (int) ($imgRow['postID'] ?? 0);
            $path = trim((string) ($imgRow['imagePath'] ?? ''));
            if ($pid > 0 && $path !== '') {
                $latestImagesByPost[$pid][] = $path;
            }
        }
    }
    foreach ($latestPosts as &$latestPost) {
        $pid = (int) ($latestPost['postID'] ?? 0);
        $legacyImage = trim((string) ($latestPost['postIMAGE'] ?? ''));
        $latestPost['postIMAGES'] = $latestImagesByPost[$pid] ?? ($legacyImage !== '' ? [$legacyImage] : []);
    }
    unset($latestPost);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root {
            --animeals-bg: #f3f7f5;
            --animeals-card: #ffffff;
            --animeals-green: #1dbf73;
            --animeals-green-dark: #0f7b55;
            --animeals-text: #25332d;
            --animeals-muted: #66746e;
            --animeals-line: #e7eeea;
            --animeals-soft: #f1f4f3;
            --animeals-shadow: 0 10px 25px rgba(0,0,0,0.08);
            --animeals-shadow-soft: 0 5px 15px rgba(0,0,0,0.05);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            background: var(--animeals-bg);
            height: 100vh;
            overflow: hidden;
            color: var(--animeals-text);
        }

        .dashboard-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            height: 100vh;
        }

        /* ========== SIDEBAR ========== */
        .sidebar {
            background: linear-gradient(180deg, var(--animeals-green-dark), var(--animeals-green));
            color: #fff;
            padding: 25px 18px;
            display: flex;
            flex-direction: column;
            border-top-right-radius: 35px;
            border-bottom-right-radius: 35px;
            box-shadow: 10px 0 25px rgba(15,123,85,0.16);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 800;
            padding-left: 10px;
            margin-bottom: 20px;
        }
        .brand img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            border-radius: 0;
            background: transparent;
            padding: 0;
        }

        /* Profile section — clickable to go to profile.php */
        .profile {
            text-align: center;
            padding: 18px 10px;
            background: rgba(255,255,255,0.12);
            border-radius: 24px;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12);
        }
        .profile img {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            border: 5px solid #fff;
            margin-bottom: 10px;
            object-fit: cover;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .profile h4 { font-size: 16px; }
        .profile small { color: rgba(255,255,255,0.82); }

        /* Sidebar navigation links */
        .menu { display: flex; flex-direction: column; gap: 5px; margin-top: 15px; }

        .menu a {
            text-decoration: none;
            color: #fff;
            padding: 12px 15px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            font-size: 14px;
            transition: .25s;
            cursor: pointer;
        }

        .menu a:hover, .menu a.active { background: rgba(255,255,255,0.22); }
        .menu a i { margin-right: 12px; font-size: 18px; }

        /* Logout button at bottom of sidebar */
        .logout-btn {
            margin-top: auto;
            background: rgba(255,255,255,0.16);
            text-align: center;
            padding: 12px;
            border-radius: 20px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #ff4d4d; }

        /* ========== MAIN CONTENT ========== */
        .main {
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            height: 100vh;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--animeals-green-dark), var(--animeals-green));
            color: #fff;
            padding: 22px 24px;
            border-radius: 30px;
            box-shadow: var(--animeals-shadow);
        }
        .topbar h2 { color: #fff !important; }
        .topbar span { color: rgba(255,255,255,0.82) !important; }

        .search-container { display: flex; gap: 10px; align-items: center; }

        .search {
            background: #fff;
            padding: 10px 15px;
            border-radius: 20px;
            width: 350px;
            display: flex;
            align-items: center;
            box-shadow: var(--animeals-shadow-soft);
        }
        .search i { color: var(--animeals-green-dark); }
        .search input { border: none; outline: none; padding-left: 10px; width: 100%; background: transparent; }

        .filter-btn {
            background: #fff;
            padding: 10px;
            border-radius: 18px;
            cursor: pointer;
            box-shadow: var(--animeals-shadow-soft);
            color: var(--animeals-green-dark);
        }

        /* ========== CONTENT GRID ========== */
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
            flex: 1;
            min-height: 0;
        }

        .left-col { display: flex; flex-direction: column; gap: 20px; min-height: 0; }
        .right-col { display: flex; flex-direction: column; gap: 20px; min-height: 0; overflow-y: auto; padding-right: 5px; }

        /* Scrollable restaurants box */
        .shops-scroll-box {
            background: var(--animeals-card);
            padding: 20px;
            border-radius: 25px;
            box-shadow: var(--animeals-shadow-soft);
            flex: 1;
            overflow-y: auto;
        }

        .shops-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 20px;
        }

        /* Individual restaurant card */
        .shop-card {
            background: #fff;
            border-radius: 20px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: 0.3s;
            border: 1px solid var(--animeals-line);
        }
        .shop-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(15,123,85,0.14); border-color: rgba(29,191,115,0.28); }
        .shop-card img { width: 100%; aspect-ratio: 1/1; object-fit: cover; border-radius: 15px; margin-bottom: 10px; }
        .shop-card b { font-size: 14px; color: var(--animeals-text); display: block; }

        /* ========== RIGHT COLUMN BOXES ========== */
        .best-sellers-box, .feed-box, .history-box {
            background: var(--animeals-card);
            padding: 20px;
            border-radius: 25px;
            box-shadow: var(--animeals-shadow-soft);
        }

        /* Best seller item row */
        .mini-food-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--animeals-line);
        }
        .mini-food-item img { width: 50px; height: 50px; border-radius: 15px; object-fit: cover; }
        .mini-food-item div { flex: 1; }
        .mini-food-item b { font-size: 13px; display: block; }
        .mini-food-item span { font-size: 12px; color: var(--animeals-green-dark); font-weight: 700; }

        /* Latest food feed post */
        .post-latest { margin-top: 10px; }
        .post-latest img { width: 100%; border-radius: 15px; margin-top: 10px; max-height: 150px; object-fit: cover; }
        .post-latest img.student-post-preview-image { cursor: zoom-in; }
        .student-post-image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
            gap: 6px;
            margin-bottom: 8px;
        }
        .student-post-image-grid img {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 12px;
            object-fit: cover;
            max-height: none;
            margin: 0;
        }

        .post-box {
            background: white;
            border-radius: 20px;
            padding: 15px 20px;
            box-shadow: var(--animeals-shadow-soft);
        }

        .post-box input[type="text"] {
            background: var(--animeals-soft) !important;
            border: none !important;
            border-radius: 20px !important;
            padding: 10px 15px !important;
        }

        .history-item { font-size: 13px; padding: 10px 0; border-bottom: 1px dashed #eee; display: flex; justify-content: space-between; }

        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--animeals-green); border-radius: 10px; }

        .student-photo-lightbox {
            display: none; position: fixed; inset: 0; z-index: 2000;
            background: rgba(0,0,0,0.88); align-items: center; justify-content: center;
            padding: 24px; cursor: zoom-out;
        }
        .student-photo-lightbox.open { display: flex; }
        .student-photo-lightbox img {
            max-width: min(92vw, 720px); max-height: 88vh; border-radius: 16px;
            object-fit: contain; box-shadow: 0 20px 60px rgba(0,0,0,0.5); cursor: default;
        }
        .student-post-image-viewer {
            position: fixed;
            inset: 0;
            display: none;
            z-index: 3000;
            background: rgba(0,0,0,0.88);
            overflow: auto;
            padding: 72px 18px 32px;
        }
        .student-post-image-viewer.open { display: flex; align-items: flex-start; justify-content: center; }
        .student-post-image-viewer img {
            width: auto;
            max-width: min(96vw, 1100px);
            height: auto;
            border-radius: 16px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.45);
            cursor: default;
        }
        .student-post-image-viewer-close {
            position: fixed;
            top: 18px;
            right: 18px;
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 50%;
            background: rgba(255,255,255,0.95);
            color: #111;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            z-index: 3001;
        }
        .student-track-map { height: 190px; border-radius: 14px; overflow: hidden; border: 1px solid #dbe7e1; margin-top: 10px; }

        @media (max-width: 980px) {
            body { overflow: auto; }
            .dashboard-container { grid-template-columns: 1fr; height: auto; min-height: 100vh; }
            .sidebar { border-radius: 0 0 30px 30px; }
            .main { height: auto; }
            .content-grid { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; gap: 14px; flex-direction: column; }
            .search-container { width: 100%; }
            .search { width: 100%; }
        }
    </style>
</head>
<body>
<div id="studentPhotoLightbox" class="student-photo-lightbox" onclick="studentCloseProfilePhoto(event)" aria-hidden="true">
    <img id="studentPhotoLightboxImg" src="" alt="Profile enlarged" onclick="event.stopPropagation()">
</div>
<div id="studentPostImageViewer" class="student-post-image-viewer" onclick="studentClosePostImageViewer(event)" aria-hidden="true">
    <button type="button" class="student-post-image-viewer-close" onclick="studentClosePostImageViewer(event)" aria-label="Close">&times;</button>
    <img id="studentPostImageViewerImg" src="" alt="Post preview" onclick="event.stopPropagation()">
</div>
<div class="dashboard-container">

    <!-- ========== SIDEBAR ========== -->
    <div class="sidebar">
        <div class="brand"><img src="logo.png?v=transparent" alt="ANIMEALS Logo"> ANIMEALS</div>

        <div class="profile">
            <img src="<?= $profilePic ?>" alt="Profile" style="cursor:pointer;" onclick="event.stopPropagation(); studentOpenProfilePhoto(this.src)">
            <div style="cursor:pointer;margin-top:8px;" onclick="location.href='profile.php'">
                <h4><?= htmlspecialchars($user['userNAME']) ?></h4>
                <small><?= htmlspecialchars($user['userSTUDENTNUM'] ?? $user['userEMAIL']) ?></small>
            </div>
        </div>

        <div class="menu">
            <a class="active"><i class="bi bi-grid"></i> Dashboard</a>
            <a onclick="openAllStoresModal()"><i class="bi bi-journal-text"></i> Menu</a>
            <a onclick="openCartModal()"><i class="bi bi-cart3"></i> My Cart</a>
            <a onclick="openOrdersModal()"><i class="bi bi-receipt-cutoff"></i> My Orders</a>
            <a href="feed.php"><i class="bi bi-rss"></i> Food Feed</a>
            <a href="profile.php"><i class="bi bi-person"></i> Profile</a>
            <a href="settings.php"><i class="bi bi-gear"></i> Settings</a>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="bi bi-box-arrow-right"></i> Log out
        </a>
    </div>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="main">

        <?php if (!empty($checkoutToast)): ?>
        <div id="checkoutToast" style="background:linear-gradient(135deg,#2ecc71,#0e6e36);color:#fff;padding:12px 18px;border-radius:14px;margin-bottom:12px;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 14px rgba(14,110,54,0.25);">
            <span><i class="bi bi-check-circle-fill" style="margin-right:8px;"></i><?= htmlspecialchars($checkoutMessage, ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" onclick="document.getElementById('checkoutToast').remove()" style="background:rgba(255,255,255,0.25);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;line-height:1;">&times;</button>
        </div>
        <?php endif; ?>

        <div class="topbar">
            <div class="search-container">
                <div class="search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="shopSearch" placeholder="Search shops..." oninput="searchShops(this.value)">
                </div>
                <div class="filter-btn" role="button" tabindex="0" title="Sort shops (tap to cycle)" onclick="cycleStudentShopSort()" onkeydown="if(event.key==='Enter'||event.key===' ')cycleStudentShopSort()"><i class="bi bi-sliders"></i></div>
            </div>

            <div style="display:flex; gap:25px; align-items:center;">
                <div style="position:relative; cursor:pointer;" onclick="openCartModal()">
                    <i class="bi bi-cart3" style="font-size:24px; color: #444;"></i>
                    <span id="cartBadge" style="position:absolute; top:-5px; right:-8px; background:#ff4d4d; color:white; font-size:10px; padding:2px 6px; border-radius:50%; display:<?= $cartCount > 0 ? 'block' : 'none' ?>;"><?= $cartCount ?></span>
                </div>
            </div>
        </div>

        <div class="content-grid">
            <!-- ── LEFT: Restaurants ── -->
            <div class="left-col">
                <h3 style="color:#333; font-size: 20px;">Restaurants</h3>
                <div class="shops-scroll-box">
                    <div class="shops-grid" id="shopsGrid">
                        <?php if (empty($shops)): ?>
                            <p style="color:#999; font-size:14px;">No shops available yet.</p>
                        <?php endif; ?>
                        <?php foreach ($shops as $shop):
                            $stype = trim((string) ($shop['shopType'] ?? ''));
                            $stypeLower = strtolower($stype);
                        ?>
                        <div class="shop-card" data-shop-id="<?= (int) ($shop['shopID'] ?? 0) ?>"
                             onclick="openShopMenuByID(<?= (int) $shop['shopID'] ?>)"
                             data-name="<?= htmlspecialchars(strtolower((string) ($shop['shopName'] ?? ''))) ?>"
                             data-shop-type="<?= htmlspecialchars($stypeLower !== '' ? $stypeLower : 'zzz') ?>">
                            <img src="<?= !empty($shop['shopLogo']) ? htmlspecialchars($shop['shopLogo']) : 'https://via.placeholder.com/150x150?text=Shop' ?>"
                                 onerror="this.src='https://via.placeholder.com/150x150?text=Shop'">
                            <b><?= htmlspecialchars((string) ($shop['shopName'] ?? '')) ?></b>
                            <?php if ($stype !== ''): ?>
                            <span style="display:block;font-size:11px;color:#888;margin-top:4px;"><?= htmlspecialchars($stype) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ── RIGHT: Best Sellers + Feed ── -->
            <div class="right-col">
                <div class="best-sellers-box">
                    <h4 style="font-size:14px; color:#444; border-bottom: 2px solid #1dbf73; display:inline-block; padding-bottom:3px;">TOP 3 BEST SELLERS</h4>
                    <?php foreach ($bestSellers as $item): ?>
                    <div class="mini-food-item">
                        <img src="<?= !empty($item['itemImage']) ? htmlspecialchars($item['itemImage']) : 'https://via.placeholder.com/50' ?>"
                             onerror="this.src='https://via.placeholder.com/50'">
                        <div>
                            <b><?= htmlspecialchars($item['itemName']) ?></b>
                            <span>₱<?= number_format((float)$item['itemPrice'], 2) ?></span>
                        </div>
                        <i class="bi bi-plus-circle-fill" style="color:#1dbf73; cursor:pointer; font-size: 20px;"
                           onclick="openItemDetail(<?= (int) $item['itemID'] ?>, <?= (int) $item['shopID'] ?>, <?= (float) ($item['itemPrice'] ?? 0) ?>)"></i>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($bestSellers)): ?>
                        <p style="color:#999; font-size:13px; margin-top:10px;">No data yet.</p>
                    <?php endif; ?>
                </div>

                <div class="feed-box">
                    <h4 style="font-size:14px; color:#444;">LATEST FOOD FEED</h4>
                    <div class="post-box" style="margin:10px 0 12px; display:flex; align-items:center; gap:12px;">
                        <img src="<?= $profilePic ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;" onerror="this.src='https://ui-avatars.com/api/?name=U&background=1dbf73&color=fff'">
                        <input id="postContentInput" type="text" placeholder="What's on your mind" style="flex:1;border-radius:12px;border:1px solid #eee;padding:10px;font-size:13px;">
                        <input id="postImageInput" type="file" accept="image/*" multiple style="display:none;">
                        <button type="button" onclick="document.getElementById('postImageInput').click()" title="Add image" style="background:none;border:none;font-size:18px;cursor:pointer;color:#1dbf73;"><i class="bi bi-image"></i></button>
                        <button type="button" id="studentSubmitPostBtn" onclick="submitPost()" style="background:#1dbf73;color:#fff;border:none;padding:8px 12px;border-radius:10px;font-weight:700;cursor:pointer;">Post</button>
                    </div>
                    <div id="postImagePreviewBox" style="display:none;margin-bottom:10px;position:relative;">
                        <div id="postImagePreviewGrid" class="student-post-image-grid"></div>
                        <button type="button" onclick="clearPostPreview()" style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;">&times;</button>
                    </div>
                    <div class="post-latest">
                        <?php if (empty($latestPosts)): ?>
                        <p style="font-size:12px; color:#999; margin-top:10px;">No posts from other users yet. <a href="feed.php" style="color:#0f7b55;font-weight:700;">Open community feed →</a></p>
                        <?php else: ?>
                        <?php foreach ($latestPosts as $lp):
                            $postImages = array_values($lp['postIMAGES'] ?? []);
                            $rawPost = trim((string) ($lp['postCONTENT'] ?? ''));
                            $pText = htmlspecialchars(strlen($rawPost) > 120 ? substr($rawPost, 0, 117) . '...' : $rawPost);
                            $pWhen = $lp['postDATE'] instanceof DateTimeInterface ? $lp['postDATE']->format('M j') : '';
                            $pnRaw = trim((string) ($lp['poster_name'] ?? $lp['POSTER_NAME'] ?? ''));
                            $displayPoster = $pnRaw !== '' ? $pnRaw : trim((string) ($lp['userEMAIL'] ?? 'Someone'));
                            $posterName = htmlspecialchars($displayPoster);
                            $posterPicRaw = (string) ($lp['poster_pic'] ?? $lp['POSTER_PIC'] ?? '');
                            $posterPic = $posterPicRaw !== ''
                                ? htmlspecialchars($posterPicRaw)
                                : 'https://ui-avatars.com/api/?name=' . rawurlencode($displayPoster) . '&background=1dbf73&color=fff';
                        ?>
                        <a href="feed.php?post=<?= (int) ($lp['postID'] ?? 0) ?>" style="text-decoration:none;color:inherit;display:block;margin-top:12px;padding:10px;border-radius:14px;background:#faf8ff;border:1px solid #eee;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                                <img src="<?= $posterPic ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" onerror="this.src='https://ui-avatars.com/api/?name=U&background=1dbf73&color=fff'">
                                <span style="font-size:13px;font-weight:700;color:#333;"><?= $posterName ?></span>
                            </div>
                            <?php if ($postImages !== []): ?>
                            <div class="student-post-image-grid">
                                <?php foreach ($postImages as $imagePath): ?>
                                    <img class="student-post-preview-image" src="<?= htmlspecialchars((string) $imagePath) ?>" alt="" onerror="this.style.display='none'">
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <p style="font-size:13px;color:#333;line-height:1.45;"><?= $pText ?></p>
                            <span style="font-size:11px;color:#999;"><?= htmlspecialchars($pWhen) ?></span>
                        </a>
                        <?php endforeach; ?>
                        <p style="margin-top:12px;"><a href="feed.php" style="color:#0f7b55;font-weight:700;font-size:13px;">Open full feed →</a></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========== ALL STORES (MENU TAB) ========== -->
<div id="allStoresModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:25px; width:92%; max-width:720px; max-height:88vh; overflow-y:auto; padding:28px; position:relative;">
        <button type="button" onclick="closeAllStoresModal()" style="position:absolute; top:15px; right:20px; background:none; border:none; font-size:22px; cursor:pointer; color:#888;">&times;</button>
        <h3 style="font-size:20px; font-weight:800; color:#333; margin-bottom:8px;">Browse stores</h3>
        <p style="font-size:13px;color:#888;margin-bottom:18px;">Sorted by store type, then name. Tap a store to open its menu.</p>
        <div id="allStoresGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px,1fr)); gap:16px;"></div>
    </div>
</div>

<!-- ========== SHOP MENU MODAL ========== -->
<div id="shopMenuModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; backdrop-filter:blur(4px);" onclick="shopMenuBackdropClick(event)">
    <div style="background:#fff; border-radius:25px; width:90%; max-width:650px; max-height:85vh; overflow-y:auto; padding:30px; position:relative;" onclick="event.stopPropagation()">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <button type="button" onclick="backToAllStores()" style="background:none; border:none; font-size:18px; cursor:pointer; color:#0f7b55; font-weight:700; display:flex; align-items:center; gap:6px;">
                <i class="bi bi-arrow-left"></i> Back
            </button>
            <h3 id="shopMenuTitle" style="font-size:20px; font-weight:800; color:#333; margin:0; flex:1; text-align:center;"></h3>
            <button type="button" onclick="backToAllStores()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#888; width:28px; height:28px; display:flex; align-items:center; justify-content:center;">&times;</button>
        </div>
        <div id="shopMenuGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(160px,1fr)); gap:15px;">
            <p style="color:#999;">Loading menu...</p>
        </div>
    </div>
</div>

<!-- ========== ITEM DETAIL (reviews + note before cart) ========== -->
<div id="itemDetailModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1100; justify-content:center; align-items:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:22px; width:94%; max-width:900px; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <button type="button" onclick="closeItemDetailModal()" style="position:absolute; top:12px; right:16px; background:rgba(255,255,255,0.9); border:none; font-size:22px; cursor:pointer; color:#555; z-index:2; border-radius:50%; width:36px; height:36px;">&times;</button>
        <div style="display:flex; flex-wrap:wrap; min-height:0; flex:1;">
            <div style="flex:1; min-width:260px; padding:22px; border-right:1px solid #eee; overflow-y:auto;">
                <img id="itemDetailImg" src="" alt="" style="width:100%; max-height:220px; object-fit:cover; border-radius:16px; margin-bottom:14px;">
                <h4 id="itemDetailName" style="font-size:18px; font-weight:800; color:#222;"></h4>
                <div id="itemDetailPrice" style="color:#0f7b55; font-weight:800; margin:6px 0 10px;"></div>
                <p id="itemDetailDesc" style="font-size:13px; color:#555; line-height:1.5;"></p>
                <div style="margin-top:16px; padding:14px; border:1px solid #e8f5ee; background:#f6fffa; border-radius:16px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <span style="font-size:13px; font-weight:800; color:#244236;">Quantity</span>
                        <div class="student-qty-stepper">
                            <button type="button" onclick="changeItemQty(-1)" aria-label="Decrease quantity">-</button>
                            <input id="itemQtyInput" type="number" min="1" max="20" value="1" oninput="syncItemSubtotal()">
                            <button type="button" onclick="changeItemQty(1)" aria-label="Increase quantity">+</button>
                        </div>
                    </div>
                    <div id="itemSubtotalText" style="margin-top:10px; font-size:13px; color:#0f7b55; font-weight:800;">Subtotal: ₱0.00</div>
                </div>
            </div>
            <div style="flex:1; min-width:260px; display:flex; flex-direction:column; min-height:0;">
                <div style="display:flex; border-bottom:1px solid #eee;">
                    <button type="button" id="tabReviews" class="item-tab item-tab-active" onclick="itemDetailSetTab('reviews')">Reviews</button>
                    <button type="button" id="tabNote" class="item-tab" onclick="itemDetailSetTab('note')">Note</button>
                </div>
                <div id="itemPanelReviews" style="flex:1; overflow-y:auto; padding:16px;">
                    <div id="itemReviewsList" style="font-size:13px; color:#666;">Loading…</div>
                </div>
                <div id="itemPanelNote" style="display:none; flex:1; flex-direction:column; padding:16px;">
                    <label style="font-size:12px;font-weight:700;color:#444;">Special instructions for this item</label>
                    <textarea id="itemLineNote" rows="5" placeholder="e.g. No onions, extra sauce…" style="margin-top:8px; flex:1; min-height:120px; border:1px solid #e5e5e5; border-radius:12px; padding:10px; font-size:13px; resize:vertical;"></textarea>
                </div>
                <div style="padding:14px 16px; border-top:1px solid #eee; display:flex; gap:10px;">
                    <button type="button" onclick="closeItemDetailModal()" style="flex:1; padding:12px; border-radius:14px; border:1px solid #ddd; background:#fff; font-weight:700; cursor:pointer;">Close</button>
                    <button type="button" id="itemAddBtn" onclick="addToCartFromItemModal()" style="flex:1; padding:12px; border-radius:14px; border:none; background:linear-gradient(135deg,#2ecc71,#0e6e36); color:#fff; font-weight:800; cursor:pointer;">Add to cart</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========== MY ORDERS (history + reviews) ========== -->
<div id="ordersModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:25px; width:92%; max-width:560px; max-height:88vh; overflow-y:auto; padding:26px; position:relative;">
        <button type="button" onclick="closeOrdersModal()" style="position:absolute; top:14px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#888;">&times;</button>
        <h3 style="font-size:20px; font-weight:800; color:#333; margin-bottom:6px;"><i class="bi bi-receipt-cutoff"></i> My orders</h3>
        <p style="font-size:12px;color:#888;margin-bottom:14px;">Leave a review only after the order is completed.</p>
        <div id="ordersListBody"><p style="color:#999;">Loading…</p></div>
    </div>
</div>
<style>
    .item-tab { flex:1; padding:12px; border:none; background:#fafafa; font-weight:700; font-size:13px; color:#777; cursor:pointer; }
    .item-tab-active { background:#fff; color:#0f7b55; border-bottom:2px solid #1dbf73; margin-bottom:-1px; }
    .student-menu-tools { display:grid; grid-template-columns:1fr auto; gap:10px; margin-bottom:14px; align-items:center; }
    .student-menu-tools input, .student-menu-tools select { border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; font-size:13px; outline:none; background:#fff; }
    .student-menu-tools input:focus, .student-menu-tools select:focus { border-color:#1dbf73; box-shadow:0 0 0 3px rgba(29,191,115,0.12); }
    .student-qty-stepper { display:flex; align-items:center; gap:6px; }
    .student-qty-stepper button { width:34px; height:34px; border:none; border-radius:10px; background:#0f7b55; color:#fff; font-weight:900; cursor:pointer; }
    .student-qty-stepper input { width:54px; height:34px; border:1px solid #dbe8e0; border-radius:10px; text-align:center; font-weight:800; color:#1f2937; }
    .student-added-toast { position:fixed; left:50%; bottom:24px; transform:translate(-50%, 20px); z-index:3200; min-width:min(92vw, 420px); background:#163428; color:#fff; border-radius:18px; box-shadow:0 18px 50px rgba(0,0,0,0.25); padding:12px; display:none; align-items:center; justify-content:space-between; gap:12px; opacity:0; transition:opacity .2s ease, transform .2s ease; }
    .student-added-toast.show { display:flex; opacity:1; transform:translate(-50%, 0); }
    .student-added-toast button { border:none; border-radius:12px; padding:9px 12px; font-weight:800; cursor:pointer; }
    .student-cart-shop-title { margin:14px 0 6px; font-size:12px; color:#0f7b55; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
</style>

<!-- ========== CART MODAL ========== -->
<div id="cartModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:25px; width:90%; max-width:500px; max-height:85vh; overflow-y:auto; padding:30px; position:relative;">
        <button onclick="closeCartModal()" style="position:absolute; top:15px; right:20px; background:none; border:none; font-size:22px; cursor:pointer; color:#888;">&times;</button>
        <h3 style="font-size:20px; font-weight:800; color:#333; margin-bottom:20px;"><i class="bi bi-cart3"></i> My Cart</h3>
        <div id="cartItemsList"><p style="color:#999;">Loading...</p></div>
        <div id="cartTotal" style="font-size:16px; font-weight:800; color:#333; margin-top:15px; text-align:right;"></div>
        <button id="checkoutBtn" onclick="goToCheckout()" style="display:none; width:100%; background:linear-gradient(135deg,#2ecc71,#0e6e36); color:white; border:none; padding:14px; border-radius:18px; font-weight:800; font-size:15px; cursor:pointer; margin-top:15px;">
            Proceed to Checkout →
        </button>
    </div>
</div>
<div id="studentAddedToast" class="student-added-toast" role="status" aria-live="polite">
    <span id="studentAddedToastText">Added to cart.</span>
    <div style="display:flex; gap:8px; flex-shrink:0;">
        <button type="button" onclick="hideStudentAddedToast()" style="background:rgba(255,255,255,0.12); color:#fff;">Keep ordering</button>
        <button type="button" onclick="hideStudentAddedToast(); openCartModal()" style="background:#fff; color:#0f7b55;">View cart</button>
    </div>
</div>

<script>
    const STUDENT_ID = <?= $studentID ?>;
    const STUDENT_SHOPS = <?= json_encode(array_map(static function ($s) {
        return [
            'shopID' => (int) ($s['shopID'] ?? 0),
            'shopName' => (string) ($s['shopName'] ?? ''),
            'shopLogo' => (string) ($s['shopLogo'] ?? ''),
            'shopType' => trim((string) ($s['shopType'] ?? '')),
        ];
    }, $shops), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;

    let studentShopSort = 'default';
    let _itemModalCtx = { itemID: 0, shopID: 0 };
    let _currentShopItems = [];
    let _currentShopCategories = [];

    /* ── Search shops ── */
    function searchShops(query) {
        const q = (query || '').toLowerCase().trim();
        document.querySelectorAll('.shop-card').forEach(card => {
            const name = (card.dataset.name || '');
            card.style.display = !q || name.includes(q) ? 'block' : 'none';
        });
    }

    function cycleStudentShopSort() {
        studentShopSort = studentShopSort === 'default' ? 'asc' : studentShopSort === 'asc' ? 'desc' : 'default';
        const tip = { default: 'By store type, then name', asc: 'Name A–Z', desc: 'Name Z–A' };
        const el = document.querySelector('.filter-btn');
        if (el) el.title = 'Sort shops: ' + tip[studentShopSort];
        applyStudentShopSort();
        const inp = document.getElementById('shopSearch');
        if (inp && inp.value) searchShops(inp.value);
    }

    function applyStudentShopSort() {
        const grid = document.getElementById('shopsGrid');
        if (!grid) return;
        const cards = Array.from(grid.querySelectorAll('.shop-card'));
        if (studentShopSort === 'asc') {
            cards.sort((a, b) => (a.dataset.name || '').localeCompare(b.dataset.name || ''));
        } else if (studentShopSort === 'desc') {
            cards.sort((a, b) => (b.dataset.name || '').localeCompare(a.dataset.name || ''));
        } else {
            cards.sort((a, b) => {
                const ta = (a.dataset.shopType || 'zzz');
                const tb = (b.dataset.shopType || 'zzz');
                if (ta !== tb) return ta.localeCompare(tb);
                return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            });
        }
        cards.forEach(c => grid.appendChild(c));
    }

    function openAllStoresModal() {
        const grid = document.getElementById('allStoresGrid');
        if (!grid) return;
        if (!STUDENT_SHOPS.length) {
            grid.innerHTML = '<p style="color:#999;">No shops available yet.</p>';
        } else {
            grid.innerHTML = STUDENT_SHOPS.map(s => {
                const type = (s.shopType || '').trim();
                const typeHtml = type ? `<span style="display:block;font-size:11px;color:#888;margin-top:4px;">${escHtml(type)}</span>` : '';
                const logo = s.shopLogo || 'https://via.placeholder.com/150x150?text=Shop';
                return `<div class="shop-card" style="cursor:pointer;" onclick="closeAllStoresModal(); openShopMenuByID(${s.shopID})">
                    <img src="${escHtml(logo)}" style="width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:12px;margin-bottom:10px;" onerror="this.src='https://via.placeholder.com/150x150?text=Shop'">
                    <b style="font-size:14px;color:#333;display:block;">${escHtml(s.shopName)}</b>${typeHtml}
                </div>`;
            }).join('');
        }
        document.getElementById('allStoresModal').style.display = 'flex';
    }

    function openShopMenuByID(shopID) {
        const shop = STUDENT_SHOPS.find(s => s.shopID === shopID);
        if (shop) {
            openShopMenu(shopID, shop.shopName);
        }
    }

    function closeAllStoresModal() {
        const el = document.getElementById('allStoresModal');
        if (el) el.style.display = 'none';
    }

    /* ── Open shop menu modal ── */
    async function openShopMenu(shopID, shopName) {
        document.getElementById('shopMenuTitle').textContent = shopName;
        document.getElementById('shopMenuGrid').innerHTML = '<p style="color:#999;">Loading menu...</p>';
        document.getElementById('shopMenuModal').style.display = 'flex';

        const res = await fetch(`getMenu.php?shopID=${shopID}`);
        const data = await res.json();

        if (!data.items || data.items.length === 0) {
            document.getElementById('shopMenuGrid').innerHTML = '<p style="color:#999;">No items available.</p>';
            return;
        }

        _currentShopItems = data.items || [];
        _currentShopCategories = Array.from(new Set(_currentShopItems.map(item => (item.itemCategory || '').trim()).filter(Boolean))).sort();
        renderShopMenuItems();
    }

    function renderShopMenuItems() {
        const grid = document.getElementById('shopMenuGrid');
        if (!grid) return;
        const qEl = document.getElementById('menuItemSearch');
        const cEl = document.getElementById('menuCategoryFilter');
        const activeId = document.activeElement ? document.activeElement.id : '';
        const q = (qEl ? qEl.value : '').trim().toLowerCase();
        const cursorAt = qEl && typeof qEl.selectionStart === 'number' ? qEl.selectionStart : null;
        const cat = cEl ? cEl.value : '';
        const filtered = _currentShopItems.filter(item => {
            const name = (item.itemName || '').toLowerCase();
            const desc = (item.itemDescription || '').toLowerCase();
            const itemCat = (item.itemCategory || '').trim();
            return (!q || name.includes(q) || desc.includes(q)) && (!cat || itemCat === cat);
        });

        const toolbar = `
            <div class="student-menu-tools" style="grid-column:1/-1;">
                <input id="menuItemSearch" type="search" placeholder="Search food in this store..." value="${escHtml(qEl ? qEl.value : '')}" oninput="renderShopMenuItems()">
                <select id="menuCategoryFilter" onchange="renderShopMenuItems()">
                    <option value="">All categories</option>
                    ${_currentShopCategories.map(c => `<option value="${escHtml(c)}" ${cat === c ? 'selected' : ''}>${escHtml(c)}</option>`).join('')}
                </select>
            </div>`;

        if (!filtered.length) {
            grid.innerHTML = toolbar + '<p style="grid-column:1/-1;color:#999;text-align:center;padding:18px;">No matching items.</p>';
            restoreMenuFilterFocus(activeId, cursorAt);
            return;
        }

        grid.innerHTML = toolbar + filtered.map(item => `
            <div style="background:#fdfdfd; border:1px solid #eee; border-radius:15px; overflow:hidden; transition:0.3s; cursor:pointer;"
                 onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform=''"
                 onclick="openItemDetail(${item.itemID}, ${item.shopID}, ${parseMoney(item.itemPrice)})">
                <img src="${item.itemImage ? escHtml(item.itemImage) : 'https://via.placeholder.com/160x100?text=Food'}"
                     style="width:100%; height:100px; object-fit:cover;"
                     onerror="this.src='https://via.placeholder.com/160x100?text=Food'">
                <div style="padding:10px;">
                    <b style="font-size:13px; display:block;">${escHtml(item.itemName)}</b>
                    <p style="font-size:11px;color:#66746e;line-height:1.35;margin:5px 0 7px;min-height:30px;">${escHtml(shortItemDescription(item))}</p>
                    <span style="font-size:12px; color:#0f7b55; font-weight:700;">₱${parseFloat(item.itemPrice).toFixed(2)}</span>
                    <span style="display:block; width:100%; margin-top:8px; text-align:center; font-size:11px; font-weight:700; color:#0f7b55;">Tap for reviews & note</span>
                </div>
            </div>
        `).join('');
        restoreMenuFilterFocus(activeId, cursorAt);
    }

    function shortItemDescription(item) {
        const raw = String((item && item.itemDescription) || '').trim();
        const fallback = String((item && item.itemCategory) || '').trim();
        const text = raw || (fallback ? 'Category: ' + fallback : 'No description yet.');
        return text.length > 92 ? text.slice(0, 89) + '...' : text;
    }

    function restoreMenuFilterFocus(activeId, cursorAt) {
        if (activeId !== 'menuItemSearch' && activeId !== 'menuCategoryFilter') return;
        const el = document.getElementById(activeId);
        if (!el) return;
        el.focus();
        if (activeId === 'menuItemSearch' && cursorAt !== null && typeof el.setSelectionRange === 'function') {
            el.setSelectionRange(cursorAt, cursorAt);
        }
    }

    function closeShopModal() {
        document.getElementById('shopMenuModal').style.display = 'none';
    }

    function backToAllStores() {
        closeShopModal();
        openAllStoresModal();
    }

    function shopMenuBackdropClick(event) {
        if (event.target.id === 'shopMenuModal') {
            if (confirm('Are you sure you want to exit the menu?')) {
                backToAllStores();
            }
        }
    }

    function itemDetailSetTab(which) {
        const rev = document.getElementById('itemPanelReviews');
        const note = document.getElementById('itemPanelNote');
        const t1 = document.getElementById('tabReviews');
        const t2 = document.getElementById('tabNote');
        if (!rev || !note) return;
        if (which === 'note') {
            rev.style.display = 'none';
            note.style.display = 'flex';
            note.style.flexDirection = 'column';
            t1.classList.remove('item-tab-active');
            t2.classList.add('item-tab-active');
        } else {
            rev.style.display = 'block';
            note.style.display = 'none';
            t1.classList.add('item-tab-active');
            t2.classList.remove('item-tab-active');
        }
    }

    async function openItemDetail(itemID, shopID, fallbackPrice = 0) {
        fallbackPrice = parseMoney(fallbackPrice);
        _itemModalCtx = { itemID, shopID, price: fallbackPrice };
        document.getElementById('itemLineNote').value = '';
        document.getElementById('itemQtyInput').value = '1';
        document.getElementById('itemQtyInput').dataset.price = String(fallbackPrice);
        syncItemSubtotal();
        itemDetailSetTab('reviews');
        document.getElementById('itemReviewsList').innerHTML = 'Loading…';
        document.getElementById('itemDetailModal').style.display = 'flex';

        const res = await fetch(`getItemDetail.php?itemID=${encodeURIComponent(itemID)}&shopID=${encodeURIComponent(shopID)}`);
        const data = await res.json();
        if (!data.ok || !data.item) {
            document.getElementById('itemDetailName').textContent = 'Item unavailable';
            document.getElementById('itemDetailPrice').textContent = '';
            document.getElementById('itemDetailDesc').textContent = data.error === 'not_found' ? 'This item is no longer available.' : 'Could not load item.';
            document.getElementById('itemReviewsList').innerHTML = '';
            return;
        }
        const it = data.item;
        const loadedPrice = parseMoney(it.itemPrice);
        const itemPrice = loadedPrice > 0 ? loadedPrice : fallbackPrice;
        _itemModalCtx = { itemID, shopID, price: itemPrice, name: it.itemName || 'Item' };
        document.getElementById('itemQtyInput').dataset.price = String(itemPrice);
        document.getElementById('itemDetailImg').src = it.itemImage || 'https://via.placeholder.com/400x220?text=Food';
        document.getElementById('itemDetailName').textContent = it.itemName;
        document.getElementById('itemDetailPrice').textContent = '₱' + itemPrice.toFixed(2);
        syncItemSubtotal();
        let desc = (it.itemDescription || '').trim();
        if (!desc && (it.itemCategory || '').trim()) desc = 'Category: ' + it.itemCategory;
        document.getElementById('itemDetailDesc').textContent = desc || 'No description.';

        const revs = data.reviews || [];
        if (!revs.length) {
            document.getElementById('itemReviewsList').innerHTML = '<p style="color:#999;">No reviews for this item yet.</p>';
        } else {
            document.getElementById('itemReviewsList').innerHTML = revs.map(r => `
                <div style="border-bottom:1px solid #f0f0f0; padding:10px 0;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <b>${escHtml(r.userNAME || 'Student')}</b>
                        <span style="color:#f4b400; font-weight:800;">${'★'.repeat(Math.round(r.rating))}${'☆'.repeat(5 - Math.round(r.rating))}</span>
                    </div>
                    <p style="margin-top:6px; color:#444;">${escHtml(r.reviewText || '')}</p>
                    <span style="font-size:11px; color:#aaa;">${escHtml(r.createdAt || '')}</span>
                </div>`).join('');
        }
    }

    function closeItemDetailModal() {
        document.getElementById('itemDetailModal').style.display = 'none';
    }

    function getItemQty() {
        const input = document.getElementById('itemQtyInput');
        const qty = Math.max(1, Math.min(20, parseInt(input && input.value ? input.value : '1', 10) || 1));
        if (input) input.value = String(qty);
        return qty;
    }

    function changeItemQty(delta) {
        const input = document.getElementById('itemQtyInput');
        const current = getItemQty();
        if (input) input.value = String(Math.max(1, Math.min(20, current + delta)));
        syncItemSubtotal();
    }

    function syncItemSubtotal() {
        const qty = getItemQty();
        const input = document.getElementById('itemQtyInput');
        const price = parseMoney((input && input.dataset.price) || _itemModalCtx.price || 0);
        const el = document.getElementById('itemSubtotalText');
        const btn = document.getElementById('itemAddBtn');
        if (el) el.textContent = 'Subtotal: ₱' + (price * qty).toFixed(2);
        if (btn) btn.textContent = 'Add ' + qty + ' to cart';
    }

    function parseMoney(value) {
        const cleaned = String(value == null ? '0' : value).replace(/[^0-9.-]/g, '');
        const parsed = Number.parseFloat(cleaned);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    async function addToCartFromItemModal() {
        const note = document.getElementById('itemLineNote').value.trim();
        const qty = getItemQty();
        await addToCart(_itemModalCtx.itemID, _itemModalCtx.shopID, note, qty, _itemModalCtx.name || 'Item');
        closeItemDetailModal();
    }

    /* ── Add to cart ── */
    async function addToCart(itemID, shopID, lineNote, quantity = 1, itemName = 'Item') {
        const fd = new FormData();
        fd.append('action', 'addToCart');
        fd.append('itemID', itemID);
        fd.append('shopID', shopID);
        fd.append('quantity', String(quantity));
        if (lineNote) fd.append('lineNote', lineNote);

        const res = await fetch('student.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const badge = document.getElementById('cartBadge');
            badge.textContent = data.cartCount;
            badge.style.display = 'block';
            badge.style.transform = 'scale(1.4)';
            setTimeout(() => badge.style.transform = 'scale(1)', 200);
            showStudentAddedToast(`${quantity} x ${itemName} added to cart.`);
        }
    }

    function showStudentAddedToast(message) {
        const toast = document.getElementById('studentAddedToast');
        const text = document.getElementById('studentAddedToastText');
        if (!toast || !text) return;
        text.textContent = message;
        toast.classList.add('show');
        clearTimeout(showStudentAddedToast.timer);
        showStudentAddedToast.timer = setTimeout(hideStudentAddedToast, 4200);
    }

    function hideStudentAddedToast() {
        const toast = document.getElementById('studentAddedToast');
        if (toast) toast.classList.remove('show');
    }

    async function saveCartLineNote(cartID, value) {
        const fd = new FormData();
        fd.append('action', 'updateCartNote');
        fd.append('cartID', cartID);
        fd.append('lineNote', value);
        await fetch('student.php', { method: 'POST', body: fd });
    }

    function closeOrdersModal() {
        const el = document.getElementById('ordersModal');
        if (el) el.style.display = 'none';
    }

    async function openOrdersModal() {
        document.getElementById('ordersModal').style.display = 'flex';
        const body = document.getElementById('ordersListBody');
        destroyStudentTrackingMaps();
        body.innerHTML = '<p style="color:#999;">Loading…</p>';
        const res = await fetch('student.php?action=getMyOrders');
        const data = await res.json();
        if (!data.success || !data.orders || !data.orders.length) {
            body.innerHTML = '<p style="color:#999;">No orders yet.</p>';
            return;
        }
        body.innerHTML = data.orders.map(o => {
            const st = (o.orderStatus || '').toLowerCase();
            const stColor = st === 'completed' ? '#0e7a4a' : st === 'cancelled' ? '#c0392b' : '#b8860b';
            let reviewBlock = '';
            if (st === 'completed' && !o.hasReview) {
                reviewBlock = `
                    <div style="margin-top:10px;padding:10px;background:#faf8ff;border-radius:12px;border:1px solid #eee;">
                        <div style="font-size:12px;font-weight:700;color:#333;margin-bottom:6px;">Rate this order</div>
                        <select id="rate_${o.orderID}" style="width:100%;padding:8px;border-radius:8px;margin-bottom:8px;">
                            <option value="">Stars…</option>
                            <option value="5">5 — Excellent</option>
                            <option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option>
                        </select>
                        <textarea id="txt_${o.orderID}" rows="2" placeholder="Optional comment" style="width:100%;border-radius:8px;padding:8px;border:1px solid #ddd;font-size:12px;"></textarea>
                        <button type="button" onclick="submitOrderReview(${o.orderID})" style="margin-top:8px;width:100%;padding:8px;border:none;border-radius:10px;background:linear-gradient(135deg,#0f7b55,#1dbf73);color:#fff;font-weight:700;cursor:pointer;">Submit review</button>
                    </div>`;
            } else if (st === 'completed' && o.hasReview) {
                reviewBlock = '<p style="margin-top:8px;font-size:12px;color:#0e7a4a;font-weight:700;">Review submitted — thank you!</p>';
            }
            const canTrack = Number.isFinite(parseFloat(o.deliveryLat)) && Number.isFinite(parseFloat(o.deliveryLng)) && Number.isFinite(parseFloat(o.shopLat)) && Number.isFinite(parseFloat(o.shopLng));
            const trackingBlock = canTrack ? `
                    <div style="margin-top:10px;padding:10px;background:#f6fffa;border-radius:12px;border:1px solid #d9f5e7;">
                        <div style="font-size:12px;font-weight:800;color:#0f7b55;margin-bottom:6px;">Delivery tracking</div>
                        <div style="font-size:12px;color:#66746e;line-height:1.45;">From: ${escHtml(o.shopAddress || o.shopName || 'Seller location')}<br>To: ${escHtml(o.deliveryAddress || 'Your pinned checkout location')}</div>
                        <div id="studentTrackMap_${o.orderID}" class="student-track-map"></div>
                    </div>` : `
                    <p style="margin-top:8px;font-size:12px;color:#888;">Tracking map appears when both your checkout location and the seller location are set.</p>`;
            return `
                <div style="border:1px solid #eee;border-radius:14px;padding:14px;margin-bottom:12px;background:#fff;">
                    <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;">
                        <div>
                            <b style="font-size:14px;">${escHtml(o.shopName || 'Shop #' + o.shopID)}</b>
                            <div style="font-size:12px;color:#888;margin-top:4px;">Order #${o.orderID} · ${escHtml(o.orderedAt || '')}</div>
                        </div>
                        <span style="font-size:11px;font-weight:800;text-transform:uppercase;padding:4px 8px;border-radius:8px;background:${stColor};color:#fff;">${escHtml(o.orderStatus || '')}</span>
                    </div>
                    <div style="margin-top:8px;font-size:13px;">Total: <b style="color:#2ecc71;">₱${parseFloat(o.totalAmount).toFixed(2)}</b> · ${escHtml(o.paymentMethod || '')}</div>
                    ${trackingBlock}
                    ${reviewBlock}
                </div>`;
        }).join('');
        requestAnimationFrame(function () {
            setTimeout(function () { renderStudentTrackingMaps(data.orders || []); }, 160);
        });
    }

    const studentTrackingMaps = new Map();

    function destroyStudentTrackingMaps() {
        studentTrackingMaps.forEach(function (map) {
            map.remove();
        });
        studentTrackingMaps.clear();
    }

    function renderStudentTrackingMaps(orders) {
        if (!window.L) return;
        orders.forEach(function (o) {
            const el = document.getElementById('studentTrackMap_' + o.orderID);
            if (!el || studentTrackingMaps.has(String(o.orderID))) return;
            const delivery = [parseFloat(o.deliveryLat), parseFloat(o.deliveryLng)];
            const shop = [parseFloat(o.shopLat), parseFloat(o.shopLng)];
            if (!Number.isFinite(delivery[0]) || !Number.isFinite(delivery[1]) || !Number.isFinite(shop[0]) || !Number.isFinite(shop[1])) return;
            const map = L.map(el, { zoomControl: false, attributionControl: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            L.marker(shop).addTo(map).bindPopup('Seller');
            L.marker(delivery).addTo(map).bindPopup('Delivery');
            L.polyline([shop, delivery], { color: '#0f7b55', weight: 4, opacity: 0.85 }).addTo(map);
            map.fitBounds([shop, delivery], { padding: [24, 24] });
            studentTrackingMaps.set(String(o.orderID), map);
            setTimeout(function () {
                map.invalidateSize();
                map.fitBounds([shop, delivery], { padding: [24, 24] });
            }, 240);
        });
    }

    async function submitOrderReview(orderID) {
        const sel = document.getElementById('rate_' + orderID);
        const tx = document.getElementById('txt_' + orderID);
        const rating = parseFloat(sel && sel.value ? sel.value : '0');
        if (!rating) {
            alert('Please choose a star rating.');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'submitReview');
        fd.append('orderID', orderID);
        fd.append('rating', String(rating));
        fd.append('reviewText', tx ? tx.value : '');
        const res = await fetch('student.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'Could not save review.');
            return;
        }
        openOrdersModal();
    }

    /* ── Open cart modal ── */
    async function openCartModal() {
        document.getElementById('cartModal').style.display = 'flex';
        document.getElementById('cartItemsList').innerHTML = '<p style="color:#999;">Loading...</p>';

        const res  = await fetch('student.php?action=getCart');
        const data = await res.json();

        if (!data.items || data.items.length === 0) {
            document.getElementById('cartItemsList').innerHTML = '<p style="color:#999; text-align:center; padding:20px;">Your cart is empty.</p>';
            document.getElementById('cartTotal').textContent = '';
            document.getElementById('checkoutBtn').style.display = 'none';
            const badge = document.getElementById('cartBadge');
            if (badge) {
                badge.textContent = '0';
                badge.style.display = 'none';
            }
            return;
        }

        let total = 0;
        let activeShop = '';
        const sortedCartItems = data.items.slice().sort((a, b) => String(a.shopName || '').localeCompare(String(b.shopName || '')));
        document.getElementById('cartItemsList').innerHTML = sortedCartItems.map(item => {
            const subtotal = parseFloat(item.itemPrice) * parseInt(item.quantity);
            total += subtotal;
            const shopTitle = String(item.shopName || 'Shop');
            const groupHeader = shopTitle !== activeShop ? `<div class="student-cart-shop-title">${escHtml(shopTitle)}</div>` : '';
            activeShop = shopTitle;
            return `
            ${groupHeader}
            <div style="display:flex; align-items:flex-start; gap:12px; padding:10px 0; border-bottom:1px solid #f5f5f5;">
                <img src="${item.itemImage || 'https://via.placeholder.com/50'}"
                     style="width:50px; height:50px; border-radius:10px; object-fit:cover;">
                <div style="flex:1;">
                    <b style="font-size:13px;">${escHtml(item.itemName)}</b>
                    <div style="font-size:12px; color:#999;">${escHtml(item.shopName)} · x${item.quantity}</div>
                    <label style="display:block;font-size:11px;color:#666;margin-top:6px;">Note for seller</label>
                    <textarea data-cart-id="${item.cartID}" rows="2" class="cart-line-note"
                        style="width:100%;margin-top:4px;font-size:12px;border:1px solid #eee;border-radius:8px;padding:6px;resize:vertical;"></textarea>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:13px; font-weight:700; color:#2ecc71;">₱${subtotal.toFixed(2)}</div>
                    <button onclick="removeFromCart(${item.cartID})"
                            style="background:none; border:none; color:#ff4d4d; font-size:11px; cursor:pointer; margin-top:3px;">
                        Remove
                    </button>
                </div>
            </div>`;
        }).join('');
        document.querySelectorAll('.cart-line-note').forEach((ta, idx) => {
            const it = sortedCartItems[idx];
            if (it) ta.value = it.lineNote || '';
            ta.addEventListener('change', function () {
                saveCartLineNote(parseInt(this.getAttribute('data-cart-id'), 10), this.value);
            });
        });

        document.getElementById('cartTotal').innerHTML = `Total: <span style="color:#2ecc71;">₱${total.toFixed(2)}</span>`;
        document.getElementById('checkoutBtn').style.display = 'block';

        // Store cart for checkout
        sessionStorage.setItem('cartTotal', total.toFixed(2));
        sessionStorage.setItem('cartItems', JSON.stringify(data.items));
    }

    function closeCartModal() {
        document.getElementById('cartModal').style.display = 'none';
    }

    /* ── Remove from cart ── */
    async function removeFromCart(cartID) {
        const fd = new FormData();
        fd.append('action', 'removeFromCart');
        fd.append('cartID', cartID);

        await fetch('student.php', { method: 'POST', body: fd });
        openCartModal(); // Refresh cart

        // Update badge
        const res  = await fetch('student.php?action=getCart');
        const data = await res.json();
        const badge = document.getElementById('cartBadge');
        const total = data.items ? data.items.reduce((s, i) => s + parseInt(i.quantity), 0) : 0;
        badge.textContent = total;
        badge.style.display = total > 0 ? 'block' : 'none';
    }

    /* ── Proceed to checkout ── */
    function goToCheckout() {
        location.href = 'payment.php';
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function studentOpenProfilePhoto(src) {
        if (!src) return;
        const box = document.getElementById('studentPhotoLightbox');
        const img = document.getElementById('studentPhotoLightboxImg');
        if (!box || !img) return;
        img.src = src;
        box.classList.add('open');
        box.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function studentCloseProfilePhoto(ev) {
        if (ev && ev.target && ev.target.id === 'studentPhotoLightboxImg') return;
        const box = document.getElementById('studentPhotoLightbox');
        if (!box) return;
        box.classList.remove('open');
        box.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        const im = document.getElementById('studentPhotoLightboxImg');
        if (im) im.src = '';
    }
    function studentOpenPostImageViewer(src) {
        if (!src) return;
        const box = document.getElementById('studentPostImageViewer');
        const img = document.getElementById('studentPostImageViewerImg');
        if (!box || !img) return;
        img.src = src;
        box.classList.add('open');
        box.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function studentClosePostImageViewer(ev) {
        if (ev) ev.stopPropagation();
        const box = document.getElementById('studentPostImageViewer');
        const img = document.getElementById('studentPostImageViewerImg');
        if (!box || !img) return;
        box.classList.remove('open');
        box.setAttribute('aria-hidden', 'true');
        img.src = '';
        document.body.style.overflow = '';
    }
    document.addEventListener('click', function (event) {
        const img = event.target.closest('.student-post-preview-image');
        if (!img) return;
        event.preventDefault();
        event.stopPropagation();
        studentOpenPostImageViewer(img.currentSrc || img.src);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            studentCloseProfilePhoto();
            studentClosePostImageViewer(e);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        applyStudentShopSort();
    });

    // ── Post composer: image preview and submit to profile.php ──
    document.getElementById('postImageInput').addEventListener('change', function (e) {
        const files = Array.from(e.target.files || []);
        if (!files.length) return clearPostPreview();
        document.getElementById('postImagePreviewGrid').innerHTML = files.map(file => `<img src="${URL.createObjectURL(file)}" alt="Selected image">`).join('');
        document.getElementById('postImagePreviewBox').style.display = 'block';
    });

    function clearPostPreview() {
        const fi = document.getElementById('postImageInput');
        fi.value = '';
        document.getElementById('postImagePreviewGrid').innerHTML = '';
        document.getElementById('postImagePreviewBox').style.display = 'none';
    }

    async function submitPost() {
        const content = document.getElementById('postContentInput').value.trim();
        const fileInput = document.getElementById('postImageInput');
        if (!content && (!fileInput.files || fileInput.files.length === 0)) return alert('Please write something or add an image.');
        const submitButton = document.getElementById('studentSubmitPostBtn');
        if (submitButton && submitButton.disabled) return;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'POSTING...';
            submitButton.style.opacity = '0.75';
            submitButton.style.cursor = 'not-allowed';
        }

        const fd = new FormData();
        fd.append('action', 'submitPost');
        fd.append('postContent', content);
        Array.from(fileInput.files || []).forEach(file => fd.append('postImage[]', file));

        let data;
        try {
            const res = await fetch('profile.php', { method: 'POST', body: fd });
            const txt = await res.text();
            data = JSON.parse(txt);
        } catch (err) {
            alert('Could not publish the post. Please try again.');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Post';
                submitButton.style.opacity = '';
                submitButton.style.cursor = 'pointer';
            }
        }

        if (!data) return;
        if (!data.success) {
            alert(data && data.message ? data.message : 'Could not publish the post.');
            return;
        }

        // Clear inputs
        document.getElementById('postContentInput').value = '';
        clearPostPreview();

        // Prepend to latest posts area (simple card)
        const lp = document.querySelector('.post-latest');
        if (lp) {
            const posterName = data.poster_name || data.userEMAIL || 'You';
            const posterPic = data.poster_pic || ('https://ui-avatars.com/api/?name=' + encodeURIComponent(posterName) + '&background=1dbf73&color=fff');
            const postImages = Array.isArray(data.postIMAGES) ? data.postIMAGES : (data.postIMAGE ? [data.postIMAGE] : []);
            const pImg = postImages.length ? `<div class="student-post-image-grid">${postImages.map(src => `<img class="student-post-preview-image" src="${escHtml(src)}" onerror="this.style.display='none'">`).join('')}</div>` : '';
            const raw = (data.postCONTENT || '').toString();
            const txt = escHtml(raw.length > 120 ? raw.substr(0,117) + '...' : raw);
            const when = data.postDATE || '';
            const html = `
                <a href="feed.php?post=${data.postID}" style="text-decoration:none;color:inherit;display:block;margin-top:12px;padding:10px;border-radius:14px;background:#faf8ff;border:1px solid #eee;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <img src="${posterPic}" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" onerror="this.src='https://ui-avatars.com/api/?name=U&background=1dbf73&color=fff'">
                        <span style="font-size:13px;font-weight:700;color:#333;">${escHtml(posterName)}</span>
                    </div>
                    ${pImg}
                    <p style="font-size:13px;color:#333;line-height:1.45;">${txt}</p>
                    <span style="font-size:11px;color:#999;">${escHtml(when)}</span>
                </a>`;
            // Remove placeholder message if exists
            const noMsg = document.getElementById('noPostsMsg'); if (noMsg) noMsg.remove();
            lp.insertAdjacentHTML('afterbegin', html);
        }
    }

</script>

</body>
</html>
