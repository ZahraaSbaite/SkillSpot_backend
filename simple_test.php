<?php
// CORS headers - must be first
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

echo json_encode([
    "success" => true,
    "message" => "Server is working!",
    "timestamp" => date('Y-m-d H:i:s'),
    "method" => $_SERVER['REQUEST_METHOD'],
    "get_params" => $_GET
]);
