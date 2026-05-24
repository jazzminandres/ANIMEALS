<?php
// THIS FILE SHOWS THE MAIN LOGIN PAGE AND ROUTES USERS INTO THE WEBSITE.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_SERVER['QUERY_STRING'] ?? '') === '') {
    header('Location: inter.html');
    exit;
}

require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

$conn = db_connect();
$BREVO_API_KEY = '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';
$REMEMBER_SECRET = 'animeals_remember_secret_2026';

function createPendingTable(mysqli $conn): void
{
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
    global $BREVO_API_KEY;

    $payload = [
        'sender' => ['name' => 'ANIMEALS', 'email' => 'linianlunar@gmail.com'],
        'to' => [['email' => $to, 'name' => $name]],
        'subject' => 'Verify your ANIMEALS account',
        'htmlContent' => "<html><body><h2>Verify your email</h2><p>Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",</p><p>Your verification code is <strong>{$code}</strong>.</p><p>Enter it on the verification page to complete your sign up.</p><p>If you did not request this, please ignore this email.</p></body></html>",
        'textContent' => "Hello {$name},\n\nYour ANIMEALS verification code is {$code}.\n\nEnter it on the verification page to complete your sign up.",
    ];

    if (function_exists('curl_version')) {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $BREVO_API_KEY,
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $response !== false && $status >= 200 && $status < 300;
    }

    return false;
}

function autoLoginFromCookie(mysqli $conn): void
{
    global $REMEMBER_SECRET;
    if (!empty($_SESSION['user']) || !empty($_SESSION['email'])) {
        return;
    }

    if (!empty($_COOKIE['remember_email']) && !empty($_COOKIE['remember_token'])) {
        $rememberEmail = $_COOKIE['remember_email'];
        $token = $_COOKIE['remember_token'];
        $expected = hash_hmac('sha256', $rememberEmail, $REMEMBER_SECRET);

        if (hash_equals($expected, $token)) {
            $stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$rememberEmail]);
            if ($stmt && ($row = db_fetch_assoc($stmt))) {
                $_SESSION['user'] = $row['userNAME'];
                $_SESSION['email'] = $row['userEMAIL'];
                $_SESSION['role'] = $row['userROLE'];
            }
        } else {
            setcookie('remember_email', '', time() - 3600, '/');
            setcookie('remember_token', '', time() - 3600, '/');
        }
    }
}

function setRememberCookie($email): void
{
    global $REMEMBER_SECRET;
    setcookie('remember_email', $email, time() + 30 * 24 * 60 * 60, '/');
    setcookie('remember_token', hash_hmac('sha256', $email, $REMEMBER_SECRET), time() + 30 * 24 * 60 * 60, '/');
}

function clearRememberCookie(): void
{
    setcookie('remember_email', '', time() - 3600, '/');
    setcookie('remember_token', '', time() - 3600, '/');
}

createPendingTable($conn);
autoLoginFromCookie($conn);

if (isset($_SESSION['user'], $_SESSION['email'])) {
    $roleStmt = db_query($conn, "SELECT userROLE FROM user_details WHERE userEMAIL = ?", [$_SESSION['email']]);
    if ($roleStmt && ($roleRow = db_fetch_assoc($roleStmt))) {
        $r = strtolower(trim((string) ($roleRow['userROLE'] ?? 'student')));
        if ($r === 'admin') {
            header('Location: admin.php');
            exit();
        }
        if ($r === 'seller') {
            header('Location: seller.php');
            exit();
        }
        header('Location: student.php');
        exit();
    }
}

$message = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (($_GET['reset'] ?? '') === 'success') {
    $message = 'Password updated. Please log in with your new password.';
}

