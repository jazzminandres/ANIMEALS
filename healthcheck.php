<?php
// THIS FILE CHECKS WHETHER PHP AND THE DATABASE CONNECTION ARE WORKING ON THE SERVER.
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "ANIMEALS health check\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "mysqli: " . (extension_loaded('mysqli') ? 'loaded' : 'missing') . "\n";
echo "curl: " . (extension_loaded('curl') ? 'loaded' : 'missing') . "\n";
echo "session path: " . session_save_path() . "\n\n";

$databases = [
    DB_NAME_ANIMEALS => ['user_details', 'orders', 'cart'],
    DB_NAME_SELLER_DATA => ['user_details', 'seller_shops', 'menu_items'],
    DB_NAME_ANIMEALS_POSTS => ['posts', 'comments', 'post_likes', 'comment_likes'],
];

foreach ($databases as $database => $tables) {
    echo "Database {$database}: ";
    $conn = @db_connect($database);
    if (!$conn instanceof mysqli || $conn->connect_error) {
        echo "FAILED\n";
        continue;
    }
    echo "connected\n";

    foreach ($tables as $table) {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        echo "  {$table}: " . ($result && $result->num_rows > 0 ? 'ok' : 'missing') . "\n";
    }
}
