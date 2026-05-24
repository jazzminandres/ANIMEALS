<?php
// UNUSED: AUTHENTICATION NOW RUNS THROUGH INDEX.PHP AND THE GOOGLE CALLBACK FLOW.
// THIS FILE SETS UP GOOGLE LOGIN AND HOLDS THE AUTHENTICATION CONFIG USED BY THE LOGIN FLOW.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

// USE THE CENTRALIZED MYSQL HELPER SO AUTH USES THE SAME CONNECTION RULES AS THE REST OF THE APP.
$conn = db_connect(DB_NAME_ANIMEALS);

$message = '';

/* ---------- SIGNUP ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signupBtn'])) {

    // COLLECT BASIC SIGNUP VALUES FROM THE LOGIN/SIGNUP FORM.
    $fullname = trim($_POST['signupName']);
    $emailuser = trim($_POST['signupEmail']);
    $password = $_POST['signupPass'];

    if ($fullname && $emailuser && $password) {

        // SAVE THE NEW ACCOUNT WITH A PREPARED QUERY SO THE FORM INPUT STAYS SAFE.
        $sql = "INSERT INTO USER_DETAILS (userNAME, userPASSWORD, userEMAIL) VALUES (?, ?, ?)";
        $params = [$fullname, $password, $emailuser];

        $res = db_query($conn, $sql, $params);
        if ($res === false) {
            $message = "Error: Could not create account.";
        } else {
            $message = "Account created successfully!";
        }

    } else {
        $message = "Please fill in all required fields.";
    }
}


/* ---------- LOGIN ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loginBtn'])) {

    // CHECK THE ENTERED EMAIL AND PASSWORD AGAINST THE USER TABLE.
    $email = trim($_POST['LOGemail']);
    $password = $_POST['LOGpassword'];

    if ($email && $password) {

        $sql = "SELECT * FROM USER_DETAILS WHERE userEMAIL = ? AND userPASSWORD = ?";
        $params = [$email, $password];

        $stmt = db_query($conn, $sql, $params);
        if ($stmt && db_has_rows($stmt)) {
            $row = db_fetch_assoc($stmt);
            // STORE THE MAIN SESSION VALUES USED BY DASHBOARD PAGES AFTER LOGIN.
            $_SESSION['user'] = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            header("Location: dashboard.php");
            exit();
        } else {
            $message = "Invalid email or password.";
        }

    } else {
        $message = "Please enter email and password.";
    }
}
?>
