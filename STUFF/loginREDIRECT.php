<?php 
session_start(); 

$client_id = '504614731935-han96tn2qs67iu7730s0far5amk41ha8.apps.googleusercontent.com'; 

$redirect_uri = 'http://localhost/oauth-lab/oauth2callback.php';

// Combine scopes properly
$scope = urlencode('https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email');

$auth_url = "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&client_id=$client_id&redirect_uri=$redirect_uri&scope=$scope&access_type=offline"; 

header('Location: ' . $auth_url); 
exit;
?>