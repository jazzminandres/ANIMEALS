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
    
    // Check for URL parameters to display messages
    const urlParams = new URLSearchParams(window.location.search);
    const error = urlParams.get('error');
    
    if (error === 'not_registered') {
        const errorContainer = document.getElementById('error-container');
        errorContainer.innerHTML = '<div class="alert alert-danger" style="position:absolute; top:15px; font-size:13px;">No account found for that Google email. Please sign up first.</div>';
    }
});