<?php
// THIS FILE RUNS THE ADMIN DASHBOARD, INCLUDING SHOP REVIEWS, SELLER APPROVALS, USER BANS, AUDIT LOGS, REPORTS, AND LIVE AJAX ACTIONS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// USE THE MAIN DATABASE FOR USERS/ORDERS AND SELLER_DATA FOR SHOP RECORDS.
$conn = db_connect(DB_NAME_ANIMEALS);
$connSeller = db_connect(DB_NAME_SELLER_DATA);
require_once __DIR__ . '/schema_bootstrap.php';
// PATCH ANY MISSING COLUMNS/TABLES BEFORE ADMIN COUNTS OR ACTIONS RUN.
animeals_ensure_extensions($conn);
if ($connSeller) {
    seller_data_ensure_shop_type($connSeller);
}

$stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$_SESSION['email']]);
if ($stmt === false) {
    die('Database error');
}
$user = db_fetch_assoc($stmt);
if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if (strtolower(trim((string) ($user['userROLE'] ?? ''))) !== 'admin') {
    // NON-ADMIN USERS SHOULD NEVER SEE THIS PAGE EVEN IF THEY TYPE THE URL DIRECTLY.
    header("Location: student.php");
    exit();
}

/** RUN A QUERY AND RETURN THE FIRST ROW, OR NULL IF CONNECTION/QUERY FAILS. */
function admin_query_row($connection, string $sql, array $params = []): ?array
{
    if ($connection === false) return null;
    $st = db_query($connection, $sql, $params);
    if ($st === false) return null;
    return db_fetch_assoc($st) ?: null;
}

function admin_row_int(?array $row, string $key): int
{
    // READ COUNT VALUES DEFENSIVELY EVEN WHEN MYSQL RETURNS DIFFERENT KEY CASING.
    if ($row === null) {
        return 0;
    }
    if (array_key_exists($key, $row)) {
        return (int) $row[$key];
    }
    foreach ($row as $k => $v) {
        if (strcasecmp((string) $k, $key) === 0) {
            return (int) $v;
        }
    }
    return 0;
}

function admin_row_float(?array $row, string $key): float
{
    // READ MONEY/REVENUE VALUES DEFENSIVELY EVEN WHEN MYSQL RETURNS DIFFERENT KEY CASING.
    if ($row === null) {
        return 0.0;
    }
    if (array_key_exists($key, $row)) {
        return (float) $row[$key];
    }
    foreach ($row as $k => $v) {
        if (strcasecmp((string) $k, $key) === 0) {
            return (float) $v;
        }
    }
    return 0.0;
}

function admin_audit(mysqli $conn, array $actor, ?array $target, string $action, string $details = ''): void
{
    // RECORD ADMIN ACTIONS SO SELLER REVIEWS AND USER BANS HAVE A HISTORY.
    db_query(
        $conn,
        "INSERT INTO user_audit_log (actorID, actorEmail, targetUserID, targetEmail, action, details) VALUES (?, ?, ?, ?, ?, ?)",
        [
            isset($actor['userID']) ? (int) $actor['userID'] : null,
            $actor['userEMAIL'] ?? null,
            isset($target['userID']) ? (int) $target['userID'] : null,
            $target['userEMAIL'] ?? null,
            $action,
            $details
        ]
    );
}

