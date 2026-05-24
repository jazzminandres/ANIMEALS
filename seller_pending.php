<?php
// THIS FILE SHOWS SELLERS WHETHER THEIR ACCOUNT IS WAITING, APPROVED, OR REJECTED BY ADMIN.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';

if (!isset($_SESSION['user'], $_SESSION['email'])) {
    header('Location: index.php');
    exit();
}

$conn = db_connect(DB_NAME_ANIMEALS);
animeals_ensure_extensions($conn);

$stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ? LIMIT 1", [$_SESSION['email']]);
$user = $stmt ? db_fetch_assoc($stmt) : null;
if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

if (strtolower((string) ($user['userROLE'] ?? '')) !== 'seller') {
    header('Location: student.php');
    exit();
}

$status = strtolower(trim((string) ($user['sellerApprovalStatus'] ?? 'pending')));
if ($status === 'approved') {
    header('Location: seller.php');
    exit();
}

$isRejected = $status === 'rejected';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANIMEALS | Seller Verification</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:#f3f7f5; font-family:'Segoe UI',sans-serif; padding:20px; color:#25332d; }
.card { width:min(520px,100%); background:white; border-radius:28px; padding:34px; box-shadow:0 15px 40px rgba(0,0,0,.12); text-align:center; }
.icon { width:66px; height:66px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:white; background:<?= $isRejected ? '#ef4444' : '#1dbf73' ?>; font-size:34px; margin-bottom:18px; }
h1 { font-size:25px; margin-bottom:10px; }
p { color:#66746e; line-height:1.6; }
.note { margin-top:16px; background:#f1f4f3; border-radius:18px; padding:14px; color:#334155; text-align:left; }
.actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:22px; }
a { display:inline-block; border-radius:22px; padding:11px 18px; text-decoration:none; font-weight:700; }
.primary { background:#1dbf73; color:white; }
.ghost { background:#eef2f7; color:#334155; }
</style>
</head>
<body>
<div class="card">
    <div class="icon"><i class="bi <?= $isRejected ? 'bi-x-lg' : 'bi-hourglass-split' ?>"></i></div>
    <h1><?= $isRejected ? 'Seller verification needs attention' : 'Waiting for admin verification' ?></h1>
    <?php if ($isRejected): ?>
        <p>Your seller application was not approved yet. Please review the admin note below and contact ANIMEALS support or sign up again with corrected documents.</p>
        <?php if (!empty($user['sellerReviewNote'])): ?>
            <div class="note"><b>Admin note:</b><br><?= htmlspecialchars((string) $user['sellerReviewNote']) ?></div>
        <?php endif; ?>
    <?php else: ?>
        <p>Your email is verified and your seller documents were submitted. An admin must inspect your business permit and valid ID before your seller dashboard becomes available.</p>
    <?php endif; ?>
    <div class="actions">
        <a class="primary" href="seller_pending.php">Refresh status</a>
        <a class="ghost" href="logout.php">Log out</a>
    </div>
</div>
</body>
</html>
