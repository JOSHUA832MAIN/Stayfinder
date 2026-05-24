<?php
// Load .env file manually (since Hostinger shared hosting 
// doesn't auto-load .env)
$envFile = dirname(__DIR__, 2) . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Use .env values
$server   = $_ENV['DB_HOST']     ?? 'localhost';
$username = $_ENV['DB_USERNAME'] ?? '';
$password = $_ENV['DB_PASSWORD'] ?? '';
$database = $_ENV['DB_DATABASE'] ?? '';

// Create connection
$conn = new mysqli($server, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed");
}

$conn->set_charset("utf8");

function closeConnection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}
?>