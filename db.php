<?php
// THIS FILE CREATES MYSQL CONNECTIONS AND WRAPS COMMON DATABASE QUERIES FOR THE WHOLE WEBSITE.

// THIS KEEPS MYSQLI FROM THROWING RAW EXCEPTIONS SO EACH PAGE CAN SHOW A CLEANER ERROR.
mysqli_report(MYSQLI_REPORT_OFF);
$MYSQLSRV_LAST_ERROR = 'No MySQL error has been recorded.';

// THESE ARE THE DEFAULT MYSQL SETTINGS USED WHEN NO ENVIRONMENT VARIABLES ARE SET.
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME_ANIMEALS = 'animeals';
const DB_NAME_SELLER_DATA = 'seller_data';
const DB_NAME_ANIMEALS_POSTS = 'animeals_posts';

function db_connect(string $database = DB_NAME_ANIMEALS): mysqli
{
    // TRY ENVIRONMENT CREDENTIALS FIRST, THEN COMMON LOCAL/DEPLOYMENT FALLBACKS.
    $host = getenv('DB_HOST') ?: DB_HOST;
    $candidates = [
        [getenv('DB_USER') ?: DB_USER, getenv('DB_PASS') === false ? DB_PASS : getenv('DB_PASS')],
        ['root', ''],
        ['esoul', 'Animeals1.Animeals'],
    ];

    $lastError = '';
    foreach ($candidates as [$user, $pass]) {
        $conn = @new mysqli($host, $user, $pass, $database);
        if (!$conn->connect_error) {
            $conn->set_charset('utf8mb4');
            return $conn;
        }
        $lastError = $conn->connect_error;
    }

    http_response_code(500);
    die('Database connection failed. Check MySQL credentials and database name. ' . $lastError);
}

function db_query(mysqli $connection, string $sql, array $params = []): mysqli_stmt|false
{
    // PREPARE EVERY QUERY SO USER INPUT IS BOUND SAFELY INSTEAD OF BEING STRING-CONCATENATED.
    $stmt = $connection->prepare($sql);
    if ($stmt === false) {
        $GLOBALS['MYSQLSRV_LAST_ERROR'] = 'Prepare failed: ' . $connection->error . ' | SQL: ' . $sql;
        return false;
    }

    if ($params !== []) {
        // MYSQLI NEEDS A TYPE STRING, SO THIS BUILDS IT FROM THE PHP PARAMETER TYPES.
        $types = '';
        $refs = [];
        foreach ($params as $key => $param) {
            $type = gettype($param);
            $types .= match ($type) {
                'integer' => 'i',
                'double' => 'd',
                'boolean' => 'i',
                default => 's',
            };
            $refs[$key] = &$params[$key];
        }
        $stmt->bind_param($types, ...$refs);
    }

    if (!$stmt->execute()) {
        $GLOBALS['MYSQLSRV_LAST_ERROR'] = 'Execute failed: ' . $stmt->error . ' | SQL: ' . $sql;
        return false;
    }

    return $stmt;
}

function db_fetch_assoc(mysqli_stmt $stmt): ?array
{
    // RETURN ONE ROW AS AN ASSOCIATIVE ARRAY, OR NULL WHEN THE QUERY HAS NO RESULT SET.
    $result = $stmt->get_result();
    if ($result === false) {
        return null;
    }
    return $result->fetch_assoc() ?: null;
}

