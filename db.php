<?php
/**
 * MySQL database connection (mysqli).
 * For classroom use only: move credentials to environment variables in production.
 */
function connectDB(): mysqli
{
    $host = 'localhost';
    $dbname = 'secure';
    $username = 'secure';
    $password = '1111';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($host, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');

    return $conn;
}

    $users = "users1";
    // db.inc.php
    // $sql = "select * from $users";
?>