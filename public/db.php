<?php
// Database configuration
$host = 'localhost';
$username = 'root';
$password = ''; // Default XAMPP password is empty
$dbname = 'capstone';

// Create MySQLi connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Connection failed: " . $conn->connect_error]));
}

// Set charset to utf8
$conn->set_charset("utf8");
