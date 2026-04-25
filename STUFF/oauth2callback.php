<?php 
session_start(); 

$serverName = "SatanaelLG\\MSSQLSERVER01";
$connectionOptions = [
    "Database" => "ANIMEALS",
    "Uid" => "",
    "PWD" => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) { 
    die(print_r(sqlsrv_errors(), true));
}

$client_id     = '504614731935-i4si0to01aok3qqltssrmdb8p4k2ttva.apps.googleusercontent.com'; 
$client_secret = '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'; 
$redirect_uri  = 'http://localhost/SOFTWARE/oauth2callback.php'; 

if (isset($_GET['code'])) { 

    // Retrieve which button was clicked (login or signup)
    $source = isset($_GET['state']) ? $_GET['state'] : 'login';

    /* ---------- TOKEN REQUEST ---------- */
    $token_url   = 'https://oauth2.googleapis.com/token'; 
    $post_fields = [ 
        'code'          => $_GET['code'], 
        'client_id'     => $client_id, 
        'client_secret' => $client_secret, 
        'redirect_uri'  => $redirect_uri, 
        'grant_type'    => 'authorization_code' 
    ]; 

    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, $token_url); 
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields)); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 

    $response = curl_exec($ch); 
    if (curl_errno($ch)) { die("Curl error (token): " . curl_error($ch)); } 
    curl_close($ch); 

    $token_data = json_decode($response, true); 
    if (!isset($token_data['access_token'])) { 
        die("No access token received:<br><pre>$response</pre>"); 
    } 

    /* ---------- GET GOOGLE USER INFO ---------- */
    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json'); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token_data['access_token']]); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 

    $user_info = curl_exec($ch); 
    if (curl_errno($ch)) { die("Curl error (user info): " . curl_error($ch)); } 
    curl_close($ch); 

    $user  = json_decode($user_info, true);
    $email = $user['email'];
    $name  = $user['name'];

    /* ---------- CHECK IF USER EXISTS IN DB ---------- */
    $stmt = sqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$email]);
    $exists = $stmt && sqlsrv_has_rows($stmt);

    /* ---------- LOGIN FLOW ---------- */
    if ($source === 'login') {

        if ($exists) {
            // Email found in DB — log them in
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $_SESSION['user']  = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            header('Location: dashboard.php');
            exit;
        } else {
            // Google account not registered — send back with error
            header('Location: index.php?error=not_registered');
            exit;
        }
    }

    /* ---------- SIGNUP FLOW ---------- */
    if ($source === 'signup') {

        if ($exists) {
            // Already has an account — just log them in
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $_SESSION['user']  = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            header('Location: dashboard.php');
            exit;
        } else {
            // New user — create account
            $randomPassword  = bin2hex(random_bytes(5));
            $insert_stmt = sqlsrv_query($conn,
                "INSERT INTO USER_DETAILS (userNAME, userPASSWORD, userEMAIL) VALUES (?, ?, ?)",
                [$name, $randomPassword, $email]
            );

            if ($insert_stmt) {
                $_SESSION['user']  = $name;
                $_SESSION['email'] = $email;
                header('Location: dashboard.php');
                exit;
            } else {
                die("Database insert error.");
            }
        }
    }

} else { 
    echo "Error: No authorization code received."; 
}
?>