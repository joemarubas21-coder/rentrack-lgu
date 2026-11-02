<?php
require_once __DIR__ . '/env.php';

// Credentials come ONLY from environment (.env file or server env vars). Never hardcode them.
$host     = env_value('DB_HOST');
$port     = env_value('DB_PORT', '5432');
$database = env_value('DB_NAME', 'postgres');
$user     = env_value('DB_USER');
$password = env_value('DB_PASSWORD');

if (!$host || !$user || $password === null) {
    error_log('Database configuration missing. Set DB_HOST, DB_USER, DB_PASSWORD in .env');
    http_response_code(500);
    die('Server configuration error.');
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";
    $conn = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Please try again later.');
}