/* ---------- SIGNUP ---------- */
if ($requestMethod === 'POST' && isset($_POST['signupBtn'])) {
    $fullname = trim($_POST['signupName']);
    $emailuser = trim($_POST['signupEmail']);
    $password = $_POST['signupPass'];

    if ($fullname && $emailuser && $password) {
        $checkSql = "SELECT userEMAIL FROM user_details WHERE userEMAIL = ?";
        $checkStmt = db_query($conn, $checkSql, [$emailuser]);
        $checkRow = $checkStmt ? db_fetch_assoc($checkStmt) : null;

        if ($checkRow) {
            $message = "This email is already registered. Please log in.";
        } else {
            $code = random_int(100000, 999999);
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $expiry = clone $now;
            $expiry->modify('+15 minutes');

            db_query($conn, "DELETE FROM signup_pending WHERE pendingEMAIL = ?", [$emailuser]);

            $insertSql = "INSERT INTO signup_pending (pendingEMAIL, pendingNAME, pendingPASS, pendingCODE, expiresAt, createdAt) VALUES (?, ?, ?, ?, ?, ?)";
            $insertParams = [$emailuser, $fullname, $password, (string) $code, $expiry->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')];
            $insertResult = db_query($conn, $insertSql, $insertParams);

            if ($insertResult && sendBrevoVerificationEmail($emailuser, $fullname, $code)) {
                header('Location: verify.php?email=' . urlencode($emailuser));
                exit();
            }

            $message = "Unable to send verification email. Please try again later.";
        }
    } else {
        $message = "Please fill in all required fields.";
    }
}

/* ---------- LOGIN ---------- */
if ($requestMethod === 'POST' && isset($_POST['loginBtn'])) {
    $email = trim($_POST['LOGemail']);
    $password = $_POST['LOGpassword'];

    if ($email && $password) {
        $sql = "SELECT * FROM user_details WHERE userEMAIL = ? AND userPASSWORD = ?";
        $stmt = db_query($conn, $sql, [$email, $password]);
        $row = $stmt ? db_fetch_assoc($stmt) : null;

        if ($row) {
            $_SESSION['user'] = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            $_SESSION['role'] = $row['userROLE'];

            if (!empty($_POST['rememberMe'])) {
                setRememberCookie($email);
            } else {
                clearRememberCookie();
            }

            if ($row['userROLE'] === 'admin') {
                header("Location: admin.php");
            } elseif ($row['userROLE'] === 'seller') {
                header("Location: seller.php");
            } else {
                header("Location: student.php");
            }
            exit();
        }

        $pendingStmt = db_query($conn, "SELECT pendingEMAIL FROM signup_pending WHERE pendingEMAIL = ?", [$email]);
        $pendingRow = $pendingStmt ? db_fetch_assoc($pendingStmt) : null;
        if ($pendingRow) {
            header('Location: verify.php?email=' . urlencode($email));
            exit();
        }

        clearRememberCookie();
        $message = "Invalid email or password.";
    } else {
        $message = "Please enter email and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANIMEALS: LOGIN & SIGN UP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> 
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root { 
    --primary-gradient: linear-gradient(135deg, #2ecc71, #0e6e36); 
    --bg-gradient: linear-gradient(135deg, #d4f8e8, #e8fff4); 
    --accent-color: #27ae60; 
    --neu-white: #ffffff; 
    --neu-gray: #cfe9da; 
}
body { 
    min-height: 100vh; display: flex; justify-content: center; align-items: center; 
    background: var(--bg-gradient); background-size: 400% 400%; animation: gradientBG 15s ease infinite; 
    font-family: 'Segoe UI', sans-serif; overflow: hidden; 
}
.main-box { width: 900px; height: 560px; background: #fff; border-radius: 25px; overflow: hidden; display: flex; box-shadow: 0 15px 40px rgba(0,0,0,.15); }
.left-panel { width: 35%; background: url('delivery.gif') center center / cover no-repeat; background-color: #fce4ec; position: relative; display: flex; flex-direction: column; justify-content: center; align-items: flex-end; }
.toggle-box { position: relative; z-index: 1; width: 100%; display: flex; flex-direction: column; align-items: flex-end; }
.toggle-slider { position: absolute; width: 140px; height: 50px; background: white; border-radius: 30px 0 0 30px; top: -2px; right: 0; transition: .5s cubic-bezier(0.68, -0.55, 0.265, 1.55); box-shadow: -5px 0 15px rgba(0,0,0,0.05); }
.toggle-slider::after, .toggle-slider::before { content: ""; position: absolute; right: 0; width: 20px; height: 20px; background: transparent; }
.toggle-slider::after { bottom: -17.5px; border-top-right-radius: 20px; box-shadow: 10px 0 0 0 white; }
.toggle-slider::before { top: -17.5px; border-bottom-right-radius: 20px; box-shadow: 10px 0 0 0 white; }
.toggle-box.signup-active .toggle-slider { transform: translateY(53px); }
.toggle-btn { width: 140px; height: 50px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: rgba(255, 255, 255, 0.8); cursor: pointer; position: relative; z-index: 2; transition: 0.3s; }
.toggle-btn.active { color: var(--accent-color); }
.right-panel { 
    width: 65%; 
    padding: 30px 70px 30px 50px;
    display: flex; 
    align-items: center; 
    justify-content: center; 
    position: relative; 
}
.form-box { 
    width: 100%; 
    max-width: 340px;
    text-align: center; 
    position: absolute; 
    transition: all 0.6s ease-in-out; 
}
.signup-form {

}
.form-hidden { opacity: 0; transform: translateY(30px) scale(0.9); pointer-events: none; }
.logo{ width: 200px; height: 150px; border-radius: 20px; overflow: hidden; margin: 0 auto -10px; margin-top: -20px; display: flex; align-items: center; justify-content: center; }
.logo video{ width: 100%; height: 100%; object-fit: cover; }
.form-visible .logo{ animation: popIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275)); }
.description-text { font-size: 13.5px; color: #777; margin-bottom: 20px; }
.form-control { border-radius: 30px; padding: 11px 20px; margin-bottom: 15px; border: 2px solid transparent; background-color: #f0f0f0; box-shadow: inset 6px 6px 10px var(--neu-gray), inset -6px -6px 10px var(--neu-white); transition: 0.3s; outline: none; width: 100%; }
.form-control:focus { border: 2px solid var(--accent-color); box-shadow: inset 6px 6px 10px var(--neu-gray), inset -6px -6px 10px var(--neu-white); }
.pass-wrapper { position: relative; width: 100%; }
.eye-icon { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #888; font-size: 18px; z-index: 5; transition: 0.3s; }
.btn-main { border-radius: 30px; padding: 12px 35px; border: none; background: var(--primary-gradient); color: white; font-weight: 600; transition: 0.3s, box-shadow 0.3s; }
.btn-main:hover { transform: translateY(-2px); box-shadow: 0 0 15px #2ecc71, 0 0 30px #27ae60; }
.form-check-input:checked { background-color: var(--accent-color); border-color: var(--accent-color); }
.social-container { display: flex; justify-content: center; gap: 15px; margin-top: 10px; }
.social-item { display: flex; align-items: center; background: #f0f0f0; padding: 10px 14px; border-radius: 30px; cursor: pointer; overflow: hidden; max-width: 45px; white-space: nowrap; transition: max-width 0.5s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s; box-shadow: 4px 4px 8px var(--neu-gray), -4px -4px 8px var(--neu-white); }
.social-item i { font-size: 18px; color: #555; min-width: 20px; }
.social-item span { margin-left: 10px; font-size: 13px; font-weight: 600; color: #555; opacity: 0; transition: opacity 0.3s ease; }
.social-item:hover { max-width: 150px; }
.social-item:hover span { opacity: 1; }
.google:hover i { color: #DB4437; }
.password-requirements {
    position: absolute;
    right: -190px;
    top: 53%;
    transform: translateY(-50%);
    width: 170px;
    max-height: 220px;
    padding: 18px 12px;
    border-radius: 20px;
    background: var(--primary-gradient);
    color: #fff;
    font-size: 11px;
    line-height: 1.3;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-50%) translateX(20px);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    z-index: 20;
    overflow: hidden;
}
.password-requirements.visible {
    opacity: 1;
    visibility: visible;
    transform: translateY(-50%) translateX(0);
}
.password-requirements span {
    display: block;
    margin: 6px 0;
    padding: 4px 8px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    transition: all 0.3s ease;
    font-weight: 500;
}
.password-requirements span.valid {
    background: rgba(255, 255, 255, 0.9);
    color: var(--accent-color);
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(46, 204, 113, 0.3);
}
.strength-container {
    margin-top: 12px;
    height: 4px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    overflow: hidden;
}
.strength-bar {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s ease, background 0.3s ease;
}
.strength-0 { background: #e74c3c; }
.strength-1 { background: #f39c12; }
.strength-2 { background: #f1c40f; }
.strength-3 { background: #27ae60; }
.strength-4 { background: #2ecc71; }
.strength-5 { background: #2ecc71; box-shadow: 0 0 10px #2ecc71; }
@media (max-width: 768px) {
    .password-requirements {
        position: relative;
        right: auto;
        top: auto;
        transform: none;
        width: 100%;
        margin-top: 15px;
    }
    .signup-form .pass-wrapper {
        margin-bottom: 10px;
    }
}

.modal-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.45);
    z-index: 1200;
    padding: 20px;
}
.modal-overlay.show {
    display: flex;
}
.modal-card {
    width: min(420px, 100%);
    border-radius: 24px;
    background: #fff;
    padding: 28px 26px;
    box-shadow: 0 26px 80px rgba(0, 0, 0, 0.18);
    text-align: center;
}
.modal-card h3 {
    margin: 0 0 12px;
    font-size: 1.35rem;
    color: #111;
}
.modal-card p {
    margin: 0 0 22px;
    color: #555;
    font-size: 0.95rem;
    line-height: 1.6;
}
.modal-icon {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #f8d7da;
    color: #842029;
    font-weight: 700;
    margin-bottom: 18px;
    font-size: 1.4rem;
}
</style>
</head>
<body>

<div class="main-box">
    <div class="left-panel">
        <div class="toggle-box" id="toggle"> 
            <div class="toggle-slider"></div>
            <div class="toggle-btn active" id="loginToggle" onclick="showLogin()">LOGIN</div>
            <div class="toggle-btn" id="signupToggle" onclick="showSignup()">SIGN UP</div>
        </div>
    </div>

    <div class="right-panel">
        <?php if (!empty($message)): ?>
            <div class="alert alert-warning" style="position:absolute; top:15px; font-size:13px;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <!-- LOGIN FORM -->
        <form method="POST" class="form-box login-form form-visible" id="loginForm">

            <div class="logo">
                <video autoplay muted loop playsinline>
                    <source src="vid.mp4">
                </video>
            </div>

            <h4 class="mb-1">WELCOME BACK!</h4>
            <p class="description-text">Log in to continue your food journey.</p>

            <input type="email" class="form-control" name="LOGemail" placeholder="Email" value="<?php echo htmlspecialchars($_COOKIE['remember_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <div class="pass-wrapper">
                <input type="password" class="form-control" name="LOGpassword" placeholder="Password" id="loginPass" autocomplete="current-password">
                <i class="bi bi-eye-slash eye-icon" onclick="togglePass('loginPass', this)"></i>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="keepMeLogin" name="rememberMe" value="1">
                    <label class="form-check-label" for="keepMeLogin" style="font-size: 13px; color: #888; cursor:pointer;">Keep me logged in</label>
                </div>
                <a href="forgot_password.php" style="text-decoration:none; color:#1ad361; font-size:13px;">Forgot password?</a>
            </div>

            <button type="submit" class="btn-main w-100 mb-4" name="loginBtn">LOGIN</button>

            <div class="social">
                <p style="font-size:13px; color:#888;">or login with</p>
                <div class="social-container">
                    <div class="social-item google">
                        <a href="loginREDIRECT.php?source=login" style="text-decoration:none; display:flex; align-items:center;">
                            <i class="bi bi-google"></i>
                            <span>Google</span>
                        </a>
                    </div>
                </div>
            </div>
        </form>

        <!-- SIGNUP FORM -->
<!-- SIGNUP PANEL -->
<div class="form-box signup-form form-hidden" id="signupForm">

    <div class="logo">
        <video autoplay muted loop playsinline>
            <source src="vid.mp4">
        </video>
    </div>

    <h4 class="mb-1">CREATE YOUR ACCOUNT</h4>
    <p class="description-text">Join us and enjoy delicious meals delivered fast.</p>

    <p style="font-size: 13px; color: #888; margin-bottom: 25px;">
        Start your signup process on the full registration page.
    </p>

    <a href="profileSetup.php?reset_google=1" class="btn-main w-100 mb-4 d-block text-center text-decoration-none" style="padding: 12px 35px; line-height: 1.5;">
        GET STARTED →
    </a>

    <div class="social">
        <p style="font-size:13px; color:#888;">or sign up with</p>
        <div class="social-container">
            <div class="social-item google">
                <a href="loginREDIRECT.php?source=signup" style="text-decoration:none; display:flex; align-items:center;">
                    <i class="bi bi-google"></i>
                    <span>Google</span>
                </a>
            </div>
        </div>
    </div>
</div>

    </div> <!-- closes .right-panel -->
</div> <!-- closes .main-box -->

<div class="modal-overlay" id="googleNotFoundModal" aria-hidden="true">
    <div class="modal-card">
        <div class="modal-icon">!</div>
        <h3>User not found</h3>
        <p>No account exists for that Google email. Please sign up first or use another account.</p>
        <button type="button" class="btn-main" id="googleNotFoundClose">OK</button>
    </div>
</div>

<script>
const loginForm = document.getElementById("loginForm");
const signupForm = document.getElementById("signupForm");
const toggleBox = document.getElementById("toggle");
const loginToggle = document.getElementById("loginToggle");
const signupToggle = document.getElementById("signupToggle");

function showSignup(){
    toggleBox.classList.add("signup-active");
    loginForm.classList.replace("form-visible", "form-hidden");
    signupForm.classList.replace("form-hidden", "form-visible");
    signupToggle.classList.add("active");
    loginToggle.classList.remove("active");
    const pr = document.getElementById("password-requirements");
    if (pr) pr.classList.add("visible");
}

function showLogin(){
    toggleBox.classList.remove("signup-active");
    signupForm.classList.replace("form-visible", "form-hidden");
    loginForm.classList.replace("form-hidden", "form-visible");
    loginToggle.classList.add("active");
    signupToggle.classList.remove("active");
    const pr = document.getElementById("password-requirements");
    if (pr) pr.classList.remove("visible");
    const lp = document.getElementById("loginPass");
    if (lp) lp.value = "";
}

function showGoogleNotFoundModal() {
    const modal = document.getElementById("googleNotFoundModal");
    if (!modal) return;
    modal.classList.add("show");
    modal.setAttribute("aria-hidden", "false");
}

function closeGoogleNotFoundModal() {
    const modal = document.getElementById("googleNotFoundModal");
    if (!modal) return;
    modal.classList.remove("show");
    modal.setAttribute("aria-hidden", "true");
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname);
    }
}

window.addEventListener("DOMContentLoaded", function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get("error") === "not_registered") {
        showGoogleNotFoundModal();
        const closeButton = document.getElementById("googleNotFoundClose");
        if (closeButton) {
            closeButton.addEventListener("click", closeGoogleNotFoundModal);
        }
    }
});

function togglePass(inputId, iconElement) {
    const passInput = document.getElementById(inputId);
    if (passInput.type === "password") {
        passInput.type = "text";
        iconElement.classList.replace("bi-eye-slash", "bi-eye");
    } else {
        passInput.type = "password";
        iconElement.classList.replace("bi-eye", "bi-eye-slash");
    }
}
</script>

</body>
</html>
