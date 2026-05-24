<?php
// THIS FILE STARTS THE FORGOT PASSWORD FLOW AND EMAILS A RESET LINK TO THE USER.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';
require_once __DIR__ . '/brevo_mailer.php';

$conn = db_connect(DB_NAME_ANIMEALS);
// MAKE SURE THE PASSWORD RESET TABLE EXISTS BEFORE ANY RESET LINK IS CREATED.
animeals_ensure_extensions($conn);

$message = '';
$messageType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // ALWAYS SHOW A GENERIC SUCCESS MESSAGE SO PEOPLE CANNOT ENUMERATE REGISTERED EMAILS.
    $email = trim((string) ($_POST['email'] ?? ''));
    $message = 'If that email is registered, a reset link has been sent.';
    $messageType = 'success';

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = db_query($conn, 'SELECT userNAME, userEMAIL FROM user_details WHERE userEMAIL = ? LIMIT 1', [$email]);
        $user = $stmt ? db_fetch_assoc($stmt) : null;

        if ($user) {
            // STORE ONLY THE HASHED TOKEN, THEN EMAIL THE RAW TOKEN TO THE USER ONCE.
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

            // REMOVE OLD TOKENS SO EACH USER HAS ONE CURRENT RESET LINK AT A TIME.
            db_query($conn, 'DELETE FROM password_resets WHERE userEMAIL = ? OR expiresAt < CURRENT_TIMESTAMP OR usedAt IS NOT NULL', [$email]);
            $saved = db_query(
                $conn,
                'INSERT INTO password_resets (userEMAIL, tokenHash, expiresAt) VALUES (?, ?, ?)',
                [$email, $tokenHash, $expires]
            );

            if ($saved) {
                // BUILD A FULL RESET URL THAT WORKS ON BOTH LOCALHOST AND THE LIVE DOMAIN.
                $name = trim((string) ($user['userNAME'] ?? ''));
                $resetLink = animeals_app_url('reset_password.php?token=' . urlencode($token));
                $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
                $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
                $sent = animeals_send_brevo_email(
                    $email,
                    $name,
                    'Reset your ANIMEALS password',
                    "<html><body><h2>Reset your password</h2><p>Hello {$safeName},</p><p>Click the button below to reset your ANIMEALS password. This link expires in 30 minutes.</p><p><a href=\"{$safeLink}\" style=\"display:inline-block;background:#1dbf73;color:#fff;padding:12px 18px;border-radius:20px;text-decoration:none;font-weight:700;\">Reset password</a></p><p>If the button does not work, open this link:<br>{$safeLink}</p><p>If you did not request this, you can ignore this email.</p></body></html>",
                    "Hello " . ($name !== '' ? $name : 'there') . ",\n\nReset your ANIMEALS password using this link:\n{$resetLink}\n\nThis link expires in 30 minutes.\n\nIf you did not request this, ignore this email."
                );

                if (!$sent) {
                    $message = 'We found the account, but Brevo could not send the email. Check the Brevo API key/sender settings.';
                    $messageType = 'error';
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
<title>ANIMEALS | Forgot Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#d4f8e8,#e8fff4); font-family:'Segoe UI',sans-serif; padding:20px; }
.reset-card { width:min(430px,100%); background:#fff; border-radius:25px; padding:32px; box-shadow:0 15px 40px rgba(0,0,0,.15); text-align:center; }
.btn-main { border-radius:30px; padding:12px 35px; border:none; background:linear-gradient(135deg,#2ecc71,#0e6e36); color:white; font-weight:700; width:100%; }
.form-control { border-radius:30px; padding:12px 18px; background:#f0f0f0; border:2px solid transparent; margin:18px 0; }
.form-control:focus { border-color:#27ae60; box-shadow:none; }
.msg { border-radius:18px; padding:12px 14px; font-size:14px; margin-bottom:16px; }
.msg.success { background:#e8fff4; color:#0e6e36; }
.msg.error { background:#fdecec; color:#b43524; }
a { color:#0e6e36; font-weight:700; text-decoration:none; }
</style>
</head>
<body>
<div class="reset-card">
    <h3>Forgot password?</h3>
    <p class="text-muted">Enter your account email and we will send a secure reset link.</p>
    <?php if ($message !== ''): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="email" name="email" class="form-control" placeholder="Email address" required>
        <button type="submit" class="btn-main">Send reset link</button>
    </form>
    <p style="margin-top:18px;"><a href="index.php">Back to login</a></p>
</div>
</body>
</html>
