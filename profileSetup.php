<?php
// THIS FILE COLLECTS NEW USER PROFILE DETAILS AND REQUIRED SELLER DOCUMENT UPLOADS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!empty($_GET['reset_google'])) {
    // CLEAR GOOGLE PREFILL DATA WHEN THE USER WANTS TO START THE SIGNUP FORM OVER.
    unset(
        $_SESSION['google_name'],
        $_SESSION['google_email'],
        $_SESSION['google_picture'],
        $_SESSION['google_prefill_name'],
        $_SESSION['google_prefill_email'],
        $_SESSION['google_prefill_picture']
    );
}

$conn = db_connect();
require_once __DIR__ . '/schema_bootstrap.php';
// MAKE SURE SELLER APPROVAL AND AUDIT COLUMNS EXIST BEFORE PROFILE SETUP RUNS.
animeals_ensure_extensions($conn);
$BREVO_API_KEY = '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';
$brevoSendError = '';

function createPendingTable(mysqli $conn): void
{
    // KEEP EMAIL VERIFICATION SIGNUPS IN A TEMPORARY TABLE UNTIL THE CODE IS CONFIRMED.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS signup_pending (
            pendingEMAIL VARCHAR(255) NOT NULL,
            pendingNAME VARCHAR(255) DEFAULT NULL,
            pendingPASS VARCHAR(255) DEFAULT NULL,
            pendingCODE VARCHAR(10) DEFAULT NULL,
            expiresAt DATETIME DEFAULT NULL,
            createdAt DATETIME DEFAULT NULL,
            PRIMARY KEY (pendingEMAIL)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function sendBrevoVerificationEmail($to, $name, $code)
{
    // SEND THE ONE-TIME SIGNUP CODE THROUGH BREVO AND SAVE ANY ERROR FOR THE FORM MESSAGE.
    global $BREVO_API_KEY, $brevoSendError;

    // BUILD BOTH HTML AND TEXT EMAIL CONTENT SO THE CODE IS READABLE IN ANY EMAIL CLIENT.
    $payload = [
        'sender' => ['name' => 'ANIMEALS', 'email' => 'linianlunar@gmail.com'],
        'to' => [['email' => $to, 'name' => $name]],
        'subject' => 'Verify your ANIMEALS account',
        'htmlContent' => "<html><body><h2>Verify your email</h2><p>Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",</p><p>Your verification code is <strong>{$code}</strong>.</p><p>Enter it on the verification page to complete your sign up.</p><p>If you did not request this, please ignore this email.</p></body></html>",
        'textContent' => "Hello {$name},\n\nYour ANIMEALS verification code is {$code}.\n\nEnter it on the verification page to complete your sign up.",
    ];

    if (!function_exists('curl_version')) {
        $brevoSendError = 'cURL is not available on the server.';
        return false;
    }

    // CALL BREVO DIRECTLY FROM PHP BECAUSE THIS PROJECT DOES NOT USE A MAILER PACKAGE.
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $BREVO_API_KEY,
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $brevoSendError = curl_error($ch);
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($response, true);
        $brevoSendError = $decoded['message'] ?? substr($response, 0, 200);
        return false;
    }

    return true;
}

createPendingTable($conn);

$googleName    = $_SESSION['google_name']    ?? $_SESSION['google_prefill_name']    ?? '';
$googleEmail   = $_SESSION['google_email']   ?? $_SESSION['google_prefill_email']   ?? '';
$googlePicture = $_SESSION['google_picture'] ?? $_SESSION['google_prefill_picture'] ?? '';

$message = '';

if ($googleEmail) {
    // IF GOOGLE ALREADY MATCHES AN EXISTING ACCOUNT, LOG THEM IN INSTEAD OF DUPLICATING IT.
    $existingStmt = db_query($conn, 'SELECT * FROM user_details WHERE userEMAIL = ?', [$googleEmail]);
    $existing = $existingStmt ? db_fetch_assoc($existingStmt) : null;
    if ($existing) {
        $_SESSION['user'] = $existing['userNAME'];
        $_SESSION['email'] = $existing['userEMAIL'];
        $_SESSION['role'] = $existing['userROLE'] ?? 'student';
        unset($_SESSION['google_name'], $_SESSION['google_email'], $_SESSION['google_picture']);

        if ($_SESSION['role'] === 'admin') {
            header('Location: admin.php');
            exit;
        }
        if ($_SESSION['role'] === 'seller') {
            header('Location: seller.php');
            exit;
        }
        header('Location: student.php');
        exit;
    }
}
$messageType = 'danger';

