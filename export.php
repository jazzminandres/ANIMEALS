<?php
// THIS FILE EXPORTS ADMIN REPORT DATA AS CSV OR PDF DOWNLOADS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'], $_SESSION['email'])) {
    header('Location: index.php');
    exit();
}

// USE BOTH DATABASES BECAUSE ADMIN/SELLER REPORTS COMBINE ORDERS, USERS, SHOPS, AND MENU DATA.
$conn = db_connect(DB_NAME_ANIMEALS);
$connSeller = db_connect(DB_NAME_SELLER_DATA);
if ($conn === false || $connSeller === false) {
    http_response_code(500);
    exit('Database connection failed.');
}

require_once __DIR__ . '/schema_bootstrap.php';
// MAKE SURE OPTIONAL REPORT COLUMNS EXIST BEFORE EXPORT QUERIES RUN.
animeals_ensure_extensions($conn);

$stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$_SESSION['email']]);
$user = $stmt ? db_fetch_assoc($stmt) : null;
if (!$user) {
    header('Location: index.php');
    exit();
}

$role = strtolower((string) ($user['userROLE'] ?? ''));
$format = strtolower((string) ($_GET['format'] ?? 'csv'));
$report = strtolower((string) ($_GET['report'] ?? ''));

if (!in_array($format, ['csv', 'pdf'], true)) {
    $format = 'csv';
}

$allowed = match (true) {
    // LIMIT EACH ROLE TO ONLY THE REPORT TYPES THEY ARE ALLOWED TO DOWNLOAD.
    $role === 'student' => $report === 'my_orders',
    $role === 'seller' => in_array($report, ['orders', 'transactions'], true),
    $role === 'admin' => in_array($report, ['all_orders', 'users', 'shops'], true),
    default => false,
};

if (!$allowed) {
    header('Location: ' . ($role === 'admin' ? 'admin.php' : ($role === 'seller' ? 'seller.php' : 'student.php')));
    exit();
}

function h($s)
{
    // ESCAPE REPORT VALUES BEFORE PRINTING THEM INTO HTML/PDF OUTPUT.
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function dt_str($v)
{
    // NORMALIZE DATE VALUES SO CSV AND PDF EXPORTS SHOW CONSISTENT TEXT.
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i:s');
    }
    return $v !== null ? (string) $v : '';
}

