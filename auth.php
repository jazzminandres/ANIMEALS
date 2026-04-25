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