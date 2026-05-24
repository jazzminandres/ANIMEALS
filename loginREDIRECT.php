<?php
// THIS FILE SENDS LOGGED-IN USERS TO THE RIGHT DASHBOARD BASED ON THEIR ACCOUNT ROLE.
require_once __DIR__ . '/session_config.php';
session_start();

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