function admin_doc_link(?string $path, string $label): string
{
    // SHOW A SAFE DOCUMENT LINK OR A SMALL MISSING-DOCUMENT NOTE.
    $path = trim((string) $path);
    if ($path === '') {
        return '<span style="color:#94a3b8;font-size:12px;">Missing ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return '<a href="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

function admin_is_ajax(): bool
{
    // FETCH REQUESTS ASK FOR JSON; NORMAL FORM SUBMITS STILL USE REDIRECTS.
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function admin_finish(array $payload, string $fallbackLocation = 'admin.php'): never
{
    // RETURN JSON FOR THE SEAMLESS ADMIN UI, OTHERWISE FALL BACK TO CLASSIC REDIRECTS.
    if (admin_is_ajax()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['success' => true], $payload));
        exit();
    }
    header('Location: ' . $fallbackLocation);
    exit();
}

/* -- HANDLE ACTIONS -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ALL ADMIN BUTTONS POST HERE, THEN THE RESPONSE UPDATES THE PAGE WITHOUT RELOADING.
    $action = $_POST['action'] ?? '';

    // APPROVE A SHOP SO IT CAN APPEAR AS VERIFIED IN SELLER MANAGEMENT.
    if ($action === 'approveShop' && $connSeller !== false) {
        $shopID = (int)$_POST['shopID'];
        db_query($connSeller, "UPDATE seller_shops SET isApproved = 1 WHERE shopID = ?", [$shopID]);
        admin_audit($conn, $user, null, 'shop_approved', 'Approved shop ID ' . $shopID);
        admin_finish(['action' => $action, 'shopID' => $shopID, 'status' => 'approved', 'auditAction' => 'shop_approved', 'auditDetails' => 'Approved shop ID ' . $shopID, 'actorEmail' => $user['userEMAIL'] ?? '']);
    }

    // REJECTING A SHOP REMOVES THE SHOP RECORD FROM SELLER_DATA.
    if ($action === 'rejectShop' && $connSeller !== false) {
        $shopID = (int)$_POST['shopID'];
        db_query($connSeller, "DELETE FROM seller_shops WHERE shopID = ?", [$shopID]);
        admin_audit($conn, $user, null, 'shop_rejected', 'Rejected/deleted shop ID ' . $shopID);
        admin_finish(['action' => $action, 'shopID' => $shopID, 'removed' => true, 'auditAction' => 'shop_rejected', 'auditDetails' => 'Rejected/deleted shop ID ' . $shopID, 'actorEmail' => $user['userEMAIL'] ?? '']);
    }

    if (($action === 'approveSeller' || $action === 'rejectSeller')) {
        // REVIEW SELLER DOCUMENTS AND STORE THE ADMIN DECISION ON THE USER RECORD.
        $sellerID = (int) ($_POST['userID'] ?? 0);
        $note = trim((string) ($_POST['reviewNote'] ?? ''));
        $targetStmt = db_query($conn, "SELECT * FROM user_details WHERE userID = ? AND userROLE = 'seller' LIMIT 1", [$sellerID]);
        $target = $targetStmt ? db_fetch_assoc($targetStmt) : null;
        if ($target) {
            $newStatus = $action === 'approveSeller' ? 'approved' : 'rejected';
            db_query(
                $conn,
                "UPDATE user_details SET sellerApprovalStatus = ?, sellerReviewNote = ?, sellerReviewedAt = CURRENT_TIMESTAMP, sellerReviewedBy = ? WHERE userID = ?",
                [$newStatus, $note !== '' ? $note : null, (int) $user['userID'], $sellerID]
            );
            if ($connSeller !== false) {
                db_query(
                    $connSeller,
                    "UPDATE user_details SET userBUSINESSPERMIT = ?, userVALIDID = ?, userADMINDOC = ? WHERE userEMAIL = ?",
                    [$target['userBUSINESSPERMIT'] ?? '', $target['userVALIDID'] ?? '', $target['userADMINDOC'] ?? '', $target['userEMAIL']]
                );
            }
            admin_audit($conn, $user, $target, $action === 'approveSeller' ? 'seller_approved' : 'seller_rejected', $note);
            admin_finish([
                'action' => $action,
                'userID' => $sellerID,
                'status' => $newStatus,
                'note' => $note,
                'targetEmail' => $target['userEMAIL'] ?? '',
                'auditAction' => $action === 'approveSeller' ? 'seller_approved' : 'seller_rejected',
                'auditDetails' => $note,
                'actorEmail' => $user['userEMAIL'] ?? '',
            ], 'admin.php#admin-seller-review');
        }
        admin_finish(['action' => $action, 'userID' => $sellerID, 'success' => false, 'message' => 'Seller not found.'], 'admin.php#admin-seller-review');
    }

    // BAN A USER ACCOUNT FROM USING THE SITE.
    if ($action === 'banUser') {
        $userID = (int)$_POST['userID'];
        $targetStmt = db_query($conn, "SELECT * FROM user_details WHERE userID = ? LIMIT 1", [$userID]);
        $target = $targetStmt ? db_fetch_assoc($targetStmt) : null;
        db_query($conn, "UPDATE user_details SET isBanned = 1 WHERE userID = ?", [$userID]);
        admin_audit($conn, $user, $target, 'user_banned', 'Admin banned user.');
        admin_finish(['action' => $action, 'userID' => $userID, 'status' => 'banned', 'targetEmail' => $target['userEMAIL'] ?? '', 'auditAction' => 'user_banned', 'auditDetails' => 'Admin banned user.', 'actorEmail' => $user['userEMAIL'] ?? '']);
    }

    // RESTORE A BANNED USER ACCOUNT.
    if ($action === 'unbanUser') {
        $userID = (int)$_POST['userID'];
        $targetStmt = db_query($conn, "SELECT * FROM user_details WHERE userID = ? LIMIT 1", [$userID]);
        $target = $targetStmt ? db_fetch_assoc($targetStmt) : null;
        db_query($conn, "UPDATE user_details SET isBanned = 0 WHERE userID = ?", [$userID]);
        admin_audit($conn, $user, $target, 'user_unbanned', 'Admin unbanned user.');
        admin_finish(['action' => $action, 'userID' => $userID, 'status' => 'active', 'targetEmail' => $target['userEMAIL'] ?? '', 'auditAction' => 'user_unbanned', 'auditDetails' => 'Admin unbanned user.', 'actorEmail' => $user['userEMAIL'] ?? '']);
    }
}

// Stats
$studentCount = admin_row_int(admin_query_row($conn, "SELECT COUNT(*) AS c FROM user_details WHERE userROLE = 'student'"), 'c');
$sellerCount  = admin_row_int(admin_query_row($conn, "SELECT COUNT(*) AS c FROM user_details WHERE userROLE = 'seller' AND sellerApprovalStatus = 'approved'"), 'c');
$totalRevenue = admin_row_float(admin_query_row($conn, "SELECT SUM(totalAmount) AS t FROM orders WHERE orderStatus IN ('completed', 'ready')"), 't');
$pendingCount = admin_row_int(admin_query_row($conn, "SELECT COUNT(*) AS c FROM orders WHERE orderStatus = 'pending'"), 'c');

$revByDate = [];
$revStmt = db_query($conn,
    "SELECT DATE(orderedAt) AS d, SUM(totalAmount) AS amt
     FROM orders
     WHERE orderStatus IN ('completed', 'ready')
       AND orderedAt >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(orderedAt)"
);
if ($revStmt) {
    $rows = db_fetch_all($revStmt);
    foreach ($rows as $r) {
        $dk = isset($r['d']) ? (is_string($r['d']) ? $r['d'] : $r['d']->format('Y-m-d')) : '';
        $revByDate[$dk] = (float) ($r['amt'] ?? 0);
    }
}
$chartSeries = [];
for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-{$i} days");
    $key = date('Y-m-d', $ts);
    $sales = (float) ($revByDate[$key] ?? 0.0);
    $chartSeries[] = [
        'date' => $key,
        'label' => date('D', $ts) . ' ' . date('n/j', $ts),
        'commission' => round($sales * 0.10, 2),
        'sales' => round($sales, 2),
    ];
}

// Add isApproved column if not exists (run once)
// ALTER TABLE SELLER_SHOPS ADD isApproved BIT DEFAULT 0;
// ALTER TABLE USER_DETAILS ADD isBanned BIT DEFAULT 0;

$shopsStmt = $connSeller !== false ? db_query($connSeller,
    "SELECT ss.*, ud.userNAME as ownerName,
            COALESCE(SUM(CASE WHEN o.orderStatus IN ('completed', 'ready') THEN o.totalAmount ELSE 0 END), 0) AS totalSales
     FROM seller_shops ss
     LEFT JOIN animeals.user_details ud ON ud.userID = ss.sellerID
     LEFT JOIN animeals.orders o ON o.shopID = ss.shopID
     GROUP BY ss.shopID, ss.sellerID, ss.shopName, ss.shopDescription, ss.shopLogo, ss.shopStatus, ss.shopType, ss.shopLAT, ss.shopLNG, ss.shopADDRESS, ss.isApproved, ss.createdAt, ud.userNAME
     ORDER BY ss.createdAt DESC"
) : false;
$shops = $shopsStmt ? db_fetch_all($shopsStmt) : [];

$usersStmt = db_query($conn, "SELECT * FROM user_details ORDER BY userID DESC");
$users = $usersStmt ? db_fetch_all($usersStmt) : [];

$sellerReviewStmt = db_query(
    $conn,
    "SELECT * FROM user_details WHERE userROLE = 'seller' ORDER BY FIELD(sellerApprovalStatus, 'pending', 'rejected', 'approved'), createdAt DESC"
);
$sellerReviews = $sellerReviewStmt ? db_fetch_all($sellerReviewStmt) : [];

$commission = $totalRevenue * 0.10;

$orderStatusRows = [];
$osStmt = db_query($conn, "SELECT orderStatus, COUNT(*) AS cnt FROM orders GROUP BY orderStatus");
$orderStatusRows = $osStmt ? db_fetch_all($osStmt) : [];

$shopGrossRows = [];
$sgStmt = db_query(
    $conn,
    "SELECT ss.shopID, ss.shopName,
            COALESCE(SUM(CASE WHEN o.orderStatus IN ('completed', 'ready') THEN o.totalAmount ELSE 0 END), 0) AS gross
     FROM seller_data.seller_shops ss
     LEFT JOIN orders o ON o.shopID = ss.shopID
     GROUP BY ss.shopID, ss.shopName
     ORDER BY gross DESC
     LIMIT 10"
);
$shopGrossRows = $sgStmt ? db_fetch_all($sgStmt) : [];

$unverifiedShopCount = admin_row_int(
    admin_query_row($connSeller, "SELECT COUNT(*) AS c FROM seller_shops WHERE COALESCE(isApproved,0) = 0"),
    'c'
);

$bannedUserCount = admin_row_int(
    admin_query_row($conn, "SELECT COUNT(*) AS c FROM user_details WHERE COALESCE(isBanned,0) = 1"),
    'c'
);

$pendingSellerCount = admin_row_int(
    admin_query_row($conn, "SELECT COUNT(*) AS c FROM user_details WHERE userROLE = 'seller' AND sellerApprovalStatus = 'pending'"),
    'c'
);

$auditStmt = db_query($conn, "SELECT * FROM user_audit_log ORDER BY createdAt DESC LIMIT 80");
$auditRows = $auditStmt ? db_fetch_all($auditStmt) : [];

$reportsBadge = min(99, max(0, $unverifiedShopCount + $pendingSellerCount + (int) $pendingCount + $bannedUserCount));

$orderStatusChartData = array_map(static fn ($r) => [
    'status' => (string) ($r['orderStatus'] ?? 'unknown'),
    'count' => (int) ($r['cnt'] ?? 0),
], $orderStatusRows);

$shopGrossChartData = array_map(static fn ($r) => [
    'name' => (string) ($r['shopName'] ?? ''),
    'gross' => (float) ($r['gross'] ?? 0),
], $shopGrossRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS: Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0; padding: 0; box-sizing: border-box;
            font-family: 'Inter', 'Poppins', sans-serif;
        }

        body {
            background: #f0f2f5;
            height: 100vh;
            display: flex;
        }

        /* ========== SIDEBAR (Authority Blue) ========== */
        .sidebar {
            width: 280px;
            background: #1a222d;
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 25px 20px;
            height: 100vh;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #3b82f6;
        }

        .menu-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 15px;
        }

        .menu { display: flex; flex-direction: column; gap: 8px; }

        .menu a {
            text-decoration: none;
            color: #94a3b8;
            padding: 12px 15px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.3s;
        }

        .menu a:hover, .menu a.active {
            background: #3b82f6;
            color: #fff;
        }

        /* ========== MAIN CONTENT ========== */
        .main {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        /* ========== STAT CARDS ========== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }

        .stat-card i { font-size: 24px; color: #3b82f6; margin-bottom: 10px; }
        .stat-card h3 { font-size: 24px; color: #1e293b; }
        .stat-card p { color: #64748b; font-size: 14px; }

        /* ========== CHARTS SECTION ========== */
        .charts-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-box {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .chart-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 16px;
        }

        .chart-head h4 { margin: 0; }

        .chart-sort {
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-sort select {
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        /* ========== MANAGEMENT TABLE ========== */
        .data-section {
            background: #fff;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid #f1f5f9;
            color: #64748b;
            font-size: 14px;
        }

        td {
            padding: 15px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #334155;
        }

        .status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active { background: #dcfce7; color: #15803d; }
        .status-pending { background: #fef9c3; color: #854d0e; }
        .status-report { background: #fee2e2; color: #991b1b; }

        .action-btn {
            border: none; background: none; cursor: pointer; color: #64748b; font-size: 18px;
        }

        .btn-add {
            background: #3b82f6; color: #fff; padding: 10px 20px;
            border-radius: 8px; border: none; cursor: pointer; font-weight: 600;
        }

          .logout-btn { 
            margin-top: auto; 
            background: rgba(0,0,0,0.1); 
            text-align: center; 
            padding: 12px; 
            border-radius: 15px; 
            color: white; 
            text-decoration: none; 
            font-weight: 600; 
            transition: 0.3s;
        }
        .logout-btn:hover { background: #ff4d4d; }

        .admin-anchor { scroll-margin-top: 28px; }
        .menu a.admin-nav-link.active {
            background: #3b82f6;
            color: #fff;
        }
        .admin-profile-card {
            display: flex; align-items: center; gap: 16px;
        }
        .admin-profile-card img {
            width: 64px; height: 64px; border-radius: 50%; object-fit: cover;
        }
        .report-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
        .report-card {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;
        }
        .report-card h4 { font-size: 13px; color: #64748b; margin-bottom: 6px; }
        .report-card p { font-size: 22px; font-weight: 800; color: #1e293b; }
        .doc-links { display:flex; flex-wrap:wrap; gap:8px; }
        .doc-links a { color:#2563eb; font-weight:700; text-decoration:none; font-size:12px; padding:6px 9px; border-radius:8px; background:#eff6ff; }
        .review-note { width:180px; max-width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:7px; font-size:12px; }
        .audit-detail { max-width:340px; color:#64748b; font-size:12px; line-height:1.4; }
        .admin-toast {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 1000;
            min-width: 220px;
            max-width: 340px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #1e293b;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.22);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .admin-toast.show { opacity: 1; transform: translateY(0); }
        .admin-toast.error { background: #991b1b; }
        form.is-saving .action-btn { opacity: 0.45; cursor: wait; }

    </style>
</head>
<body>

    <div class="sidebar">
        <div class="brand">
            <i class="bi bi-shield-lock-fill"></i> ADMINS
        </div>

        <p class="menu-label">Main Menu</p>
        <div class="menu">
            <a href="#admin-profile" class="admin-nav-link" data-target="admin-profile"><i class="bi bi-person-badge"></i> Profile</a>
            <a href="#admin-overview" class="admin-nav-link active" data-target="admin-overview"><i class="bi bi-speedometer2"></i> Overview</a>
            <a href="#admin-shops" class="admin-nav-link" data-target="admin-shops"><i class="bi bi-shop"></i> Manage Shops</a>
            <a href="#admin-seller-review" class="admin-nav-link" data-target="admin-seller-review"><i class="bi bi-file-earmark-check"></i> Review Sellers
                <?php if ($pendingSellerCount > 0): ?><span data-admin-badge="pending-sellers" style="background:#ef4444; color:white; border-radius:50%; padding:2px 7px; font-size:10px; margin-left:auto;"><?= (int) $pendingSellerCount ?></span><?php endif; ?>
            </a>
            <a href="#admin-users" class="admin-nav-link" data-target="admin-users"><i class="bi bi-people"></i> Users List</a>
            <a href="#admin-audit" class="admin-nav-link" data-target="admin-audit"><i class="bi bi-clipboard-data"></i> User Audit</a>
            <a href="#admin-reports" class="admin-nav-link" data-target="admin-reports"><i class="bi bi-flag"></i> Reports
                <?php if ($reportsBadge > 0): ?><span data-admin-badge="reports" style="background:#ef4444; color:white; border-radius:50%; padding:2px 7px; font-size:10px; margin-left:auto;"><?= (int) $reportsBadge ?></span><?php endif; ?>
            </a>
        </div>

        <p class="menu-label" style="margin-top:30px;">Finance</p>
        <div class="menu">
            <a href="#admin-commissions" class="admin-nav-link" data-target="admin-commissions"><i class="bi bi-cash-stack"></i> Commissions</a>
            <a href="#admin-analytics" class="admin-nav-link" data-target="admin-analytics"><i class="bi bi-graph-up-arrow"></i> Analytics</a>
        </div>

        <a href="logout.php" class="logout-btn" style="margin-top:auto; text-decoration:none; color:#ef4444; display:flex; align-items:center; gap:8px; padding:12px;">
            <i class="bi bi-box-arrow-right"></i> Log out
        </a>
    </div>

    <div class="main">
        <section id="admin-overview" class="admin-anchor">
        <div class="header">
            <div>
                <h1 style="color: #1e293b;">OVERVIEW</h1>
                <p style="color: #64748b;">Welcome back, <?= htmlspecialchars($user['userNAME']) ?>.</p>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <a href="export.php?report=all_orders&amp;format=csv" class="btn-add" style="text-decoration:none;display:inline-block;">Orders CSV</a>
                <a href="export.php?report=all_orders&amp;format=pdf" class="btn-add" style="text-decoration:none;display:inline-block;background:#334155;">Orders PDF</a>
                <a href="export.php?report=users&amp;format=csv" class="btn-add" style="text-decoration:none;display:inline-block;background:#0ea5e9;">Users CSV</a>
                <a href="export.php?report=users&amp;format=pdf" class="btn-add" style="text-decoration:none;display:inline-block;background:#0284c7;">Users PDF</a>
                <a href="export.php?report=shops&amp;format=csv" class="btn-add" style="text-decoration:none;display:inline-block;background:#6366f1;">Shops CSV</a>
                <a href="export.php?report=shops&amp;format=pdf" class="btn-add" style="text-decoration:none;display:inline-block;background:#4f46e5;">Shops PDF</a>
            </div>
        </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <i class="bi bi-people"></i>
                    <h3><?= number_format($studentCount) ?></h3>
                    <p>Total Students</p>
                </div>
                <div class="stat-card">
                    <i class="bi bi-shop-window"></i>
                    <h3 data-admin-count="active-sellers"><?= number_format($sellerCount) ?></h3>
                    <p>Active Sellers</p>
                </div>
                <div class="stat-card">
                    <i class="bi bi-wallet2"></i>
                    <h3>₱<?= number_format($commission, 2) ?></h3>
                    <p>Commission Revenue (10%)</p>
                </div>
                <div class="stat-card">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h3><?= number_format($pendingCount) ?></h3>
                    <p>Pending Orders</p>
                </div>
            </div>

        <div class="charts-container">
            <div class="chart-box">
                <div class="chart-head">
                    <h4>Revenue Growth (Commission 10%)</h4>
                    <label class="chart-sort">Sort
                        <select id="sortRevenueChart" aria-label="Sort revenue chart">
                            <option value="date_asc">Date (oldest first)</option>
                            <option value="date_desc">Date (newest first)</option>
                            <option value="comm_desc">Commission (high → low)</option>
                            <option value="comm_asc">Commission (low → high)</option>
                        </select>
                    </label>
                </div>
                <canvas id="revenueChart" height="150"></canvas>
            </div>
            <div class="chart-box">
                <div class="chart-head">
                    <h4>User Distribution</h4>
                    <label class="chart-sort">Sort
                        <select id="sortUserChart" aria-label="Sort user chart">
                            <option value="role_order">Role (students first)</option>
                            <option value="count_desc">Count (high → low)</option>
                            <option value="count_asc">Count (low → high)</option>
                            <option value="name_asc">Label (A–Z)</option>
                        </select>
                    </label>
                </div>
                <canvas id="userChart"></canvas>
            </div>
        </div>
        </section>

        <section id="admin-profile" class="admin-anchor data-section" style="margin-bottom:24px;">
            <h3 style="margin-bottom:12px;">Admin profile</h3>
            <div class="admin-profile-card">
                <img src="<?= htmlspecialchars($user['userPROFILEPIC'] ?? '') ?: 'https://cdn-icons-png.flaticon.com/512/149/149071.png' ?>" alt="">
                <div>
                    <p style="font-size:18px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($user['userNAME'] ?? '') ?></p>
                    <p style="color:#64748b;font-size:14px;"><?= htmlspecialchars($user['userEMAIL'] ?? '') ?></p>
                    <p style="margin-top:8px;"><span class="status status-active"><?= htmlspecialchars(ucfirst($user['userROLE'] ?? 'admin')) ?></span></p>
                    <p style="margin-top:10px;font-size:13px;color:#64748b;">Use the sidebar to jump to shops, users, reports, commissions, and extra charts.</p>
                </div>
            </div>
        </section>

        <section id="admin-shops" class="admin-anchor data-section">
            <div class="table-header">
                <h3>Shop Management & Verifications</h3>
                <div class="search" style="border: 1px solid #e2e8f0; padding: 5px 15px; border-radius: 8px;">
                    <i class="bi bi-search"></i>
                    <input type="text" id="adminShopSearch" placeholder="Search shop..." style="border:none; outline:none; padding:5px;width:220px;">
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Shop Name</th>
                        <th>Owner</th>
                        <th>Commission Paid</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                    <tbody id="admin-shops-tbody">
                    <?php foreach ($shops as $shop): ?>
                    <tr class="admin-shop-row" data-shop-id="<?= (int) ($shop['shopID'] ?? 0) ?>" data-search="<?= htmlspecialchars(strtolower(($shop['shopName'] ?? '') . ' ' . ($shop['ownerName'] ?? ''))) ?>">
                        <td><b><?= htmlspecialchars($shop['shopName']) ?></b></td>
                        <td><?= htmlspecialchars($shop['ownerName'] ?? 'Unknown') ?></td>
                        <td>₱<?= number_format((float)($shop['totalSales'] ?? 0) * 0.10, 2) ?></td>
                        <td class="admin-shop-status">
                            <?php if ($shop['isApproved']): ?>
                                <span class="status status-active">Verified</span>
                            <?php else: ?>
                                <span class="status status-pending">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="admin-shop-actions">
                            <?php if (!$shop['isApproved']): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="approveShop">
                                    <input type="hidden" name="shopID" value="<?= $shop['shopID'] ?>">
                                    <button class="action-btn" style="color:#22c55e;" title="Approve"><i class="bi bi-check-circle-fill"></i></button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="rejectShop">
                                    <input type="hidden" name="shopID" value="<?= $shop['shopID'] ?>">
                                    <button class="action-btn" style="color:#ef4444;" title="Reject"><i class="bi bi-x-circle-fill"></i></button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="rejectShop">
                                    <input type="hidden" name="shopID" value="<?= $shop['shopID'] ?>">
                                    <button class="action-btn" style="color:#ef4444;" title="Remove"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($shops)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#999; padding:20px;">No shops registered yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <section id="admin-seller-review" class="admin-anchor data-section" style="margin-top:25px;">
            <div class="table-header">
                <div>
                    <h3>Review Seller Applications</h3>
                    <p style="color:#64748b;font-size:14px;margin-top:4px;">Inspect uploaded business permits and valid IDs before allowing seller dashboard access.</p>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Seller</th>
                        <th>Shop</th>
                        <th>Documents</th>
                        <th>Status</th>
                        <th>Admin note</th>
                        <th>Decision</th>
                    </tr>
                </thead>
                <tbody id="admin-seller-review-tbody">
                    <?php foreach ($sellerReviews as $sellerRow): ?>
                    <?php $approval = strtolower(trim((string) ($sellerRow['sellerApprovalStatus'] ?? 'pending'))); ?>
                    <tr class="admin-seller-review-row" data-seller-id="<?= (int) ($sellerRow['userID'] ?? 0) ?>">
                        <td>
                            <b><?= htmlspecialchars((string) ($sellerRow['userNAME'] ?? '')) ?></b><br>
                            <span style="color:#64748b;font-size:12px;"><?= htmlspecialchars((string) ($sellerRow['userEMAIL'] ?? '')) ?></span>
                        </td>
                        <td><?= htmlspecialchars((string) ($sellerRow['userSHOPNAME'] ?? '')) ?></td>
                        <td>
                            <div class="doc-links">
                                <?= admin_doc_link($sellerRow['userBUSINESSPERMIT'] ?? '', 'Business permit') ?>
                                <?= admin_doc_link($sellerRow['userVALIDID'] ?? '', 'Valid ID') ?>
                            </div>
                        </td>
                        <td class="admin-seller-review-status">
                            <?php if ($approval === 'approved'): ?>
                                <span class="status status-active">Approved</span>
                            <?php elseif ($approval === 'rejected'): ?>
                                <span class="status status-report">Rejected</span>
                            <?php else: ?>
                                <span class="status status-pending">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="admin-seller-review-note" style="color:#64748b;font-size:12px;"><?= htmlspecialchars((string) ($sellerRow['sellerReviewNote'] ?? '')) ?></td>
                        <td class="admin-seller-review-actions">
                            <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                <input type="hidden" name="userID" value="<?= (int) ($sellerRow['userID'] ?? 0) ?>">
                                <input class="review-note" type="text" name="reviewNote" placeholder="Optional note">
                                <button class="action-btn" name="action" value="approveSeller" style="color:#22c55e;" title="Approve seller"><i class="bi bi-check-circle-fill"></i></button>
                                <button class="action-btn" name="action" value="rejectSeller" style="color:#ef4444;" title="Reject seller"><i class="bi bi-x-circle-fill"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sellerReviews)): ?>
                        <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px;">No seller accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <section id="admin-users" class="admin-anchor data-section" style="margin-top:25px;">
    <div class="table-header">
        <h3>Users List</h3>
        <div class="search" style="border: 1px solid #e2e8f0; padding: 5px 15px; border-radius: 8px;">
            <i class="bi bi-search"></i>
            <input type="text" id="adminUserSearch" placeholder="Search users..." style="border:none; outline:none; padding:5px;width:200px;">
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="admin-users-tbody">
            <?php foreach ($users as $u): ?>
            <tr class="admin-user-row" data-user-id="<?= (int) ($u['userID'] ?? 0) ?>" data-search="<?= htmlspecialchars(strtolower(($u['userNAME'] ?? '') . ' ' . ($u['userEMAIL'] ?? '') . ' ' . ($u['userROLE'] ?? ''))) ?>">
                <td><b><?= htmlspecialchars($u['userNAME']) ?></b></td>
                <td><?= htmlspecialchars($u['userEMAIL']) ?></td>
                <td><?= htmlspecialchars(ucfirst($u['userROLE'] ?? 'student')) ?></td>
                <td class="admin-user-status">
                    <?php if ($u['isBanned'] ?? 0): ?>
                        <span class="status status-report">Banned</span>
                    <?php else: ?>
                        <span class="status status-active">Active</span>
                    <?php endif; ?>
                </td>
                <td class="admin-user-actions">
                    <?php if ($u['isBanned'] ?? 0): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="unbanUser">
                            <input type="hidden" name="userID" value="<?= $u['userID'] ?>">
                            <button class="action-btn" style="color:#22c55e;" title="Unban"><i class="bi bi-check-circle"></i></button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="banUser">
                            <input type="hidden" name="userID" value="<?= $u['userID'] ?>">
                            <button class="action-btn" style="color:#ef4444;" title="Ban"><i class="bi bi-slash-circle"></i></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

        <section id="admin-audit" class="admin-anchor data-section" style="margin-top:25px;">
            <div class="table-header">
                <div>
                    <h3>User Audit</h3>
                    <p style="color:#64748b;font-size:14px;margin-top:4px;">Recent account and seller-review decisions made by admins.</p>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Actor</th>
                        <th>Target</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="admin-audit-tbody">
                    <?php foreach ($auditRows as $audit): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($audit['createdAt'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($audit['actorEmail'] ?? 'System')) ?></td>
                        <td><?= htmlspecialchars((string) ($audit['targetEmail'] ?? '')) ?></td>
                        <td><span class="status status-pending"><?= htmlspecialchars((string) ($audit['action'] ?? '')) ?></span></td>
                        <td><div class="audit-detail"><?= htmlspecialchars((string) ($audit['details'] ?? '')) ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($auditRows)): ?>
                        <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px;">No audit entries yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section id="admin-reports" class="admin-anchor data-section" style="margin-top:25px;">
            <h3 style="margin-bottom:14px;">Reports &amp; alerts</h3>
            <p style="color:#64748b;font-size:14px;margin-bottom:16px;">Live counts from your ANIMEALS database. Jump to the linked section to take action.</p>
            <div class="report-cards">
                <div class="report-card">
                    <h4>Unverified shops</h4>
                    <p data-admin-count="unverified-shops"><?= (int) $unverifiedShopCount ?></p>
                    <a href="#admin-shops" class="btn-add" style="display:inline-block;margin-top:10px;text-decoration:none;font-size:12px;padding:8px 14px;">Review shops</a>
                </div>
                <div class="report-card">
                    <h4>Pending seller reviews</h4>
                    <p data-admin-count="pending-sellers"><?= (int) $pendingSellerCount ?></p>
                    <a href="#admin-seller-review" class="btn-add" style="display:inline-block;margin-top:10px;text-decoration:none;font-size:12px;padding:8px 14px;background:#6366f1;">Review sellers</a>
                </div>
                <div class="report-card">
                    <h4>Pending customer orders</h4>
                    <p><?= (int) $pendingCount ?></p>
                    <span style="font-size:12px;color:#64748b;">Awaiting seller action</span>
                </div>
                <div class="report-card">
                    <h4>Banned accounts</h4>
                    <p data-admin-count="banned-users"><?= (int) $bannedUserCount ?></p>
                    <a href="#admin-users" class="btn-add" style="display:inline-block;margin-top:10px;text-decoration:none;font-size:12px;padding:8px 14px;background:#0ea5e9;">View users</a>
                </div>
            </div>
        </section>

        <section id="admin-commissions" class="admin-anchor data-section" style="margin-top:25px;">
            <h3 style="margin-bottom:8px;">Commissions (10% of completed / ready sales)</h3>
            <p style="color:#64748b;font-size:14px;margin-bottom:14px;">Per-shop gross sales and estimated platform commission.</p>
            <table>
                <thead>
                    <tr>
                        <th>Shop</th>
                        <th>Gross sales (₱)</th>
                        <th>Commission 10% (₱)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shopGrossRows as $gr): ?>
                    <tr>
                        <td><b><?= htmlspecialchars($gr['shopName'] ?? '') ?></b></td>
                        <td>₱<?= number_format((float) ($gr['gross'] ?? 0), 2) ?></td>
                        <td>₱<?= number_format((float) ($gr['gross'] ?? 0) * 0.10, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($shopGrossRows)): ?>
                    <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">No completed sales by shop yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section id="admin-analytics" class="admin-anchor data-section" style="margin-top:25px;">
            <h3 style="margin-bottom:8px;">Extended analytics</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;flex-wrap:wrap;">
                <div class="chart-box" style="box-shadow:0 4px 6px -1px rgba(0,0,0,0.08);">
                    <div class="chart-head">
                        <h4>Orders by status</h4>
                        <label class="chart-sort">Sort
                            <select id="sortAdminStatusChart" aria-label="Sort order status chart">
                                <option value="count_desc">Count (high → low)</option>
                                <option value="count_asc">Count (low → high)</option>
                                <option value="name_asc">Status (A–Z)</option>
                                <option value="name_desc">Status (Z–A)</option>
                            </select>
                        </label>
                    </div>
                    <canvas id="adminOrdersStatusChart" height="220"></canvas>
                </div>
                <div class="chart-box" style="box-shadow:0 4px 6px -1px rgba(0,0,0,0.08);">
                    <div class="chart-head">
                        <h4>Top shops by gross sales</h4>
                        <label class="chart-sort">Sort
                            <select id="sortAdminTopShopsChart" aria-label="Sort top shops chart">
                                <option value="gross_desc">Revenue (high → low)</option>
                                <option value="gross_asc">Revenue (low → high)</option>
                                <option value="name_asc">Shop name (A–Z)</option>
                                <option value="name_desc">Shop name (Z–A)</option>
                            </select>
                        </label>
                    </div>
                    <canvas id="adminTopShopsChart" height="220"></canvas>
                </div>
            </div>
        </section>
    </div>

    <script>
        const chartSeries = <?= json_encode($chartSeries, JSON_UNESCAPED_UNICODE) ?>;
        const userSlicesBase = [
            { label: 'Students', count: <?= (int) $studentCount ?>, color: '#3b82f6' },
            { label: 'Sellers', count: <?= (int) $sellerCount ?>, color: '#1e293b' }
        ];

        function sortRevenueSeries(mode) {
            const rows = chartSeries.map(function (r) { return Object.assign({}, r); });
            if (mode === 'date_desc') {
                rows.sort(function (a, b) { return b.date.localeCompare(a.date); });
            } else if (mode === 'date_asc') {
                rows.sort(function (a, b) { return a.date.localeCompare(b.date); });
            } else if (mode === 'comm_desc') {
                rows.sort(function (a, b) { return b.commission - a.commission; });
            } else if (mode === 'comm_asc') {
                rows.sort(function (a, b) { return a.commission - b.commission; });
            }
            return rows;
        }

        const revCtx = document.getElementById('revenueChart').getContext('2d');
        const initialRev = sortRevenueSeries('date_asc');
        const revenueChart = new Chart(revCtx, {
            type: 'line',
            data: {
                labels: initialRev.map(function (r) { return r.label; }),
                datasets: [{
                    label: 'Daily commission (10% of sales, ₱)',
                    data: initialRev.map(function (r) { return r.commission; }),
                    borderColor: '#3b82f6',
                    tension: 0.4,
                    fill: true,
                    backgroundColor: 'rgba(59, 130, 246, 0.1)'
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        document.getElementById('sortRevenueChart').addEventListener('change', function () {
            const rows = sortRevenueSeries(this.value);
            revenueChart.data.labels = rows.map(function (r) { return r.label; });
            revenueChart.data.datasets[0].data = rows.map(function (r) { return r.commission; });
            revenueChart.update();
        });

        function sortUserSlices(mode) {
            const arr = userSlicesBase.map(function (s) { return Object.assign({}, s); });
            if (mode === 'count_desc') {
                arr.sort(function (a, b) { return b.count - a.count; });
            } else if (mode === 'count_asc') {
                arr.sort(function (a, b) { return a.count - b.count; });
            } else if (mode === 'name_asc') {
                arr.sort(function (a, b) { return a.label.localeCompare(b.label); });
            }
            return arr;
        }

        const userCtx = document.getElementById('userChart').getContext('2d');
        const initialUsers = sortUserSlices('role_order');
        const userChart = new Chart(userCtx, {
            type: 'doughnut',
            data: {
                labels: initialUsers.map(function (s) { return s.label; }),
                datasets: [{
                    data: initialUsers.map(function (s) { return s.count; }),
                    backgroundColor: initialUsers.map(function (s) { return s.color; }),
                    borderWidth: 0
                }]
            },
            options: { cutout: '70%' }
        });

        document.getElementById('sortUserChart').addEventListener('change', function () {
            const rows = sortUserSlices(this.value);
            userChart.data.labels = rows.map(function (s) { return s.label; });
            userChart.data.datasets[0].data = rows.map(function (s) { return s.count; });
            userChart.data.datasets[0].backgroundColor = rows.map(function (s) { return s.color; });
            userChart.update();
        });

        const adminOrderStatusBase = <?= json_encode($orderStatusChartData, JSON_UNESCAPED_UNICODE) ?>;
        const adminTopShopsBase = <?= json_encode($shopGrossChartData, JSON_UNESCAPED_UNICODE) ?>;

        function sortStatusChartRows(mode) {
            const rows = adminOrderStatusBase.map(function (r) { return Object.assign({}, r); });
            if (mode === 'count_desc') rows.sort(function (a, b) { return b.count - a.count; });
            else if (mode === 'count_asc') rows.sort(function (a, b) { return a.count - b.count; });
            else if (mode === 'name_asc') rows.sort(function (a, b) { return String(a.status).localeCompare(String(b.status)); });
            else if (mode === 'name_desc') rows.sort(function (a, b) { return String(b.status).localeCompare(String(a.status)); });
            return rows;
        }

        function sortTopShopsRows(mode) {
            const rows = adminTopShopsBase.map(function (r) { return Object.assign({}, r); });
            if (mode === 'gross_desc') rows.sort(function (a, b) { return b.gross - a.gross; });
            else if (mode === 'gross_asc') rows.sort(function (a, b) { return a.gross - b.gross; });
            else if (mode === 'name_asc') rows.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
            else if (mode === 'name_desc') rows.sort(function (a, b) { return String(b.name).localeCompare(String(a.name)); });
            return rows;
        }

        const stRows = sortStatusChartRows('count_desc');
        const adminStatusCtx = document.getElementById('adminOrdersStatusChart');
        const adminStatusChart = adminStatusCtx ? new Chart(adminStatusCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: stRows.map(function (r) { return r.status; }),
                datasets: [{
                    label: 'Orders',
                    data: stRows.map(function (r) { return r.count; }),
                    backgroundColor: 'rgba(59, 130, 246, 0.65)',
                    borderRadius: 6
                }]
            },
            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        }) : null;

        const tsRows = sortTopShopsRows('gross_desc');
        const adminTopCtx = document.getElementById('adminTopShopsChart');
        const adminTopShopsChart = adminTopCtx ? new Chart(adminTopCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: tsRows.map(function (r) { return r.name || 'Shop'; }),
                datasets: [{
                    label: 'Gross sales (₱)',
                    data: tsRows.map(function (r) { return r.gross; }),
                    backgroundColor: 'rgba(30, 41, 59, 0.75)',
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                scales: { x: { beginAtZero: true } },
                plugins: { legend: { display: false } }
            }
        }) : null;

        const sortSt = document.getElementById('sortAdminStatusChart');
        if (sortSt && adminStatusChart) {
            sortSt.addEventListener('change', function () {
                const rows = sortStatusChartRows(this.value);
                adminStatusChart.data.labels = rows.map(function (r) { return r.status; });
                adminStatusChart.data.datasets[0].data = rows.map(function (r) { return r.count; });
                adminStatusChart.update();
            });
        }
        const sortTs = document.getElementById('sortAdminTopShopsChart');
        if (sortTs && adminTopShopsChart) {
            sortTs.addEventListener('change', function () {
                const rows = sortTopShopsRows(this.value);
                adminTopShopsChart.data.labels = rows.map(function (r) { return r.name || 'Shop'; });
                adminTopShopsChart.data.datasets[0].data = rows.map(function (r) { return r.gross; });
                adminTopShopsChart.update();
            });
        }

        function setActiveAdminNav(id) {
            document.querySelectorAll('.admin-nav-link').forEach(function (a) {
                a.classList.toggle('active', (a.getAttribute('data-target') || '') === id);
            });
        }
        document.querySelectorAll('.admin-nav-link').forEach(function (a) {
            a.addEventListener('click', function () {
                const id = this.getAttribute('data-target');
                if (id) setActiveAdminNav(id);
            });
        });
        window.addEventListener('hashchange', function () {
            const h = (location.hash || '#admin-overview').replace('#', '');
            if (h.indexOf('admin-') === 0) setActiveAdminNav(h);
        });
        (function () {
            const h = (location.hash || '#admin-overview').replace('#', '');
            if (h.indexOf('admin-') === 0) setActiveAdminNav(h);
        })();

        const shopSearch = document.getElementById('adminShopSearch');
        if (shopSearch) {
            shopSearch.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('.admin-shop-row').forEach(function (tr) {
                    const hay = (tr.getAttribute('data-search') || '');
                    tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
                });
            });
        }
        const userSearch = document.getElementById('adminUserSearch');
        if (userSearch) {
            userSearch.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('.admin-user-row').forEach(function (tr) {
                    const hay = (tr.getAttribute('data-search') || '');
                    tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
                });
            });
        }

        function htmlEscape(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
            });
        }

        function selectorValue(value) {
            const text = String(value == null ? '' : value);
            return window.CSS && CSS.escape ? CSS.escape(text) : text.replace(/"/g, '\\"');
        }

        function statusBadge(status) {
            if (status === 'approved' || status === 'active') {
                return '<span class="status status-active">' + (status === 'approved' ? 'Approved' : 'Active') + '</span>';
            }
            if (status === 'verified') {
                return '<span class="status status-active">Verified</span>';
            }
            if (status === 'rejected' || status === 'banned') {
                return '<span class="status status-report">' + (status === 'rejected' ? 'Rejected' : 'Banned') + '</span>';
            }
            return '<span class="status status-pending">Pending</span>';
        }

        function currentStatus(row, selector) {
            const el = row ? row.querySelector(selector) : null;
            return el ? el.textContent.trim().toLowerCase() : '';
        }

        function adjustAdminCount(name, delta) {
            if (!delta) return;
            document.querySelectorAll('[data-admin-count="' + name + '"], [data-admin-badge="' + name + '"]').forEach(function (el) {
                const current = parseInt(String(el.textContent || '0').replace(/,/g, ''), 10) || 0;
                const next = Math.max(0, current + delta);
                el.textContent = next.toLocaleString();
                if (el.hasAttribute('data-admin-badge')) {
                    el.style.display = next > 0 ? '' : 'none';
                }
            });
        }

        function showAdminToast(message, isError) {
            let toast = document.querySelector('.admin-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.className = 'admin-toast';
                document.body.appendChild(toast);
            }
            toast.textContent = message;
            toast.classList.toggle('error', !!isError);
            toast.classList.add('show');
            clearTimeout(showAdminToast.timer);
            showAdminToast.timer = setTimeout(function () {
                toast.classList.remove('show');
            }, 2600);
        }

        function prependAuditRow(data) {
            if (!data.auditAction) return;
            const tbody = document.getElementById('admin-audit-tbody');
            if (!tbody) return;
            const empty = tbody.querySelector('td[colspan="5"]');
            if (empty) empty.closest('tr').remove();

            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + htmlEscape(new Date().toLocaleString()) + '</td>' +
                '<td>' + htmlEscape(data.actorEmail || 'System') + '</td>' +
                '<td>' + htmlEscape(data.targetEmail || '') + '</td>' +
                '<td><span class="status status-pending">' + htmlEscape(data.auditAction) + '</span></td>' +
                '<td><div class="audit-detail">' + htmlEscape(data.auditDetails || data.note || '') + '</div></td>';
            tbody.prepend(tr);
        }

        function updateAdminUi(data, form) {
            if (!data || data.success === false) {
                showAdminToast((data && data.message) || 'Action failed.', true);
                return;
            }

            if (data.action === 'approveShop') {
                const row = document.querySelector('.admin-shop-row[data-shop-id="' + selectorValue(data.shopID) + '"]');
                if (row) {
                    const wasPending = currentStatus(row, '.admin-shop-status') === 'pending';
                    const status = row.querySelector('.admin-shop-status');
                    const actions = row.querySelector('.admin-shop-actions');
                    if (status) status.innerHTML = statusBadge('verified');
                    if (actions) {
                        actions.innerHTML =
                            '<form method="POST" style="display:inline;">' +
                            '<input type="hidden" name="action" value="rejectShop">' +
                            '<input type="hidden" name="shopID" value="' + htmlEscape(data.shopID) + '">' +
                            '<button class="action-btn" style="color:#ef4444;" title="Remove"><i class="bi bi-trash"></i></button>' +
                            '</form>';
                    }
                    if (wasPending) {
                        adjustAdminCount('unverified-shops', -1);
                        adjustAdminCount('reports', -1);
                    }
                }
                showAdminToast('Shop approved.');
            } else if (data.action === 'rejectShop') {
                const row = document.querySelector('.admin-shop-row[data-shop-id="' + selectorValue(data.shopID) + '"]');
                if (row) {
                    const wasPending = currentStatus(row, '.admin-shop-status') === 'pending';
                    row.remove();
                    if (wasPending) {
                        adjustAdminCount('unverified-shops', -1);
                        adjustAdminCount('reports', -1);
                    }
                }
                showAdminToast('Shop removed.');
            } else if (data.action === 'approveSeller' || data.action === 'rejectSeller') {
                const row = document.querySelector('.admin-seller-review-row[data-seller-id="' + selectorValue(data.userID) + '"]');
                if (row) {
                    const previous = currentStatus(row, '.admin-seller-review-status');
                    const status = row.querySelector('.admin-seller-review-status');
                    const note = row.querySelector('.admin-seller-review-note');
                    const noteInput = row.querySelector('.review-note');
                    if (status) status.innerHTML = statusBadge(data.status);
                    if (note) note.textContent = data.note || '';
                    if (noteInput) noteInput.value = '';
                    if (previous === 'pending') {
                        adjustAdminCount('pending-sellers', -1);
                        adjustAdminCount('reports', -1);
                    }
                    if (previous !== 'approved' && data.status === 'approved') {
                        adjustAdminCount('active-sellers', 1);
                    } else if (previous === 'approved' && data.status !== 'approved') {
                        adjustAdminCount('active-sellers', -1);
                    }
                }
                showAdminToast(data.status === 'approved' ? 'Seller approved.' : 'Seller rejected.');
            } else if (data.action === 'banUser' || data.action === 'unbanUser') {
                const row = document.querySelector('.admin-user-row[data-user-id="' + selectorValue(data.userID) + '"]');
                if (row) {
                    const previous = currentStatus(row, '.admin-user-status');
                    const status = row.querySelector('.admin-user-status');
                    const actions = row.querySelector('.admin-user-actions');
                    const isBanned = data.status === 'banned';
                    if (status) status.innerHTML = statusBadge(data.status);
                    if (actions) {
                        actions.innerHTML =
                            '<form method="POST" style="display:inline;">' +
                            '<input type="hidden" name="action" value="' + (isBanned ? 'unbanUser' : 'banUser') + '">' +
                            '<input type="hidden" name="userID" value="' + htmlEscape(data.userID) + '">' +
                            '<button class="action-btn" style="color:' + (isBanned ? '#22c55e' : '#ef4444') + ';" title="' + (isBanned ? 'Unban' : 'Ban') + '">' +
                            '<i class="bi ' + (isBanned ? 'bi-check-circle' : 'bi-slash-circle') + '"></i></button>' +
                            '</form>';
                    }
                    if (previous !== 'banned' && isBanned) {
                        adjustAdminCount('banned-users', 1);
                        adjustAdminCount('reports', 1);
                    } else if (previous === 'banned' && !isBanned) {
                        adjustAdminCount('banned-users', -1);
                        adjustAdminCount('reports', -1);
                    }
                }
                showAdminToast(data.status === 'banned' ? 'User banned.' : 'User unbanned.');
            }
            prependAuditRow(data);
        }

        document.querySelector('.main').addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || String(form.method).toLowerCase() !== 'post') return;
            event.preventDefault();

            const submitter = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
            const formData = submitter ? new FormData(form, submitter) : new FormData(form);
            const buttons = Array.from(form.querySelectorAll('button, input, select, textarea'));
            buttons.forEach(function (el) { el.disabled = true; });
            form.classList.add('is-saving');

            fetch('admin.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'fetch',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Server returned ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    updateAdminUi(data, form);
                })
                .catch(function (error) {
                    showAdminToast(error.message || 'Action failed.', true);
                })
                .finally(function () {
                    buttons.forEach(function (el) { el.disabled = false; });
                    form.classList.remove('is-saving');
                });
        });
    </script>
</body>
</html>