/** PRESET PASSWORD FOR GOOGLE OAUTH SIGNUP THAT MEETS POLICY; THE USER DOES NOT TYPE IT. */
$googlePwdPreset = 'Aa1!' . bin2hex(random_bytes(14));

function profilesetup_password_valid(string $pwd, bool $isGoogleSignup): bool
{
    // GOOGLE SIGNUPS USE THE GENERATED PASSWORD, WHILE EMAIL SIGNUPS MUST FOLLOW THE VISIBLE RULES.
    if ($isGoogleSignup) {
        return true;
    }
    $len = strlen($pwd);
    if ($len < 16 || $len > 32) {
        return false;
    }
    if (!preg_match('/[a-z]/', $pwd) || !preg_match('/[A-Z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
        return false;
    }
    if (!preg_match('/[^A-Za-z0-9]/', $pwd)) {
        return false;
    }

    return true;
}

/* ---------- SIGNUP + PROFILE SETUP SUBMIT ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setupBtn'])) {

    // COLLECT THE COMMON PROFILE FIELDS FIRST, THEN VALIDATE ROLE-SPECIFIC REQUIREMENTS.
    $fullname   = trim($_POST['signupName']   ?? '');
    $emailuser  = trim($_POST['signupEmail']  ?? '');
    $password   = (string) ($_POST['signupPass'] ?? '');
    $role       = trim($_POST['role']         ?? '');
    $phone      = trim($_POST['phone']        ?? '');
    $gender     = trim($_POST['gender']       ?? '');

    // Role-specific
    $college    = trim($_POST['college']      ?? '');
    $studentNum = preg_replace('/\D/', '', (string) ($_POST['studentNum'] ?? ''));
    $shopName   = trim($_POST['shopName']     ?? '');
    $adminDoc   = '';

    $isGoogle = $googleEmail !== '';
    $hasPassword = $password !== '';

    if (!$fullname || !$emailuser || !$role || !$hasPassword) {
        $message = 'Please fill in all required fields (Name, Email, Password, and Role).';
        $messageType = 'warning';
    } elseif (!$isGoogle && !profilesetup_password_valid($password, false)) {
        $message = 'Password must be 16–32 characters and include uppercase, lowercase, a number, and a symbol.';
        $messageType = 'danger';
    } elseif ($role === 'student' && !preg_match('/^\d{9}$/', $studentNum)) {
        $message = 'Student number is required: exactly 9 digits (numbers only).';
        $messageType = 'danger';
    } elseif ($role === 'seller' && ($shopName === '' || ($_POST['shopNameVerified'] ?? '') !== '1')) {
        $message = 'Please enter a shop name, tap Check availability, and confirm it is available before creating your account.';
        $messageType = 'danger';
    } elseif ($role === 'seller' && (empty($_FILES['businessPermit']['name']) || empty($_FILES['validId']['name']))) {
        $message = 'Seller signup requires both a business permit and a valid ID upload.';
        $messageType = 'danger';
    } else {
        $validationError = '';

        $dupEstmt = db_query($conn, 'SELECT 1 AS x FROM user_details WHERE userEMAIL = ?', [$emailuser]);
        $dupE = $dupEstmt ? db_fetch_assoc($dupEstmt) : null;
        if ($dupE) {
            $validationError = 'This email is already registered. Please go back and log in with that email instead of signing up again.';
        }

        if ($validationError === '' && $role === 'student' && $studentNum !== '') {
            $dupSstmt = db_query($conn, "SELECT 1 AS x FROM user_details WHERE userSTUDENTNUM = ? AND TRIM(COALESCE(userSTUDENTNUM, '')) <> ''", [$studentNum]);
            $dupS = $dupSstmt ? db_fetch_assoc($dupSstmt) : null;
            if ($dupS) {
                $validationError = 'This student number is already registered to another account.';
            }
        }

        if ($validationError === '' && $role === 'seller' && $shopName !== '') {
            $dupShopStmt = db_query(
                $conn,
                'SELECT 1 AS x FROM seller_data.seller_shops WHERE LOWER(TRIM(shopName)) = LOWER(TRIM(?))',
                [$shopName]
            );
            $dupShop = $dupShopStmt ? db_fetch_assoc($dupShopStmt) : null;
            if ($dupShop) {
                $validationError = 'This shop name is already taken. Choose another name and check availability again.';
            }
        }

        if ($validationError !== '') {
            $message = $validationError;
            $messageType = 'danger';
        } else {

            /* -- Profile picture upload -- */
            $profilePicPath = '';
            if (!empty($_FILES['profilePic']['name'])) {
                $uploadDir = 'uploads/profiles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['profilePic']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('prof_') . '.' . $ext;
                if (move_uploaded_file($_FILES['profilePic']['tmp_name'], $uploadDir . $filename)) {
                    $profilePicPath = $uploadDir . $filename;
                }
            }

            /* -- Seller: Business Permit upload -- */
            $businessPermitPath = '';
            if (!empty($_FILES['businessPermit']['name'])) {
                $uploadDir = 'uploads/documents/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['businessPermit']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('permit_') . '.' . $ext;
                if (move_uploaded_file($_FILES['businessPermit']['tmp_name'], $uploadDir . $filename)) {
                    $businessPermitPath = $uploadDir . $filename;
                }
            }

            /* -- Seller: Valid ID upload -- */
            $validIdPath = '';
            if (!empty($_FILES['validId']['name'])) {
                $uploadDir = 'uploads/documents/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['validId']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('validid_') . '.' . $ext;
                if (move_uploaded_file($_FILES['validId']['tmp_name'], $uploadDir . $filename)) {
                    $validIdPath = $uploadDir . $filename;
                }
            }

            /* -- Admin: Admin ID Document upload -- */
            $adminDocPath = '';
            if (!empty($_FILES['adminDoc']['name'])) {
                $uploadDir = 'uploads/documents/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['adminDoc']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('admindoc_') . '.' . $ext;
                if (move_uploaded_file($_FILES['adminDoc']['tmp_name'], $uploadDir . $filename)) {
                    $adminDocPath = $uploadDir . $filename;
                }
            }

            if ($role === 'seller' && ($businessPermitPath === '' || $validIdPath === '')) {
                $message = 'We could not save both seller documents. Please upload the business permit and valid ID again.';
                $messageType = 'danger';
            } else {
            $code = random_int(100000, 999999);
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $expiry = clone $now;
            $expiry->modify('+15 minutes');

            db_query($conn, "DELETE FROM signup_pending WHERE pendingEMAIL = ?", [$emailuser]);

            $pendingSql = "INSERT INTO signup_pending (pendingEMAIL, pendingNAME, pendingPASS, pendingCODE, expiresAt, createdAt) VALUES (?, ?, ?, ?, ?, ?)";
            $pendingParams = [
                $emailuser,
                $fullname,
                $password,
                (string) $code,
                $expiry->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s')
            ];
            $pendingResult = db_query($conn, $pendingSql, $pendingParams);

            if ($pendingResult) {
                $_SESSION['pending_signup'] = [
                    'userNAME' => $fullname,
                    'userPASSWORD' => $password,
                    'userEMAIL' => $emailuser,
                    'userROLE' => $role,
                    'userPHONE' => $phone,
                    'userGENDER' => $gender,
                    'userCOLLEGE' => $college,
                    'userSTUDENTNUM' => $studentNum,
                    'userSHOPNAME' => $shopName,
                    'userPROFILEPIC' => $profilePicPath,
                    'userBUSINESSPERMIT' => $businessPermitPath,
                    'userVALIDID' => $validIdPath,
                    'userADMINDOC' => $adminDocPath,
                    'sellerApprovalStatus' => $role === 'seller' ? 'pending' : 'approved'
                ];

                if (sendBrevoVerificationEmail($emailuser, $fullname, (string) $code)) {
                    unset($_SESSION['google_name'], $_SESSION['google_email'], $_SESSION['google_picture']);
                    header('Location: verify.php?email=' . urlencode($emailuser));
                    exit();
                }

                db_query($conn, "DELETE FROM signup_pending WHERE pendingEMAIL = ?", [$emailuser]);
                unset($_SESSION['pending_signup']);
                $message = 'Unable to send verification email. ' . ($brevoSendError ? htmlspecialchars($brevoSendError, ENT_QUOTES, 'UTF-8') : 'Please try again later.');
                $messageType = 'danger';
            } else {
                $message = 'Could not save signup verification data. Please try again.';
                $messageType = 'danger';
            }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Profile Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; font-family:'Outfit',sans-serif; }

        :root {
            --green: #10b981;
            --dark-green: #0f7a4a;
            --light-bg: #fdfaf7;
            --card-bg: #fff;
            --border: #eee;
            --muted: #999;
            --text: #2d2d2d;
        }

        body {
            background: var(--light-bg);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 90px 20px 40px;
            color: var(--text);
        }

        /* ── CARD ── */
        .setup-box {
            background: var(--card-bg);
            padding: 35px;
            border-radius: 35px;
            box-shadow: 0 15px 35px rgba(0,0,0,.07);
            width: 100%;
            max-width: 500px;
            position: relative;
            margin-top: 60px;
        }

        .upload-success {
    font-size: 11px;
    color: var(--green);
    font-weight: 700;
    margin-top: 6px;
    display: none;
}
.upload-success.show { display: block; }
.upload-btn.uploaded {
    border-color: var(--green);
    background: #d1fae5;
    color: var(--dark-green);
}

        /* ── AVATAR ── */
        .profile-header {
            position: absolute;
            top: -60px;
            left: 50%;
            transform: translateX(-50%);
            width: 120px;
            height: 120px;
        }

        .profile-img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 6px solid #fff;
            background: #eee;
            box-shadow: 0 5px 15px rgba(0,0,0,.12);
        }

        .edit-icon {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: var(--green);
            color: white;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid #fff;
            font-size: 14px;
        }

        /* ── TITLE ── */
        .title-area { text-align: center; margin-top: 50px; margin-bottom: 22px; }
        .title-area h2 { color: var(--dark-green); font-weight: 800; }
        .title-area p  { font-size: 13px; color: var(--muted); }

        /* ── ALERTS ── */
        .alert {
            padding: 10px 15px;
            border-radius: 14px;
            font-size: 13px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .alert-danger  { background: #ffeaea; color: #c0392b; border: 1.5px solid #f5b7b1; }
        .alert-warning { background: #fff8e1; color: #b7950b; border: 1.5px solid #f9e79f; }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1.5px solid #a9dfbf; }

        /* ── ROLE CARDS ── */
        .role-row {
            display: grid;
            grid-template-columns: repeat(3,1fr);
            gap: 10px;
            margin-bottom: 22px;
        }

        .role-card {
            background: #f9f9f9;
            border: 2px solid var(--border);
            padding: 12px;
            border-radius: 18px;
            text-align: center;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            transition: .25s;
            user-select: none;
        }

        .role-card.active { background: var(--green); color: #fff; border-color: var(--green); }

        /* ── FORM ELEMENTS ── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        .form-full  { width: 100%; margin-bottom: 14px; }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 800;
            font-size: 11px;
            color: #b0b0b0;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        input[type=text],
        input[type=email],
        input[type=tel],
        input[type=password],
        select {
            width: 100%;
            padding: 12px;
            background: #fcfcfc;
            border: 1.5px solid var(--border);
            border-radius: 15px;
            outline: none;
            font-size: 14px;
            transition: border-color .2s;
        }

        input:focus, select:focus { border-color: var(--green); }

        /* password wrapper */
        .pass-wrapper { position: relative; }
        .eye-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            font-size: 17px;
        }

        /* ── GENDER ── */
        .gender-row { display: flex; gap: 10px; }
        .gender-item {
            flex: 1;
            border: 1.5px solid var(--border);
            border-radius: 15px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            background: #fdfdfd;
            transition: .2s;
        }
        .gender-item svg   { width: 24px; height: 24px; fill: #ccc; margin-bottom: 4px; }
        .gender-item span  { font-size: 10px; font-weight: 800; color: #ccc; }
        .gender-item.active { border-color: var(--green); background: #f0faf7; }
        .gender-item.active svg  { fill: var(--green); }
        .gender-item.active span { color: var(--green); }

        /* ── FILE UPLOAD ── */
        .upload-wrapper { position: relative; overflow: hidden; }
        .upload-btn {
            border: 1.5px dashed var(--green);
            color: var(--green);
            background: #f0faf7;
            padding: 10px;
            border-radius: 15px;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            display: block;
            cursor: pointer;
        }
        .upload-wrapper input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

        /* ── SUBMIT ── */
        .submit-btn {
            width: 100%;
            background: var(--green);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 18px;
            font-weight: 800;
            font-size: 15px;
            cursor: pointer;
            margin-top: 10px;
            transition: .3s;
        }
        .submit-btn:hover { background: var(--dark-green); transform: translateY(-2px); }
        .submit-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        /* ── HIDDEN ROLE INPUT ── */
        #roleInput { display: none; }

        /* ── GENDER HIDDEN INPUT ── */
        #genderInput { display: none; }

        /* ── MODAL ── */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            display: none;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            z-index: 100;
        }
        .popup-card {
            background: #fff;
            padding: 35px;
            border-radius: 30px;
            text-align: center;
            width: 300px;
            animation: popIn .4s cubic-bezier(.175,.885,.32,1.275);
        }
        @keyframes popIn {
            from { opacity:0; transform:scale(.8); }
            to   { opacity:1; transform:scale(1);  }
        }

        /* ── DYNAMIC FIELDS ANIMATION ── */
        #dynamicFields { transition: opacity .3s; }
        #dynamicFields.loading { opacity: 0; }

        .pwd-req-pop {
            display: none;
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #1f2937;
            color: #f9fafb;
            font-size: 12px;
            line-height: 1.45;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        .pwd-req-pop.open { display: block; }
        .pwd-req-pop .req-line { padding: 4px 0; display: flex; align-items: center; gap: 8px; }
        .pwd-req-pop .req-line::before {
            content: '';
            width: 8px; height: 8px; border-radius: 50%;
            background: #ef4444;
            flex-shrink: 0;
        }
        .pwd-req-pop .req-line.ok::before { background: #22c55e; }
        .pwd-req-pop .req-line.ok { color: #bbf7d0; }
        .pwd-req-pop .req-line.bad { color: #fecaca; }
        .pwd-strength-wrap { margin-top: 8px; }
        .pwd-strength-label { font-size: 11px; color: #9ca3af; margin-bottom: 4px; }
        .pwd-strength-track { height: 6px; border-radius: 6px; background: #374151; overflow: hidden; }
        .pwd-strength-fill { height: 100%; width: 0%; border-radius: 6px; transition: width .2s, background .2s; background: #ef4444; }
        .pwd-strength-fill.s1 { width: 20%; background: #f97316; }
        .pwd-strength-fill.s2 { width: 40%; background: #eab308; }
        .pwd-strength-fill.s3 { width: 60%; background: #84cc16; }
        .pwd-strength-fill.s4 { width: 80%; background: #22c55e; }
        .pwd-strength-fill.s5 { width: 100%; background: #16a34a; }
        .shop-check-msg { font-size: 12px; font-weight: 700; margin-top: 6px; min-height: 18px; }
        .shop-check-msg.ok { color: var(--dark-green); }
        .shop-check-msg.bad { color: #c0392b; }
    </style>
</head>
<body>

<div class="setup-box">

    <!-- Avatar -->
    <div class="profile-header">
        <img src="<?= $googlePicture ?: 'https://cdn-icons-png.flaticon.com/512/149/149071.png' ?>"
             id="avatarPreview" class="profile-img" alt="Profile">
        <?php if (!$googleEmail): ?>
            <label class="edit-icon" title="Change photo">
                <i class="bi bi-pencil-fill"></i>
                <input type="file" name="profilePicPreview" hidden accept="image/*" onchange="previewAvatar(event)">
            </label>
        <?php endif; ?>
    </div>

    <div class="title-area">
        <h2>Profile Setup</h2>
        <p>Create your account to continue</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Role Selector -->
    <div class="role-row">
        <div class="role-card" data-role="student" onclick="setRole('student', this)">Student</div>
        <div class="role-card" data-role="seller" onclick="setRole('seller',  this)">Seller</div>
        <div class="role-card" data-role="admin" onclick="setRole('admin',   this)">Admin</div>
    </div>

    <!-- Main Form -->
    <form method="POST" enctype="multipart/form-data" id="setupForm">

        <!-- Hidden: real profile pic file input -->
        <input type="file" name="profilePic" id="profilePicReal" accept="image/*" style="display:none">

        <!-- Hidden role & gender values -->
        <input type="hidden" name="role"   id="roleInput">
        <input type="hidden" name="gender" id="genderInput">

        <!-- Signup fields (always present) -->
        <!-- NEW -->
<div class="form-grid">
    <div>
        <label>Full Name</label>
        <input type="text" name="signupName" placeholder="Your Name" required
               value="<?= htmlspecialchars($googleName) ?>"
               <?= $googleEmail ? 'readonly style="background:#f0faf7;color:#888;"' : '' ?>>
    </div>
    <div>
        <label>Email</label>
        <input type="email" name="signupEmail" placeholder="email@sample.com" required
               value="<?= htmlspecialchars($googleEmail) ?>"
               <?= $googleEmail ? 'readonly style="background:#f0faf7;color:#888;"' : '' ?>>
    </div>
</div>

        <!-- NEW -->
<div class="form-full" id="passwordField" <?= $googleEmail ? 'style="display:none"' : '' ?>>
    <label>Password (16–32 characters)</label>
    <div class="pass-wrapper">
        <input type="password" name="signupPass" id="signupPass" autocomplete="new-password"
               placeholder="16–32 characters, mixed case, number, symbol"
               minlength="16" maxlength="32"
               <?= $googleEmail ? '' : 'required' ?>
               value="<?= $googleEmail ? htmlspecialchars($googlePwdPreset) : '' ?>"
               onfocus="setupPwdPop(true)" onblur="setupPwdPopDelayed()" oninput="setupPwdMeter(this.value)">
        <i class="bi bi-eye-slash eye-icon" onclick="togglePass('signupPass', this)"></i>
    </div>
    <div id="pwdReqPop" class="pwd-req-pop" aria-live="polite">
        <div class="req-line bad" id="req-len">16–32 characters long</div>
        <div class="req-line bad" id="req-lower">At least one lowercase letter</div>
        <div class="req-line bad" id="req-upper">At least one uppercase letter</div>
        <div class="req-line bad" id="req-num">At least one number</div>
        <div class="req-line bad" id="req-sym">At least one symbol (e.g. ! @ # $ %)</div>
        <div class="pwd-strength-wrap">
            <div class="pwd-strength-label">Password strength</div>
            <div class="pwd-strength-track"><div id="pwdStrengthFill" class="pwd-strength-fill"></div></div>
        </div>
    </div>
</div>

        <div class="form-full">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="09xxxxxxxxx" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')">


        </div>

        <!-- Gender picker -->
        <div class="form-full">
            <label>Gender</label>
            <div class="gender-row">
                <div class="gender-item" onclick="pickGender('Male', this)">
                    <svg viewBox="0 0 24 24"><path d="M12,2C10.34,2 9,3.34 9,5C9,6.66 10.34,8 12,8C13.66,8 15,6.66 15,5C15,3.34 13.66,2 12,2M10.5,22H13.5V15H14.5L14.75,10.23C14.89,7.66 13.11,5.5 10.5,5.5C7.89,5.5 6.11,7.66 6.25,10.23L6.5,15H7.5V22H10.5Z"/></svg>
                    <span>MALE</span>
                </div>
                <div class="gender-item" onclick="pickGender('Female', this)">
                    <svg viewBox="0 0 24 24"><path d="M12,2C10.34,2 9,3.34 9,5C9,6.66 10.34,8 12,8C13.66,8 15,6.66 15,5C15,3.34 13.66,2 12,2M18,10.5C18,8.57 16.43,7 14.5,7H9.5C7.57,7 6,8.57 6,10.5L7,16H9V22H15V16H17L18,10.5Z"/></svg>
                    <span>FEMALE</span>
                </div>
            </div>
        </div>

        <!-- Dynamic role-specific fields -->
        <div id="dynamicFields"></div>

        <button type="submit" name="setupBtn" class="submit-btn" id="doneBtn" style="display:none" disabled>
            CREATE ACCOUNT
        </button>
    </form>
</div>

<!-- Loading / Success Modal -->
<div class="overlay" id="modalOverlay">
    <div class="popup-card" id="popupInner">
        <div class="bi bi-hourglass-split" style="color:var(--green);font-size:40px;margin-bottom:10px;"></div>
        <h3>Creating account…</h3>
        <p style="color:#999;font-size:13px;">Please wait.</p>
    </div>
</div>

<script>
    function markUploaded(input, btnId, statusId) {
    const btn    = document.getElementById(btnId);
    const status = document.getElementById(statusId);
    if (input.files && input.files[0]) {
        const name = input.files[0].name;
        btn.classList.add('uploaded');
        btn.innerHTML = '<i class="bi bi-check-lg"></i> ' + name;
        status.querySelector('span').textContent = name + ' uploaded successfully';
        status.classList.add('show');
    }
}
    /* ── Avatar preview ── */
    function previewAvatar(e) {
        const file = e.target.files[0];
        if (!file) return;
        document.getElementById('avatarPreview').src = URL.createObjectURL(file);
        // Mirror to the real file input so PHP receives it
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('profilePicReal').files = dt.files;
    }

    /* ── Password toggle ── */
    function togglePass(id, icon) {
        const inp = document.getElementById(id);
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.classList.replace('bi-eye-slash','bi-eye');
        } else {
            inp.type = 'password';
            icon.classList.replace('bi-eye','bi-eye-slash');
        }
    }

    /* ── Gender picker ── */
    function pickGender(value, el) {
        document.querySelectorAll('.gender-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('genderInput').value = value;
    }

    /* ── Role picker + dynamic fields ── */
    function setRole(role, btn) {
        document.querySelectorAll('.role-card').forEach(r => r.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('roleInput').value = role;
        const doneBtn = document.getElementById('doneBtn');
        doneBtn.style.display = 'block';
        if (role === 'seller') {
            doneBtn.disabled = true;
            doneBtn.style.opacity = '0.55';
        } else {
            updateDoneButtonState(role);
        }

        let extra = '';
        if (role === 'student') {
            extra = `
                <div class="form-grid">
                    <div>
                        <label>College</label>
                        <select name="college">
                            <option value="">Select…</option>
                            <option>CEAT</option>
                            <option>CBAA</option>
                            <option>CTHM</option>
                            <option>CSCS</option>
                        </select>
                    </div>
                    <div>
                        <label>Student No. (9 digits)</label>
                        <input type="text" name="studentNum" id="studentNumInput" placeholder="123456789" inputmode="numeric" pattern="[0-9]*" maxlength="9" required
                               oninput="this.value=this.value.replace(/\\D/g,'').slice(0,9)">
                    </div>
                </div>`;
        } else if (role === 'seller') {
    extra = `
        <div class="form-full">
            <label>Shop Name</label>
            <div style="display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;">
                <input type="text" name="shopName" id="shopNameInput" placeholder="Your shop name" style="flex:1;min-width:160px;"
                       oninput="document.getElementById('shopNameVerified').value='0';document.getElementById('shopNameMsg').textContent='';document.getElementById('shopNameMsg').className='shop-check-msg';updateDoneButtonState('seller');">
                <button type="button" id="shopCheckBtn" class="upload-btn" style="white-space:nowrap;padding-left:16px;padding-right:16px;" onclick="checkShopNameAvail()">Check availability</button>
            </div>
            <input type="hidden" name="shopNameVerified" id="shopNameVerified" value="0">
            <div id="shopNameMsg" class="shop-check-msg"></div>
        </div>
        <div class="form-grid">
            <div>
                <label>Business Permit</label>
                <div class="upload-wrapper">
                    <span class="upload-btn" id="permitBtn"><i class="bi bi-upload"></i> Upload</span>
                    <input type="file" name="businessPermit" accept=".pdf,image/*" required onchange="markUploaded(this, 'permitBtn', 'permitStatus');updateDoneButtonState('seller');">
                </div>
                <div class="upload-success" id="permitStatus"><i class="bi bi-check-circle-fill"></i> <span></span></div>
            </div>
            <div>
                <label>Valid ID</label>
                <div class="upload-wrapper">
                    <span class="upload-btn" id="validIdBtn"><i class="bi bi-upload"></i> Upload</span>
                    <input type="file" name="validId" accept=".pdf,image/*" required onchange="markUploaded(this, 'validIdBtn', 'validIdStatus');updateDoneButtonState('seller');">
                </div>
                <div class="upload-success" id="validIdStatus"><i class="bi bi-check-circle-fill"></i> <span></span></div>
            </div>
        </div>`;
        } else if (role === 'admin') {
    extra = `
        <div class="form-full">
            <label>Admin ID Document</label>
            <div class="upload-wrapper">
                <span class="upload-btn" id="adminDocBtn"><i class="bi bi-upload"></i> Choose File</span>
                <input type="file" name="adminDoc" accept=".pdf,image/*" onchange="markUploaded(this, 'adminDocBtn', 'adminDocStatus')">
            </div>
            <div class="upload-success" id="adminDocStatus"><i class="bi bi-check-circle-fill"></i> <span></span></div>
        </div>`;
        }

        const df = document.getElementById('dynamicFields');
        df.classList.add('loading');
        setTimeout(() => {
            df.innerHTML = extra;
            df.classList.remove('loading');
            updateDoneButtonState(role);
        }, 150);
    }

    let pwdPopTimer = null;
    function setupPwdPop(show) {
        const pop = document.getElementById('pwdReqPop');
        if (!pop) return;
        if (pwdPopTimer) { clearTimeout(pwdPopTimer); pwdPopTimer = null; }
        pop.classList.toggle('open', !!show);
    }
    function setupPwdPopDelayed() {
        pwdPopTimer = setTimeout(() => setupPwdPop(false), 200);
    }
    function setupPwdMeter(pw) {
        const lines = [
            { id: 'req-len', ok: pw.length >= 16 && pw.length <= 32 },
            { id: 'req-lower', ok: /[a-z]/.test(pw) },
            { id: 'req-upper', ok: /[A-Z]/.test(pw) },
            { id: 'req-num', ok: /[0-9]/.test(pw) },
            { id: 'req-sym', ok: /[^A-Za-z0-9]/.test(pw) }
        ];
        let score = 0;
        lines.forEach(function (L) {
            const el = document.getElementById(L.id);
            if (!el) return;
            el.classList.toggle('ok', L.ok);
            el.classList.toggle('bad', !L.ok);
            if (L.ok) score++;
        });
        const fill = document.getElementById('pwdStrengthFill');
        if (fill) {
            fill.className = 'pwd-strength-fill s' + Math.min(5, Math.max(0, score));
        }
        updateDoneButtonState(document.getElementById('roleInput').value);
    }

    async function checkShopNameAvail() {
        const inp = document.getElementById('shopNameInput');
        const msg = document.getElementById('shopNameMsg');
        const hid = document.getElementById('shopNameVerified');
        const name = (inp && inp.value || '').trim();
        if (!name) {
            if (msg) { msg.textContent = 'Enter a shop name first.'; msg.className = 'shop-check-msg bad'; }
            if (hid) hid.value = '0';
            updateDoneButtonState('seller');
            return;
        }
        if (msg) msg.textContent = 'Checking…';
        try {
            const r = await fetch('signup_checks.php?action=shopname&value=' + encodeURIComponent(name));
            const j = await r.json();
            if (j.available) {
                if (msg) { msg.textContent = j.message || 'Available.'; msg.className = 'shop-check-msg ok'; }
                if (hid) hid.value = '1';
            } else {
                if (msg) { msg.textContent = j.message || 'Taken.'; msg.className = 'shop-check-msg bad'; }
                if (hid) hid.value = '0';
            }
        } catch (e) {
            if (msg) { msg.textContent = 'Could not verify. Try again.'; msg.className = 'shop-check-msg bad'; }
            if (hid) hid.value = '0';
        }
        updateDoneButtonState('seller');
    }

    function updateDoneButtonState(role) {
        const btn = document.getElementById('doneBtn');
        if (!btn || btn.style.display === 'none') return;
        const isGoogle = <?= $googleEmail ? 'true' : 'false' ?>;
        const pwd = (document.getElementById('signupPass') || {}).value || '';
        let ok = true;
        if (!isGoogle) {
            const goodLen = pwd.length >= 16 && pwd.length <= 32;
            const goodMix = /[a-z]/.test(pwd) && /[A-Z]/.test(pwd) && /[0-9]/.test(pwd) && /[^A-Za-z0-9]/.test(pwd);
            ok = goodLen && goodMix;
        }
        if (role === 'seller') {
            ok = ok && (document.getElementById('shopNameVerified') || {}).value === '1';
            const permit = document.querySelector('input[name="businessPermit"]');
            const validId = document.querySelector('input[name="validId"]');
            ok = ok && !!(permit && permit.files && permit.files.length) && !!(validId && validId.files && validId.files.length);
        }
        btn.disabled = !ok;
        btn.style.opacity = ok ? '1' : '0.55';
    }

    /* ── Show loading modal on submit ── */
document.getElementById('setupForm').addEventListener('submit', function(e) {
    const role = document.getElementById('roleInput').value;

    if (!role) {
        e.preventDefault();
        alert('Please select a role (Student, Seller, or Admin).');
        return;
    }

    if (role === 'seller' && (document.getElementById('shopNameVerified') || {}).value !== '1') {
        e.preventDefault();
        alert('Please check that your shop name is available before signing up.');
        return;
    }

    if (role === 'seller') {
        const permit = document.querySelector('input[name="businessPermit"]');
        const validId = document.querySelector('input[name="validId"]');
        if (!permit || !permit.files[0]) {
            e.preventDefault();
            alert('Please upload your Business Permit.');
            return;
        }
        if (!validId || !validId.files[0]) {
            e.preventDefault();
            alert('Please upload your Valid ID.');
            return;
        }
    }

    if (role === 'admin') {
        const adminDoc = document.querySelector('input[name="adminDoc"]');
        if (!adminDoc || !adminDoc.files[0]) {
            e.preventDefault();
            alert('Please upload your Admin ID Document.');
            return;
        }
    }

    document.getElementById('modalOverlay').style.display = 'flex';
});

document.addEventListener('DOMContentLoaded', function () {
    const sp = document.getElementById('signupPass');
    if (sp) {
        sp.addEventListener('input', function () { setupPwdMeter(sp.value); });
        setupPwdMeter(sp.value || '');
    }
    const em = document.querySelector('input[name="signupEmail"]');
    if (em && !em.readOnly) {
        em.addEventListener('blur', async function () {
            const v = (em.value || '').trim();
            if (!v || !v.includes('@')) return;
            try {
                const r = await fetch('signup_checks.php?action=email&value=' + encodeURIComponent(v));
                const j = await r.json();
                if (j.ok && !j.available) {
                    alert(j.message || 'This email is already registered. Please log in instead.');
                }
            } catch (e) {}
        });
    }

    const defaultRoleCard = document.querySelector('.role-card[data-role="student"]');
    if (defaultRoleCard && !document.getElementById('roleInput').value) {
        setRole('student', defaultRoleCard);
    }
});
</script>

</body>
</html>
