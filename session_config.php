<?php
// THIS FILE CONFIGURES PHP SESSIONS SO LOGIN STATE WORKS CONSISTENTLY ON LOCALHOST AND HOSTING.

if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0775, true);
    }
    if (is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
}
