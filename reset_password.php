<?php
// THIS FILE VALIDATES RESET TOKENS AND LETS USERS CREATE A NEW PASSWORD.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';

$conn = db_connect(DB_NAME_ANIMEALS);
animeals_ensure_extensions($conn);

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$message = '';
$messageType = 'error';
$resetRow = null;

function reset_password_valid(string $password): bool
{
    // MATCH THE SAME PASSWORD RULES USED BY SIGNUP SO RESET DOES NOT WEAKEN ACCOUNT SECURITY.
    $len = strlen($password);
    return $len >= 16
        && $len <= 32
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

if ($tokenHash !== '') {
    // LOOK UP ONLY UNUSED AND UNEXPIRED TOKENS, USING THE HASH INSTEAD OF THE RAW TOKEN.
    $stmt = db_query(
        $conn,
        'SELECT * FROM password_resets WHERE tokenHash = ? AND usedAt IS NULL AND expiresAt > CURRENT_TIMESTAMP LIMIT 1',
        [$tokenHash]
    );
    $resetRow = $stmt ? db_fetch_assoc($stmt) : null;
}

if (!$resetRow) {
    $message = 'This reset link is invalid or expired. Please request a new one.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $resetRow) {
    // VALIDATE BOTH PASSWORD FIELDS BEFORE WRITING ANYTHING TO THE DATABASE.
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirmPassword'] ?? '');

    if (!reset_password_valid($password)) {
        $message = 'Password must be 16-32 characters and include uppercase, lowercase, a number, and a symbol.';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } else {
        // UPDATE BOTH DATABASE COPIES SO SELLERS CAN STILL LOG IN TO THE SELLER DASHBOARD.
        $email = (string) $resetRow['userEMAIL'];
        $updated = db_query($conn, 'UPDATE user_details SET userPASSWORD = ? WHERE userEMAIL = ?', [$password, $email]);

        $sellerConn = db_connect(DB_NAME_SELLER_DATA);
        if ($sellerConn) {
            db_query($sellerConn, 'UPDATE user_details SET userPASSWORD = ? WHERE userEMAIL = ?', [$password, $email]);
        }

        if ($updated) {
            // MARK THE TOKEN USED SO THE RESET LINK CANNOT BE REPLAYED.
            db_query($conn, 'UPDATE password_resets SET usedAt = CURRENT_TIMESTAMP WHERE resetID = ?', [(int) $resetRow['resetID']]);
            header('Location: index.php?reset=success');
            exit();
        }
        $message = 'Could not update password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANIMEALS | Reset Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#d4f8e8,#e8fff4); font-family:'Segoe UI',sans-serif; padding:20px; }
.reset-card { width:min(430px,100%); background:#fff; border-radius:25px; padding:32px; box-shadow:0 15px 40px rgba(0,0,0,.15); text-align:center; }
.btn-main { border-radius:30px; padding:12px 35px; border:none; background:linear-gradient(135deg,#2ecc71,#0e6e36); color:white; font-weight:700; width:100%; }
.form-control { border-radius:30px; padding:12px 18px; background:#f0f0f0; border:2px solid transparent; margin-bottom:14px; }
.form-control:focus { border-color:#27ae60; box-shadow:none; }
.msg { border-radius:18px; padding:12px 14px; font-size:14px; margin-bottom:16px; background:#fdecec; color:#b43524; }
.pwd-req-pop { margin:0 0 14px; padding:12px 14px; border-radius:14px; background:#1f2937; color:#f9fafb; font-size:12px; line-height:1.45; text-align:left; box-shadow:0 8px 24px rgba(0,0,0,0.12); }
.pwd-req-pop .req-line { padding:4px 0; display:flex; align-items:center; gap:8px; }
.pwd-req-pop .req-line::before { content:''; width:8px; height:8px; border-radius:50%; background:#ef4444; flex-shrink:0; }
.pwd-req-pop .req-line.ok::before { background:#22c55e; }
.pwd-req-pop .req-line.ok { color:#bbf7d0; }
.pwd-req-pop .req-line.bad { color:#fecaca; }
.pwd-strength-wrap { margin-top:8px; }
.pwd-strength-label { font-size:11px; color:#9ca3af; margin-bottom:4px; }
.pwd-strength-track { height:6px; border-radius:6px; background:#374151; overflow:hidden; }
.pwd-strength-fill { height:100%; width:0%; border-radius:6px; transition:width .2s, background .2s; background:#ef4444; }
.pwd-strength-fill.s1 { width:20%; background:#f97316; }
.pwd-strength-fill.s2 { width:40%; background:#eab308; }
.pwd-strength-fill.s3 { width:60%; background:#84cc16; }
.pwd-strength-fill.s4 { width:80%; background:#22c55e; }
.pwd-strength-fill.s5 { width:100%; background:#16a34a; }
a { color:#0e6e36; font-weight:700; text-decoration:none; }
</style>
</head>
<body>
<div class="reset-card">
    <h3>Reset password</h3>
    <p class="text-muted">Choose a new password for your ANIMEALS account.</p>
    <?php if ($message !== ''): ?>
        <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($resetRow): ?>
    <form method="POST" onsubmit="return validateResetPassword()">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="password" name="password" id="resetPassword" class="form-control" placeholder="16-32 characters, mixed case, number, symbol" minlength="16" maxlength="32" required oninput="resetPwdMeter(this.value)">
        <div id="pwdReqPop" class="pwd-req-pop" aria-live="polite">
            <div class="req-line bad" id="req-len">16-32 characters long</div>
            <div class="req-line bad" id="req-lower">At least one lowercase letter</div>
            <div class="req-line bad" id="req-upper">At least one uppercase letter</div>
            <div class="req-line bad" id="req-num">At least one number</div>
            <div class="req-line bad" id="req-sym">At least one symbol (e.g. ! @ # $ %)</div>
            <div class="pwd-strength-wrap">
                <div class="pwd-strength-label">Password strength</div>
                <div class="pwd-strength-track"><div id="pwdStrengthFill" class="pwd-strength-fill"></div></div>
            </div>
        </div>
        <input type="password" name="confirmPassword" class="form-control" placeholder="Confirm new password" required>
        <button type="submit" class="btn-main">Update password</button>
    </form>
    <?php endif; ?>
    <p style="margin-top:18px;"><a href="index.php">Back to login</a></p>
</div>
<script>
function resetPwdState(password) {
    return [
        { id: 'req-len', ok: password.length >= 16 && password.length <= 32 },
        { id: 'req-lower', ok: /[a-z]/.test(password) },
        { id: 'req-upper', ok: /[A-Z]/.test(password) },
        { id: 'req-num', ok: /[0-9]/.test(password) },
        { id: 'req-sym', ok: /[^A-Za-z0-9]/.test(password) }
    ];
}

function resetPwdMeter(password) {
    let score = 0;
    resetPwdState(password).forEach(function (rule) {
        const el = document.getElementById(rule.id);
        if (!el) return;
        el.classList.toggle('ok', rule.ok);
        el.classList.toggle('bad', !rule.ok);
        if (rule.ok) score++;
    });
    const fill = document.getElementById('pwdStrengthFill');
    if (fill) fill.className = 'pwd-strength-fill s' + Math.min(5, Math.max(0, score));
}

function validateResetPassword() {
    const input = document.getElementById('resetPassword');
    const password = input ? input.value : '';
    const ok = resetPwdState(password).every(function (rule) { return rule.ok; });
    if (!ok) {
        alert('Password must be 16-32 characters and include uppercase, lowercase, a number, and a symbol.');
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('resetPassword');
    resetPwdMeter(input ? input.value : '');
});
</script>
</body>
</html>
