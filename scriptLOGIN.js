// UNUSED: LOGIN AND SIGNUP BEHAVIOR IS NOW INLINE IN INDEX.PHP.
// THIS SCRIPT CONTROLS THE LOGIN AND SIGNUP FORM SWITCHING, VALIDATION HELPERS, AND PASSWORD UI.
const loginForm = document.getElementById("loginForm");
const signupForm = document.getElementById("signupForm");
const toggleBox = document.getElementById("toggle");
const loginToggle = document.getElementById("loginToggle");
const signupToggle = document.getElementById("signupToggle");

const signupPasswordInput = document.getElementById("signupPass");
const passwordRequirements = document.getElementById("password-requirements");
// THESE ELEMENTS TURN GREEN/RED AS THE USER TYPES A PASSWORD.
const requirements = {
    lowercase: document.getElementById("lowercase-req"),
    uppercase: document.getElementById("uppercase-req"),
    number: document.getElementById("number-req"),
    special: document.getElementById("special-req"),
    length: document.getElementById("length-req")
};

function showSignup(){
    // SLIDE THE AUTH PANEL INTO SIGNUP MODE AND SHOW PASSWORD REQUIREMENTS.
    toggleBox.classList.add("signup-active");
    loginForm.classList.replace("form-visible", "form-hidden");
    signupForm.classList.replace("form-hidden", "form-visible");
    signupToggle.classList.add("active");
    loginToggle.classList.remove("active");
    passwordRequirements.classList.add("visible");
}

function showLogin(){
    // RETURN TO LOGIN MODE AND CLEAR THE SIGNUP PASSWORD FIELD FOR PRIVACY.
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
    // SWITCH A PASSWORD FIELD BETWEEN HIDDEN AND VISIBLE TEXT.
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
    // CHECK THE PASSWORD AGAINST THE LIVE REQUIREMENT LIST AND UPDATE THE STRENGTH BAR.
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
    // RECHECK THE PASSWORD EVERY TIME THE USER TYPES.
    updatePasswordRequirements(this.value);
});

document.addEventListener("DOMContentLoaded", function() {
    // START WITH THE REQUIREMENT LIST IN ITS DEFAULT EMPTY-PASSWORD STATE.
    updatePasswordRequirements("");
    
    // CHECK FOR URL PARAMETERS TO DISPLAY LOGIN/SIGNUP MESSAGES.
    const urlParams = new URLSearchParams(window.location.search);
    const error = urlParams.get('error');
    
    if (error === 'not_registered') {
        const errorContainer = document.getElementById('error-container');
        errorContainer.innerHTML = '<div class="alert alert-danger" style="position:absolute; top:15px; font-size:13px;">No account found for that Google email. Please sign up first.</div>';
    }
});