function db_fetch_all(mysqli_stmt $stmt): array
{
    // RETURN EVERY ROW AS ASSOCIATIVE ARRAYS SO CALLERS DO NOT NEED TO LOOP MYSQLI RESULTS.
    $result = $stmt->get_result();
    if ($result === false) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function db_has_rows(mysqli_stmt|false $stmt): bool
{
    // SMALL HELPER FOR OLD CODE THAT ONLY NEEDS TO KNOW IF A QUERY FOUND ANYTHING.
    if ($stmt === false) {
        return false;
    }
    $result = $stmt->get_result();
    return $result !== false && $result->num_rows > 0;
}

function db_last_error(mysqli $connection): string
{
    // EXPOSE THE LAST MYSQL ERROR FOR DEBUGGING WITHOUT REACHING INTO THE CONNECTION EVERYWHERE.
    return $connection->error;
}

function db_escape_string(mysqli $connection, string $value): string
{
    // LEGACY ESCAPE HELPER FOR PLACES THAT STILL NEED MYSQL STRING ESCAPING.
    return $connection->real_escape_string($value);
}

/*
 * THIS COMPATIBILITY LAYER LETS OLDER SQLSRV-STYLE PAGES KEEP WORKING WHILE THE
 * APP RUNS ON MYSQLI. IT TRANSLATES COMMON SQL SERVER PATTERNS INTO MYSQL.
 */
if (!defined('SQLSRV_FETCH_ASSOC')) {
    define('SQLSRV_FETCH_ASSOC', MYSQLI_ASSOC);
}

if (!class_exists('SqlsrvCompatStatement')) {
    final class SqlsrvCompatStatement
    {
        public ?mysqli_result $result = null;

        public function __construct(
            public mysqli_stmt $stmt,
            public mysqli $connection
        ) {
        }
    }
}

if (!function_exists('mysqlsrv_connect')) {
    function mysqlsrv_connect(string $serverName, array $connectionOptions = []): mysqli|false
    {
        // MAP OLD DATABASE NAMES TO THE MYSQL DATABASES USED BY THE LIVE APP.
        $database = $connectionOptions['Database'] ?? DB_NAME_ANIMEALS;
        $database = strtolower((string) $database);
        $database = match ($database) {
            'animeals', 'animeals.dbo' => DB_NAME_ANIMEALS,
            'seller_data', 'seller_data.dbo' => DB_NAME_SELLER_DATA,
            'animeals_posts', 'animeals_posts.dbo' => DB_NAME_ANIMEALS_POSTS,
            default => $database,
        };

        $host = getenv('DB_HOST') ?: DB_HOST;
        $candidates = [
            [getenv('DB_USER') ?: DB_USER, getenv('DB_PASS') === false ? DB_PASS : getenv('DB_PASS')],
            ['root', ''],
            ['esoul', 'Animeals1.Animeals'],
        ];

        foreach ($candidates as [$user, $pass]) {
            $conn = @new mysqli($host, $user, $pass, $database);
            if (!$conn->connect_error) {
                $conn->set_charset('utf8mb4');
                return $conn;
            }
            $GLOBALS['MYSQLSRV_LAST_ERROR'] = 'Connection failed: ' . $conn->connect_error . ' | Database: ' . $database;
        }

        return false;
    }

    function mysqlsrv_translate_query(string $sql): string
    {
        // CONVERT SELECT TOP INTO MYSQL LIMIT WHILE PRESERVING THE ORIGINAL QUERY BODY.
        $limit = null;
        $sql = preg_replace_callback('/^\s*SELECT\s+TOP\s+(\d+)\s+/i', static function (array $m) use (&$limit): string {
            $limit = (int) $m[1];
            return 'SELECT ';
        }, $sql, 1);

        $replacements = [
            // TRANSLATE COMMON SQL SERVER FUNCTIONS, PREFIXES, AND BRACKETED IDENTIFIERS TO MYSQL.
            '/\bSYSDATETIME\s*\(\s*\)/i' => 'CURRENT_TIMESTAMP',
            '/\bGETDATE\s*\(\s*\)/i' => 'CURRENT_DATE',
            '/\bDATEADD\s*\(\s*day\s*,\s*(-?\d+)\s*,\s*CAST\s*\(\s*CURRENT_DATE\s+AS\s+DATE\s*\)\s*\)/i' => 'DATE_ADD(CURRENT_DATE, INTERVAL $1 DAY)',
            '/\bDATEADD\s*\(\s*day\s*,\s*(-?\d+)\s*,\s*CAST\s*\(\s*GETDATE\s*\(\s*\)\s+AS\s+DATE\s*\)\s*\)/i' => 'DATE_ADD(CURRENT_DATE, INTERVAL $1 DAY)',
            '/\bISNULL\s*\(/i' => 'COALESCE(',
            '/\bLTRIM\s*\(\s*RTRIM\s*\((.*?)\)\s*\)/is' => 'TRIM($1)',
            '/\bANIMEALS_POSTS\.dbo\./i' => 'animeals_posts.',
            '/\bSELLER_DATA\.dbo\./i' => 'seller_data.',
            '/\bANIMEALS\.dbo\./i' => 'animeals.',
            '/\bdbo\./i' => '',
            '/\bCAST\s*\(\s*N\'([^\']*)\'\s+AS\s+NVARCHAR\s*\(\s*\d+\s*\)\s*\)/i' => "'$1'",
            '/\bN\'([^\']*)\'/i' => "'$1'",
            '/\[([A-Za-z0-9_]+)\]/' => '`$1`',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $sql = preg_replace($pattern, $replacement, $sql);
        }

        $tableNames = [
            // NORMALIZE TABLE NAMES BECAUSE OLD PAGES MIX UPPERCASE SQL SERVER NAMES WITH MYSQL TABLES.
            'USER_DETAILS' => 'user_details',
            'CART' => 'cart',
            'ORDERS' => 'orders',
            'ORDER_ITEMS' => 'order_items',
            'SHOP_REVIEWS' => 'shop_reviews',
            'SIGNUP_PENDING' => 'signup_pending',
            'SELLER_SHOPS' => 'seller_shops',
            'MENU_ITEMS' => 'menu_items',
            'REVIEWS' => 'reviews',
            'POSTS' => 'posts',
            'COMMENTS' => 'comments',
            'POST_LIKES' => 'post_likes',
            'COMMENT_LIKES' => 'comment_likes',
        ];
        foreach ($tableNames as $from => $to) {
            $sql = preg_replace('/\b' . preg_quote($from, '/') . '\b/i', $to, $sql);
        }

        if ($limit !== null && !preg_match('/\bLIMIT\s+\d+\s*$/i', $sql)) {
            $sql = rtrim($sql, " \t\n\r\0\x0B;") . ' LIMIT ' . $limit;
        }

        return $sql;
    }

    function mysqlsrv_query(mysqli $connection, string $sql, array $params = []): SqlsrvCompatStatement|false
    {
        // RUN AN OLD SQLSRV QUERY THROUGH THE TRANSLATOR, THEN EXECUTE IT AS A MYSQL PREPARED STATEMENT.
        $translatedSql = mysqlsrv_translate_query($sql);
        $stmt = $connection->prepare($translatedSql);
        if ($stmt === false) {
            $GLOBALS['MYSQLSRV_LAST_ERROR'] = 'Prepare failed: ' . $connection->error . ' | SQL: ' . $translatedSql;
            return false;
        }

        if ($params !== []) {
            $types = '';
            $refs = [];
            foreach ($params as $key => $param) {
                $types .= match (gettype($param)) {
                    'integer' => 'i',
                    'double' => 'd',
                    'boolean' => 'i',
                    default => 's',
                };
                $refs[$key] = &$params[$key];
            }
            $stmt->bind_param($types, ...$refs);
        }

        if (!$stmt->execute()) {
            $GLOBALS['MYSQLSRV_LAST_ERROR'] = 'Execute failed: ' . $stmt->error . ' | SQL: ' . $translatedSql;
            return false;
        }

        return new SqlsrvCompatStatement($stmt, $connection);
    }

    function mysqlsrv_fetch_array(SqlsrvCompatStatement|false $stmt, int $fetchType = SQLSRV_FETCH_ASSOC): array|null
    {
        // FETCH ONE TRANSLATED MYSQL ROW USING THE SAME SHAPE THE OLD SQLSRV CODE EXPECTED.
        if ($stmt === false) {
            return null;
        }
        if ($stmt->result === null) {
            $stmt->result = $stmt->stmt->get_result();
        }
        if ($stmt->result === false) {
            return null;
        }
        return $stmt->result->fetch_assoc() ?: null;
    }

    function mysqlsrv_has_rows(SqlsrvCompatStatement|false $stmt): bool
    {
        // LET OLD PAGES CHECK FOR RESULTS WITHOUT KNOWING THE STATEMENT IS REALLY MYSQLI.
        if ($stmt === false) {
            return false;
        }
        if ($stmt->result === null) {
            $stmt->result = $stmt->stmt->get_result();
        }
        return $stmt->result !== false && $stmt->result->num_rows > 0;
    }

    function mysqlsrv_errors(): array
    {
        // RETURN ERRORS IN THE ARRAY FORMAT THE OLD SQLSRV CALL SITES ALREADY DISPLAY.
        return [['message' => $GLOBALS['MYSQLSRV_LAST_ERROR'] ?? 'MySQL query failed.']];
    }

    function mysqlsrv_begin_transaction(mysqli $connection): bool
    {
        // PASS TRANSACTION STARTS THROUGH TO MYSQLI.
        return $connection->begin_transaction();
    }

    function mysqlsrv_commit(mysqli $connection): bool
    {
        // COMMIT MYSQL TRANSACTIONS FOR CODE THAT STILL CALLS SQLSRV HELPERS.
        return $connection->commit();
    }

    function mysqlsrv_rollback(mysqli $connection): bool
    {
        // ROLL BACK MYSQL TRANSACTIONS WHEN AN OLD SQLSRV-STYLE FLOW FAILS.
        return $connection->rollback();
    }

    function mysqlsrv_close(mysqli $connection): bool
    {
        // CLOSE THE MYSQL CONNECTION BUT KEEP THE OLD FUNCTION NAME AVAILABLE.
        $connection->close();
        return true;
    }

    function mysqlsrv_rows_affected(SqlsrvCompatStatement|false $stmt): int|false
    {
        // REPORT AFFECTED ROWS FROM THE WRAPPED MYSQLI STATEMENT.
        return $stmt === false ? false : $stmt->stmt->affected_rows;
    }
}

if (!function_exists('sqlsrv_connect')) {
    // THESE ALIASES SUPPORT PAGES THAT USE SQLSRV_* INSTEAD OF MYSQLSRV_*.
    function sqlsrv_connect(string $serverName, array $connectionOptions = []): mysqli|false
    {
        return mysqlsrv_connect($serverName, $connectionOptions);
    }

    function sqlsrv_query(mysqli $connection, string $sql, array $params = []): SqlsrvCompatStatement|false
    {
        return mysqlsrv_query($connection, $sql, $params);
    }

    function sqlsrv_fetch_array(SqlsrvCompatStatement|false $stmt, int $fetchType = SQLSRV_FETCH_ASSOC): array|null
    {
        return mysqlsrv_fetch_array($stmt, $fetchType);
    }

    function sqlsrv_has_rows(SqlsrvCompatStatement|false $stmt): bool
    {
        return mysqlsrv_has_rows($stmt);
    }

    function sqlsrv_errors(): array
    {
        return mysqlsrv_errors();
    }

    function sqlsrv_begin_transaction(mysqli $connection): bool
    {
        return mysqlsrv_begin_transaction($connection);
    }

    function sqlsrv_commit(mysqli $connection): bool
    {
        return mysqlsrv_commit($connection);
    }

    function sqlsrv_rollback(mysqli $connection): bool
    {
        return mysqlsrv_rollback($connection);
    }

    function sqlsrv_close(mysqli $connection): bool
    {
        return mysqlsrv_close($connection);
    }

    function sqlsrv_rows_affected(SqlsrvCompatStatement|false $stmt): int|false
    {
        return mysqlsrv_rows_affected($stmt);
    }
}
