<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+02:00'");
} catch (mysqli_sql_exception $e) {
    error_log('NEXSUS database connection: ' . $e->getMessage());
    http_response_code(503);
    exit('Database unavailable. Start MySQL in XAMPP and check config.php.');
}
