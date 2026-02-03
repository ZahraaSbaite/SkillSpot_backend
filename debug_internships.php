<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Debug: Log what we're receiving
$debug = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'get_params' => $_GET,
    'action' => $_GET['action'] ?? 'NOT SET',
    'user_id' => $_GET['user_id'] ?? 'NOT SET',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'NOT SET',
];

echo json_encode($debug);
?>