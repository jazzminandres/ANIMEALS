<?php
// THIS FILE VALIDATES SIGNUP INPUTS SUCH AS EMAILS, PASSWORD RULES, AND DUPLICATE ACCOUNTS.
require_once __DIR__ . '/db.php';
/**
 * AJAX CHECKS FOR PROFILE SIGNUP: EMAIL, STUDENT NUMBER, AND SHOP NAME AVAILABILITY.
 * GET: ACTION=EMAIL|STUDENTNUM|SHOPNAME & VALUE=...
 */
header('Content-Type: application/json; charset=UTF-8');

$serverName = "SatanaelLG\MSSQLSERVER01";
$connectionOptions = ["Database" => "ANIMEALS", "Uid" => "", "PWD" => ""];
$conn = mysqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    // RETURN JSON EVEN WHEN THE DATABASE IS DOWN SO THE FRONT END CAN SHOW A CLEAN MESSAGE.
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}

$action = $_GET['action'] ?? '';
$value = trim((string) ($_GET['value'] ?? ''));

if ($action === 'email') {
    // VALIDATE EMAIL FORMAT FIRST, THEN CHECK IF AN ACCOUNT ALREADY USES IT.
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'available' => false, 'message' => 'Enter a valid email.']);
        exit;
    }
    $st = mysqlsrv_query($conn, "SELECT 1 AS x FROM USER_DETAILS WHERE userEMAIL = ?", [$value]);
    $taken = $st && mysqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'available' => !$taken,
        'message' => $taken
            ? 'This email is already registered. Try logging in instead.'
            : 'Email is available.',
    ]);
    exit;
}

if ($action === 'studentnum') {
    // STUDENT NUMBERS ARE EXPECTED TO BE EXACTLY NINE DIGITS.
    if ($value === '' || !preg_match('/^\d{9}$/', $value)) {
        echo json_encode(['ok' => false, 'available' => false, 'message' => 'Student number must be exactly 9 digits.']);
        exit;
    }
    $st = mysqlsrv_query(
        $conn,
        "SELECT 1 AS x FROM USER_DETAILS WHERE userSTUDENTNUM = ? AND LTRIM(RTRIM(ISNULL(userSTUDENTNUM,''))) <> ''",
        [$value]
    );
    $taken = $st && mysqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'available' => !$taken,
        'message' => $taken ? 'This student number is already registered.' : 'Student number is available.',
    ]);
    exit;
}

if ($action === 'shopname') {
    // SHOP NAMES MUST BE UNIQUE SO SELLERS DO NOT COLLIDE IN THE DASHBOARD.
    if ($value === '') {
        echo json_encode(['ok' => false, 'available' => false, 'message' => 'Enter a shop name.']);
        exit;
    }
    $st = mysqlsrv_query(
        $conn,
        "SELECT 1 AS x FROM SELLER_DATA.dbo.SELLER_SHOPS WHERE LOWER(LTRIM(RTRIM(shopName))) = LOWER(LTRIM(RTRIM(?)))",
        [$value]
    );
    $taken = $st && mysqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'available' => !$taken,
        'message' => $taken ? 'This shop name is already taken.' : 'Shop name is available.',
    ]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