function send_csv(string $filename, array $headers, array $rows): void
{
    // STREAM A UTF-8 CSV FILE DIRECTLY TO THE BROWSER.
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\n"], '', $filename) . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

function send_pdf_table(string $title, array $headers, array $rows): void
{
    // BUILD A PRINT-FRIENDLY HTML TABLE THAT THE BROWSER CAN SAVE AS PDF.
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . h($title) . '</title>';
    echo '<style>
      body{font-family:system-ui,sans-serif;padding:24px;color:#1e293b;}
      h1{font-size:20px;margin-bottom:16px;}
      table{border-collapse:collapse;width:100%;font-size:12px;}
      th,td{border:1px solid #cbd5e1;padding:8px;text-align:left;}
      th{background:#f1f5f9;}
      @media print{body{padding:12px;} .noprint{display:none;}}
    </style></head><body>';
    echo '<p class="noprint" style="margin-bottom:16px;">Use your browser <strong>Print</strong> dialog and choose <strong>Save as PDF</strong>.</p>';
    echo '<h1>' . h($title) . '</h1><table><thead><tr>';
    foreach ($headers as $hcol) {
        echo '<th>' . h($hcol) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $cell) {
            echo '<td>' . h((string) $cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table><script>setTimeout(function(){window.print();},450);</script></body></html>';
    exit;
}

/**
 * SELLER FINANCIAL REPORT PDF (PRINT-TO-PDF): SUMMARY PLUS EVERY ORDER STATUS.
 */
/**
 * @RETURN ARRAY<int,string> ORDERID => SEMICOLON-SEPARATED "ITEM XN: NOTE" LINES.
 */
function animeals_item_notes_by_order_ids($conn, array $orderIds): array
{
    // LOAD ORDER ITEM NOTES IN CHUNKS SO LARGE REPORTS DO NOT CREATE A HUGE IN CLAUSE.
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if ($orderIds === []) {
        return [];
    }
    $out = [];
    $chunks = array_chunk($orderIds, 80);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT orderID, itemName, quantity, lineNote FROM order_items WHERE orderID IN ($placeholders)";
        $s = db_query($conn, $sql, $chunk);
        if (!$s) {
            continue;
        }
        $rows = db_fetch_all($s);
        foreach ($rows as $r) {
            $oid = (int) ($r['orderID'] ?? 0);
            $nm = trim((string) ($r['itemName'] ?? ''));
            $qty = (int) ($r['quantity'] ?? 0);
            $ln = trim((string) ($r['lineNote'] ?? ''));
            $part = $nm . ' x' . $qty . ($ln !== '' ? ': ' . $ln : '');
            $out[$oid][] = $part;
        }
    }
    foreach ($out as $k => $parts) {
        $out[$k] = implode('; ', $parts);
    }

    return $out;
}

function send_seller_financial_pdf(string $shopName, array $orderRows): void
{
    // CALCULATE SELLER FINANCIAL SUMMARY VALUES BEFORE PRINTING THE REPORT TABLE.
    $totalOrders = count($orderRows);
    $grossAll = 0.0;
    $recognized = 0.0;
    $pendingVal = 0.0;
    $byStatus = [];

    foreach ($orderRows as $o) {
        $amt = (float) ($o['totalAmount'] ?? 0);
        $grossAll += $amt;
        $st = strtolower((string) ($o['orderStatus'] ?? ''));
        $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        if (in_array($st, ['completed', 'ready'], true)) {
            $recognized += $amt;
        }
        if ($st === 'pending') {
            $pendingVal += $amt;
        }
    }

    $gen = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Financial Report</title>';
    echo '<style>
      body{font-family:system-ui,sans-serif;padding:24px;color:#0f172a;max-width:1100px;margin:0 auto;}
      h1{font-size:22px;margin:0 0 4px;}
      .sub{color:#64748b;font-size:14px;margin-bottom:20px;}
      .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px;}
      .card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;}
      .card b{display:block;font-size:11px;text-transform:uppercase;color:#64748b;letter-spacing:.04em;}
      .card span{font-size:20px;font-weight:800;color:#0f7a4a;}
      h2{font-size:15px;margin:24px 0 10px;}
      table{border-collapse:collapse;width:100%;font-size:11px;}
      th,td{border:1px solid #cbd5e1;padding:7px;text-align:left;}
      th{background:#ecfdf5;color:#14532d;}
      tr:nth-child(even){background:#f8fafc;}
      .ostatus{font-size:10px;font-weight:700;text-transform:capitalize;}
      @media print{body{padding:12px;} .noprint{display:none!important;} .card{break-inside:avoid;}}
    </style></head><body>';
    echo '<p class="noprint" style="margin-bottom:16px;">Use <strong>Print</strong> → <strong>Save as PDF</strong>.</p>';
    echo '<h1>Financial report</h1>';
    echo '<div class="sub">' . h($shopName) . ' · Generated ' . h($gen) . '</div>';

    echo '<div class="grid">';
    echo '<div class="card"><b>Total orders</b><span>' . (int) $totalOrders . '</span></div>';
    echo '<div class="card"><b>Gross (all statuses)</b><span>₱' . number_format($grossAll, 2) . '</span></div>';
    echo '<div class="card"><b>Recognized sales (completed / ready)</b><span>₱' . number_format($recognized, 2) . '</span></div>';
    echo '<div class="card"><b>Pending order value</b><span>₱' . number_format($pendingVal, 2) . '</span></div>';
    echo '</div>';

    if ($byStatus !== []) {
        echo '<h2>Orders by status</h2><table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
        ksort($byStatus);
        foreach ($byStatus as $st => $c) {
            echo '<tr><td>' . h($st !== '' ? $st : 'unknown') . '</td><td>' . (int) $c . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>All orders (every status)</h2><table><thead><tr>';
    foreach (['Order ID', 'Customer', 'Email', 'Status', 'Payment', 'Amount (PHP)', 'Ordered at', 'Note'] as $col) {
        echo '<th>' . h($col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($orderRows as $o) {
        $dt = dt_str($o['orderedAt'] ?? null);
        echo '<tr>';
        echo '<td>' . h((string) ($o['orderID'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($o['userNAME'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($o['userEMAIL'] ?? '')) . '</td>';
        echo '<td class="ostatus">' . h((string) ($o['orderStatus'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($o['paymentMethod'] ?? '')) . '</td>';
        echo '<td>₱' . h(number_format((float) ($o['totalAmount'] ?? 0), 2)) . '</td>';
        echo '<td>' . h($dt) . '</td>';
        echo '<td>' . h((string) ($o['orderNote'] ?? '')) . '</td>';
        echo '</tr>';
    }
    if ($totalOrders === 0) {
        echo '<tr><td colspan="8" style="text-align:center;color:#64748b;">No orders yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '<script>setTimeout(function(){window.print();},450);</script></body></html>';
    exit;
}

/* ---- Student: my orders ---- */
if ($role === 'student' && $report === 'my_orders') {
    $uid = (int) $user['userID'];
    $s = db_query($conn, "SELECT o.orderID, o.shopID, ss.shopName, o.orderStatus, o.paymentMethod, o.totalAmount, o.orderedAt, o.orderNote
         FROM orders o
         LEFT JOIN seller_data.seller_shops ss ON ss.shopID = o.shopID
         WHERE o.studentID = ?
         ORDER BY o.orderedAt DESC", [$uid]);
    $headers = ['Order ID', 'Shop', 'Status', 'Payment', 'Total (PHP)', 'Ordered At', 'Note'];
    $rows = [];
    $rows = $s ? db_fetch_all($s) : [];
    foreach ($rows as $row) {
        $rows[] = [
            $row['orderID'],
            $row['shopName'] ?? $row['shopID'],
            $row['orderStatus'],
            $row['paymentMethod'],
            number_format((float) $row['totalAmount'], 2, '.', ''),
            dt_str($row['orderedAt'] ?? null),
            $row['orderNote'] ?? '',
        ];
    }
    $title = 'My Orders — ANIMEALS';
    if ($format === 'csv') {
        send_csv('animeals_my_orders.csv', $headers, $rows);
    }
    send_pdf_table($title, $headers, $rows);
}

/* ---- Seller: all exports include every order status (shop by SELLER_DATA email; merge legacy DB) ---- */
if ($role === 'seller' && in_array($report, ['orders', 'transactions'], true)) {
    $sh = db_query($connSeller,
        "SELECT ss.shopID, ss.shopName
         FROM seller_shops ss
         INNER JOIN user_details su ON su.userID = ss.sellerID AND su.userEMAIL = ?
         LIMIT 1",
        [$_SESSION['email']]
    );
    $shopRow = $sh ? db_fetch_assoc($sh) : null;
    $shopID = (int) ($shopRow['shopID'] ?? 0);
    $shopName = (string) ($shopRow['shopName'] ?? 'My shop');

    $orderRowsAssoc = [];
    if ($shopID > 0) {
        $sqlA = "SELECT o.orderID, o.studentID, u.userNAME, u.userEMAIL, o.orderStatus, o.paymentMethod, o.totalAmount, o.orderedAt, o.orderNote
            FROM orders o
            LEFT JOIN user_details u ON u.userID = o.studentID
            WHERE o.shopID = ?";
        $sA = db_query($conn, $sqlA, [$shopID]);
        $rowsA = $sA ? db_fetch_all($sA) : [];

        $sqlL = "SELECT o.orderID, o.studentID, u.userNAME, u.userEMAIL, o.orderStatus, o.paymentMethod, o.totalAmount, o.orderedAt, o.orderNote
            FROM seller_data.orders o
            LEFT JOIN animeals.user_details u ON u.userID = o.studentID
            WHERE o.shopID = ?";
        $sL = db_query($conn, $sqlL, [$shopID]);
        $rowsL = $sL ? db_fetch_all($sL) : [];
        $seen = [];
        foreach ($rowsA as $row) {
            $seen[(int) ($row['orderID'] ?? 0)] = true;
            $orderRowsAssoc[] = $row;
        }
        foreach ($rowsL as $row) {
            $oid = (int) ($row['orderID'] ?? 0);
            if ($oid && empty($seen[$oid])) {
                $orderRowsAssoc[] = $row;
            }
        }
        usort($orderRowsAssoc, static function ($a, $b) {
            $ta = ($a['orderedAt'] ?? null) instanceof DateTimeInterface ? $a['orderedAt']->getTimestamp() : 0;
            $tb = ($b['orderedAt'] ?? null) instanceof DateTimeInterface ? $b['orderedAt']->getTimestamp() : 0;

            return $tb <=> $ta;
        });
    }

    $orderIdsForNotes = array_values(array_unique(array_map(static fn ($r) => (int) ($r['orderID'] ?? 0), $orderRowsAssoc)));
    $lineNotesMap = animeals_item_notes_by_order_ids($conn, $orderIdsForNotes);
    foreach ($orderRowsAssoc as &$row) {
        $oid = (int) ($row['orderID'] ?? 0);
        $base = trim((string) ($row['orderNote'] ?? ''));
        $extra = $lineNotesMap[$oid] ?? '';
        if ($extra !== '') {
            $row['orderNote'] = trim($base . ($base !== '' ? "\n" : '') . 'Item notes: ' . $extra);
        }
    }
    unset($row);

    $headers = ['Order ID', 'Student ID', 'Customer', 'Email', 'Status', 'Payment', 'Total (PHP)', 'Ordered At', 'Note'];
    $rows = [];
    foreach ($orderRowsAssoc as $row) {
        $rows[] = [
            $row['orderID'],
            $row['studentID'],
            $row['userNAME'] ?? '',
            $row['userEMAIL'] ?? '',
            $row['orderStatus'],
            $row['paymentMethod'],
            number_format((float) $row['totalAmount'], 2, '.', ''),
            dt_str($row['orderedAt'] ?? null),
            $row['orderNote'] ?? '',
        ];
    }

    $titleOrders = 'Shop orders (all statuses) — ' . $shopName;
    $fnOrders = 'animeals_shop_orders_all_statuses.csv';
    $fnFinancial = 'animeals_shop_financial_report_all_orders.csv';

    if ($format === 'csv') {
        $fn = $report === 'transactions' ? $fnFinancial : $fnOrders;
        send_csv($fn, $headers, $rows);
    }

    if ($report === 'transactions') {
        send_seller_financial_pdf($shopName, $orderRowsAssoc);
    }

    send_pdf_table($titleOrders, $headers, $rows);
}

/* ---- Admin ---- */
if ($role === 'admin' && $report === 'all_orders') {
    $s = db_query($conn, "SELECT o.orderID, o.shopID, ss.shopName, o.studentID, u.userNAME, o.orderStatus, o.paymentMethod, o.totalAmount, o.orderedAt
         FROM orders o
         LEFT JOIN animeals.user_details u ON u.userID = o.studentID
         LEFT JOIN seller_data.seller_shops ss ON ss.shopID = o.shopID
         ORDER BY o.orderedAt DESC");
    $headers = ['Order ID', 'Shop ID', 'Shop', 'Student ID', 'Customer', 'Status', 'Payment', 'Total (PHP)', 'Ordered At'];
    $rows = [];
    $rowsSource = $s ? db_fetch_all($s) : [];
    foreach ($rowsSource as $row) {
        $rows[] = [
            $row['orderID'],
            $row['shopID'],
            $row['shopName'] ?? '',
            $row['studentID'],
            $row['userNAME'] ?? '',
            $row['orderStatus'],
            $row['paymentMethod'],
            number_format((float) $row['totalAmount'], 2, '.', ''),
            dt_str($row['orderedAt'] ?? null),
        ];
    }
    if ($format === 'csv') {
        send_csv('animeals_all_orders.csv', $headers, $rows);
    }
    send_pdf_table('All platform orders', $headers, $rows);
}

if ($role === 'admin' && $report === 'users') {
    $s = db_query($conn, "SELECT userID, userNAME, userEMAIL, userROLE, userPHONE, COALESCE(isBanned, 0) AS isBanned FROM user_details ORDER BY userID DESC");
    $headers = ['User ID', 'Name', 'Email', 'Role', 'Phone', 'Banned'];
    $rows = [];
    $rowsSource = $s ? db_fetch_all($s) : [];
    foreach ($rowsSource as $row) {
        $rows[] = [
            $row['userID'],
            $row['userNAME'],
            $row['userEMAIL'],
            $row['userROLE'],
            $row['userPHONE'] ?? '',
            !empty($row['isBanned']) ? '1' : '0',
        ];
    }
    if ($format === 'csv') {
        send_csv('animeals_users.csv', $headers, $rows);
    }
    send_pdf_table('All users', $headers, $rows);
}

if ($role === 'admin' && $report === 'shops') {
        $s = db_query($connSeller, "SELECT ss.shopID, ss.shopName, ss.sellerID, COALESCE(ss.isApproved, 0) AS isApproved, ud.userNAME AS ownerName
            FROM seller_shops ss
            LEFT JOIN animeals.user_details ud ON ud.userID = ss.sellerID
            ORDER BY ss.shopID DESC");
    $headers = ['Shop ID', 'Shop Name', 'Seller ID', 'Owner', 'Approved'];
    $rows = [];
    $rowsSource = $s ? db_fetch_all($s) : [];
    foreach ($rowsSource as $row) {
        $rows[] = [
            $row['shopID'],
            $row['shopName'],
            $row['sellerID'],
            $row['ownerName'] ?? '',
            !empty($row['isApproved']) ? '1' : '0',
        ];
    }
    if ($format === 'csv') {
        send_csv('animeals_shops.csv', $headers, $rows);
    }
    send_pdf_table('All shops', $headers, $rows);
}

http_response_code(400);
exit('Invalid export request.');
