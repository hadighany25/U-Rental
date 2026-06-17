<?php
$host = "localhost:3307";
$db_user = "root";
$db_pass = "";
$db_name = "rental_db";

$conn = new mysqli("localhost", "root", "", "", 3307);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->query("CREATE DATABASE IF NOT EXISTS $db_name");
$conn->select_db($db_name);

// អានឯកសារ .env
$env = parse_ini_file('.env');

define('TELEGRAM_TOKEN', $env['TELEGRAM_TOKEN']);
define('TELEGRAM_CHAT_ID', $env['TELEGRAM_CHAT_ID']);

function sendTelegramMessage($message) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&text=" . urlencode($message);
    @file_get_contents($url);
}

// ១. បង្កើតតារាង ប្រភេទបន្ទប់ (Room Types)
$conn->query("CREATE TABLE IF NOT EXISTS room_types (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    type_name VARCHAR(100) NOT NULL UNIQUE
)");

// ២. បង្កើតតារាង បន្ទប់ (Rooms)
$conn->query("CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    room_number VARCHAR(10) NOT NULL UNIQUE, 
    type_id INT NULL, 
    price DECIMAL(10,2) NOT NULL, 
    status ENUM('available', 'occupied') DEFAULT 'available', 
    description TEXT,
    FOREIGN KEY (type_id) REFERENCES room_types(id) ON DELETE SET NULL
)");

// ៣. បង្កើតតារាង អ្នកប្រើប្រាស់ (Users)
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    username VARCHAR(50) NOT NULL UNIQUE, 
    password VARCHAR(255) NOT NULL, 
    fullname VARCHAR(100) NOT NULL, 
    phone VARCHAR(20) NOT NULL UNIQUE, 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ៤. បង្កើតតារាង ការកក់ (Bookings)
$conn->query("CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    room_id INT NOT NULL, 
    tenant_name VARCHAR(100) NOT NULL, 
    tenant_phone VARCHAR(20) NOT NULL, 
    user_id INT NULL, 
    check_in_time DATETIME NOT NULL, 
    check_out_time DATETIME NOT NULL, 
    status ENUM('booked', 'checked_in', 'checked_out') DEFAULT 'booked', 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE, 
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)");
?>