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

    $source = isset($_GET['state']) ? $_GET['state'] : 'login';

    /* ---------- TOKEN REQUEST ---------- */
    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token'); 
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => $_GET['code'], 
        'client_id'     => $client_id, 
        'client_secret' => $client_secret, 
        'redirect_uri'  => $redirect_uri, 
        'grant_type'    => 'authorization_code' 
    ])); 
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
    $stmt  = sqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$email]);
    $row   = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $exists = ($row !== null && $row !== false);

    /* ---------- HELPER: check userTYPE and redirect accordingly ---------- */
        function redirectByType($row) {
            $role = isset($row['userROLE']) ? trim($row['userROLE']) : null;

            if ($role === 'admin') {
                header('Location: admin.php');
            } elseif ($role === 'seller') {
                header('Location: seller.php');
            } elseif ($role === 'student') {
                header('Location: student.php');
            } else {
                // No role yet — send to profile setup to complete registration
                header('Location: profileSetup.php');
            }
            exit;
        }

    /* ---------- LOGIN FLOW ---------- */
    if ($source === 'login') {
        if ($exists) {
            $_SESSION['user']  = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            redirectByType($row);
        } else {
            header('Location: index.php?error=not_registered');
            exit;
        }
    }

    /* ---------- SIGNUP FLOW ---------- */
    if ($source === 'signup') {
        if ($exists) {
            // Already registered — log in and check type
            $_SESSION['user']  = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            redirectByType($row);
        // NEW
        } else {
            // New user — store Google info in session, send to profileSetup
            $_SESSION['google_name']    = $name;
            $_SESSION['google_email']   = $email;
            $_SESSION['google_picture'] = $user['picture'] ?? '';
            header('Location: profileSetup.php?from=google');
            exit;
        }
    }

} else { 
    echo "Error: No authorization code received."; 
}
?>