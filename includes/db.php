<?php
/**
 * PDO database singleton (MySQL / MariaDB).
 */

/**
 * Returns the shared PDO connection.
 */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('Database connection failed: ' . htmlspecialchars($e->getMessage())
                    . '<br><br>Check the values in <b>config.php</b>. If the database has not been '
                    . 'created yet, open <a href="install/install.php">install/install.php</a> in your browser.');
            }
            die('Service temporarily unavailable. Please try again later.');
        }
    }
    return $pdo;
}

/**
 * Prepare + execute a query and return the statement.
 */
function q($sql, $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch a single row (or null).
 */
function q_row($sql, $params = [])
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * Fetch a single scalar value.
 */
function q_val($sql, $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/**
 * Fetch all rows.
 */
function q_all($sql, $params = [])
{
    return q($sql, $params)->fetchAll();
}

/**
 * Fetch all rows as id => column map.
 */
function q_map($sql, $params = [], $col = 'name')
{
    $out = [];
    foreach (q_all($sql, $params) as $r) {
        $out[$r['id']] = $r[$col];
    }
    return $out;
}
