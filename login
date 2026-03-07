<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANIMEALS: LOGIN & SIGN UP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> 
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
/* kulay ng buttons and texts */
:root {
    --primary-gradient: linear-gradient(135deg, #2ecc71, #0e6e36);
    --bg-gradient: linear-gradient(135deg, #d4f8e8, #e8fff4);
    --accent-color: #27ae60;
    --accent-color2: #c77d9d;
    --neu-white: #ffffff;
    --neu-gray: #cfe9da;
}

/* main body or box para mag center lang */
body {
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    background: var(--bg-gradient);
    background-size: 400% 400%;
    animation: gradientBG 15s ease infinite; 
    font-family: 'Segoe UI', sans-serif;
    overflow: hidden;
}

/* main box talaga */
.main-box {
    width: 900px;
    height: 560px;
    background: #fff;
    border-radius: 25px;
    overflow: hidden;
    display: flex;
    box-shadow: 0 15px 40px rgba(0,0,0,.15);
}

/* left side, kasama yung vid and everything */
.left-panel {
    width: 35%; 
    /* etong width para sa size nung video or yung left panel overall */
    background: url('delivery.gif') center center / cover no-repeat;
    background-color: #fce4ec;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: flex-end;
}

/* yung gumagalaw na oblong pang priness yung login or sign up */
.toggle-box {
    position: relative;
    z-index: 1;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

/* yung mismong oblong i2 */
.toggle-slider {
    position: absolute;
    width: 140px; 
    height: 50px;
    background: white;
    border-radius: 30px 0 0 30px; 
    top: -2px;
    right: 0;
    transition: .5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    box-shadow: -5px 0 15px rgba(0,0,0,0.05);
}

/* pang smooth ng edges sa oblong */
.toggle-slider::after, .toggle-slider::before {
    content: ""; 
    position: absolute; 
    right: 0; 
    width: 20px; 
    height: 20px; 
    background: transparent;
}

/* babang smoother */
.toggle-slider::after { 
    bottom: -17.5px; 
    border-top-right-radius: 20px; 
    box-shadow: 10px 0 0 0 white; }

/* upper smoother */
.toggle-slider::before { 
    top: -17.5px; 
    border-bottom-right-radius: 20px; 
    box-shadow: 10px 0 0 0 white; }

/*transition*/
.toggle-box.signup-active .toggle-slider { 
    transform: translateY(53px); }

/* button para mag switch into login or sign up page */
.toggle-btn {
    width: 140px; 
    height: 50px; 
    display: flex; 
    align-items: center; 
    justify-content: center;
    font-weight: 700; 
    color: rgba(255, 255, 255, 0.8); 
    cursor: pointer; 
    position: relative; 
    z-index: 2; 
    transition: 0.3s;
}

/* pagbabago ng color pag press */
.toggle-btn.active {
    color: var(--accent-color);
}

/*right side position*/
.right-panel {
    width: 65%;
    padding: 30px 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

/* eto rin same lang */
.form-box {
    width: 100%;
    max-width: 360px;
    text-align: center;
    position: absolute;
    transition: all 0.6s ease-in-out;
}

.form-hidden {
    opacity: 0;
    transform: translateY(30px) scale(0.9);
    pointer-events: none;
}

/* logo ito */
.logo{
    width: 200px;
    height: 150px;
    border-radius: 20px;
    overflow: hidden;
    margin: 0 auto -10px;
    margin-top: -20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* size ng logo */
.logo video{
    width: 100%;
    height: 100%;
    object-fit: cover;
}
/* transition lang ng logo */
.form-visible .logo{ 
    animation: popIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275); }

/* short description langs*/
.description-text {
    font-size: 13.5px;
    color: #777;
    margin-bottom: 20px;
}

/* 3d effects ng mga fill */
.form-control {
    border-radius: 30px; 
    padding: 11px 20px; 
    margin-bottom: 15px; 
    border: 2px solid transparent;
    background-color: #f0f0f0;
    box-shadow: inset 6px 6px 10px var(--neu-gray), 
                inset -6px -6px 10px var(--neu-white);
    transition: 0.3s;
    outline: none;
    width: 100%;
}

.form-control:focus {
   background-color: #f0f0f0; 
    border: 2px solid var(--accent-color); 
    box-shadow: inset 6px 6px 10px var(--neu-gray), 
                inset -6px -6px 10px var(--neu-white);
    outline: none;
}

/* same position sa login and sign up */
.pass-wrapper {
    position: relative;
    width: 100%;
}

/* matang icon position */
.eye-icon {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #888;
    font-size: 18px;
    z-index: 5;
    transition: 0.3s;
}

/* kulay ng check sa keep me logged in */
.form-check-input:checked {
    background-color: var(--accent-color);
    border-color: var(--accent-color);
}

/* design ng mga buttons */
.btn-main {
    border-radius: 30px; 
    padding: 12px 35px; 
    border: none;
    background: var(--primary-gradient); 
    color: white;
    font-weight: 600; 
    transition: 0.3s, box-shadow 0.3s;
}

/* hover effects ng buttons */
.btn-main:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 15px #2ecc71, 0 0 30px #27ae60;
}
/* social media */
.social-container {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 10px;
}

/* specialz effects ng ating socials */
.social-item {
    display: flex;
    align-items: center;
    background: #f0f0f0;
    padding: 10px 14px;
    border-radius: 30px;
    cursor: pointer;
    overflow: hidden;
    max-width: 45px; 
    white-space: nowrap;
    transition: max-width 0.5s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s;
    box-shadow: 4px 4px 8px var(--neu-gray), 
                -4px -4px 8px var(--neu-white);
}

.social-item i {
    font-size: 18px;
    color: #555;
    min-width: 20px;
}

/* para sa logo lang ng social makikita */
.social-item span {
    margin-left: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #555;
    opacity: 0;
    transition: opacity 0.3s ease;
}

/* hover effects */
.social-item:hover {
    max-width: 150px; 
}

.social-item:hover span {
    opacity: 1;
}

.google:hover i { color: #DB4437; }
.facebook:hover i { color: #4267B2; }

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

        <div class="form-box login-form form-visible" id="loginForm">
             <div class="logo">
    <video autoplay muted loop playsinline>
        <source src="vid.mp4">
    </video>
</div>
            <h4 class="mb-1">WELCOME BACK!</h4>
            <p class="description-text">Log in to continue your food journey.</p>
            
            <input type="email" class="form-control" placeholder="Email">
            
            <div class="pass-wrapper">
                <input type="password" class="form-control" placeholder="Password" id="loginPass">
                <i class="bi bi-eye-slash eye-icon" onclick="togglePass('loginPass', this)"></i>
            </div>

            <div class="action-row d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="keepMeLogin">
                    <label class="form-check-label" for="keepMeLogin" style="font-size: 13px; color: #888; cursor: pointer;">
                        Keep me logged in
                    </label>
                </div>
                <a href="#" style="text-decoration:none; color:#1ad361; font-size:13px;">Forgot password?</a>
            </div>

            <button class="btn-main w-100 mb-4">LOGIN</button>

            <div class="social">
                <p style="font-size:13px; color:#888;">or login with</p>
                <div class="social-container">
                    <div class="social-item google">
                        <i class="bi bi-google"></i>
                        <span>Google</span>
                    </div>
                    <div class="social-item facebook">
                        <i class="bi bi-facebook"></i>
                        <span>Facebook</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-box signup-form form-hidden" id="signupForm">
            <div class="logo">
    <video autoplay muted loop playsinline>
        <source src="vid.mp4">
    </video>
</div>
            <h4 class="mb-1">CREATE YOUR ACCOUNT</h4>
            <p class="description-text">Join us and enjoy delicious meals delivered fast.</p>
            
            <input type="text" class="form-control" placeholder="Full Name">
            <input type="email" class="form-control" placeholder="Email">
            
            <div class="pass-wrapper">
                <input type="password" class="form-control" placeholder="Password (9 characters)" id="signupPass">
                <i class="bi bi-eye-slash eye-icon" onclick="togglePass('signupPass', this)"></i>
            </div>

            <button class="btn-main w-100 mt-2 mb-4">SIGN UP</button>

            <div class="social">
                <p style="font-size:13px; color:#888;">or sign up with</p>
                <div class="social-container">
                    <div class="social-item google">
                        <i class="bi bi-google"></i>
                        <span>Google</span>
                    </div>
                    <div class="social-item facebook">
                        <i class="bi bi-facebook"></i>
                        <span>Facebook</span>
                    </div>
                </div>
            </div>
        </div>

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
}

function showLogin(){
    toggleBox.classList.remove("signup-active");
    signupForm.classList.replace("form-visible", "form-hidden");
    loginForm.classList.replace("form-hidden", "form-visible");
    loginToggle.classList.add("active");
    signupToggle.classList.remove("active");
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
</script>

</body>
</html>
