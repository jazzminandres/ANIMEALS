<?php
// THIS FILE HANDLES THE GOOGLE OAUTH CALLBACK AND FINISHES GOOGLE SIGN-IN.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php'; 

// Use centralized DB helper
$conn = db_connect(DB_NAME_ANIMEALS);
if ($conn === false) {
    die('Database connection failed.');
}

$client_id     = '504614731935-i4si0to01aok3qqltssrmdb8p4k2ttva.apps.googleusercontent.com'; 
$client_secret = '.'; 

function app_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (preg_replace('/:\d+$/', '', $host) === 'animeals.online') {
        return 'http://animeals.online/' . ltrim($path, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath) . '/' . ltrim($path, '/');
}

$redirect_uri  = app_url('oauth2callback.php'); 

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
    $stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$email]);
    $row = $stmt ? db_fetch_assoc($stmt) : null;
    $exists = !empty($row);

    /* ---------- HELPER: check userTYPE and redirect accordingly ---------- */
        function redirectByType($row) {
            $role = isset($row['userROLE']) ? strtolower(trim((string) $row['userROLE'])) : '';

            if ($role === 'admin') {
                header('Location: admin.php');
            } elseif ($role === 'seller') {
                header('Location: seller.php');
            } else {
                // Default to student for existing accounts that do not have an explicit role.
                header('Location: student.php');
            }
            exit;
        }

    /* ---------- LOGIN FLOW ---------- */
    if ($source === 'login') {
        if ($exists) {
            $_SESSION['user']  = $row['userNAME'];
            $_SESSION['email'] = $row['userEMAIL'];
            $_SESSION['role']  = $row['userROLE'] ?? 'student';
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
            $_SESSION['role']  = $row['userROLE'] ?? 'student';
            redirectByType($row);
        // NEW
        } else {
            // New user — store Google info in session and send to profile setup
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
