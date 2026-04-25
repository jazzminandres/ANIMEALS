<?php
session_start();

$client_id     = '504614731935-i4si0to01aok3qqltssrmdb8p4k2ttva.apps.googleusercontent.com';
$client_secret = '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';
$redirect_uri  = 'http://localhost/SOFTWARE/oauth2callback.php';

$source = isset($_GET['source']) ? $_GET['source'] : 'login';

$auth_url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'     => $client_id,
    'redirect_uri'  => $redirect_uri,
    'response_type' => 'code',
    'scope'         => 'email profile',
    'state'         => $source
]);

header('Location: ' . $auth_url);
exit;
?>