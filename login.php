<?php
session_start();

$serverName = "SatanaelLG\MSSQLSERVER01";
$connectionOptions = [
    "Database" => "ANIMEALS",
    "Uid" => "",
    "PWD" => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) { 
    die(print_r(sqlsrv_errors(), true));
}

$message = '';

/* ---------- SIGNUP ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signupBtn'])) {

    $fullname = trim($_POST['signupName']);
    $emailuser = trim($_POST['signupEmail']);
    $password = $_POST['signupPass'];

    if ($fullname && $emailuser && $password) {

        $sql = "INSERT INTO USER_DETAILS (userNAME, userPASSWORD, userEMAIL) VALUES (?, ?, ?)";
        $params = [$fullname, $password, $emailuser];

        $result = sqlsrv_query($conn, $sql, $params);

        if ($result) {
            $message = "Account created successfully!";
        } else {
            $message = "Error: Username may already exist.";
        }

    } else {
        $message = "Please fill in all required fields.";
    }
}


/* ---------- LOGIN ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loginBtn'])) {

    $email = trim($_POST['LOGemail']);
    $password = $_POST['LOGpassword'];

    if ($email && $password) {

        $sql = "SELECT * FROM USER_DETAILS WHERE userEMAIL = ? AND userPASSWORD = ?";
        $params = [$email, $password];

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt && sqlsrv_has_rows($stmt)) {

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            $_SESSION['user']  = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            $_SESSION['role']  = $row['userROLE'];

            if ($row['userROLE'] === 'admin') {
                header("Location: admin.php");
            } elseif ($row['userROLE'] === 'seller') {
                header("Location: seller.php");
            } else {
                header("Location: student.php");
            }
            exit();

        } else {
            $message = "Invalid email or password.";
        }

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
.facebook:hover i { color: #4267B2; }
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
        <?php if (isset($_GET['error']) && $_GET['error'] === 'not_registered'): ?>
            <div class="alert alert-danger" style="position:absolute; top:15px; font-size:13px;">
                No account found for that Google email. Please sign up first.
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

            <input type="email" class="form-control" name="LOGemail" placeholder="Email">

            <div class="pass-wrapper">
                <input type="password" class="form-control" name="LOGpassword" placeholder="Password" id="loginPass">
                <i class="bi bi-eye-slash eye-icon" onclick="togglePass('loginPass', this)"></i>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="keepMeLogin">
                    <label class="form-check-label" for="keepMeLogin" style="font-size: 13px; color: #888; cursor:pointer;">Keep me logged in</label>
                </div>
                <a href="#" style="text-decoration:none; color:#1ad361; font-size:13px;">Forgot password?</a>
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
                    <div class="social-item facebook">
                        <i class="bi bi-facebook"></i>
                        <span>Facebook</span>
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
        Fill in your details and set up your profile to get started.
    </p>

    <a href="profileSetup.php" class="btn-main w-100 mb-4 d-block text-center text-decoration-none" style="padding: 12px 35px; line-height: 1.5;">
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
            <div class="social-item facebook">
                <i class="bi bi-facebook"></i>
                <span>Facebook</span>
            </div>
        </div>
    </div>
</div>

    </div> <!-- closes .right-panel -->
</div> <!-- closes .main-box -->

<script>
const loginForm = document.getElementById("loginForm");
const signupForm = document.getElementById("signupForm");
const toggleBox = document.getElementById("toggle");
const loginToggle = document.getElementById("loginToggle");
const signupToggle = document.getElementById("signupToggle");

const signupPasswordInput = document.getElementById("signupPass");
const passwordRequirements = document.getElementById("password-requirements");
const requirements = {
    lowercase: document.getElementById("lowercase-req"),
    uppercase: document.getElementById("uppercase-req"),
    number: document.getElementById("number-req"),
    special: document.getElementById("special-req"),
    length: document.getElementById("length-req")
};

function showSignup(){
    toggleBox.classList.add("signup-active");
    loginForm.classList.replace("form-visible", "form-hidden");
    signupForm.classList.replace("form-hidden", "form-visible");
    signupToggle.classList.add("active");
    loginToggle.classList.remove("active");
    passwordRequirements.classList.add("visible");
}

function showLogin(){
    toggleBox.classList.remove("signup-active");
    signupForm.classList.replace("form-visible", "form-hidden");
    loginForm.classList.replace("form-hidden", "form-visible");
    loginToggle.classList.add("active");
    signupToggle.classList.remove("active");
    passwordRequirements.classList.remove("visible");
    signupPasswordInput.value = "";
    updatePasswordRequirements("");
}

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

function updatePasswordRequirements(password) {
    const hasLowercase = /[a-z]/.test(password);
    const hasUppercase = /[A-Z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSpecial = /[!@#$%^&*]/.test(password);
    const hasLength = password.length >= 9;

    requirements.lowercase.classList.toggle("valid", hasLowercase);
    requirements.uppercase.classList.toggle("valid", hasUppercase);
    requirements.number.classList.toggle("valid", hasNumber);
    requirements.special.classList.toggle("valid", hasSpecial);
    requirements.length.classList.toggle("valid", hasLength);

    const validCount = [hasLowercase, hasUppercase, hasNumber, hasSpecial, hasLength].filter(Boolean).length;
    const strengthBar = document.getElementById("strength-bar");
    if (strengthBar) {
        strengthBar.style.width = (validCount / 5) * 100 + "%";
        strengthBar.className = `strength-bar strength-${validCount}`;
    }
}

signupPasswordInput.addEventListener("input", function() {
    updatePasswordRequirements(this.value);
});

document.addEventListener("DOMContentLoaded", function() {
    updatePasswordRequirements("");
});
</script>

</body>
</html>