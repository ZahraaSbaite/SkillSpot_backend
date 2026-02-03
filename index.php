<?php
// htdocs/capstone/api/index.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$request = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Remove base path
$request = str_replace('/capstone/api', '', $request);
$request = preg_replace('/\?.*/', '', $request); // Remove query string

// Route mapping
$routes = [
    'POST' => [
        '/auth/login' => 'login.php',
        '/auth/register' => 'register.php',
        '/users/update_coins' => 'update_coins.php',
        '/transactions/send' => 'get_transaction.php',
        '/messages/send' => 'chat.php',
    ],
    'GET' => [
        '/users/profile' => 'get_profile.php',
        '/users/coins' => 'get_user_coins.php',
        '/transactions' => 'get_transactions.php',
        '/messages' => 'get_messages.php',
        '/messages/with/' => 'get_messages_with_user.php',
    ]
];

// Find matching route
foreach ($routes[$method] ?? [] as $route => $file) {
    if (strpos($request, $route) === 0) {
        // Pass query parameters and POST data
        $_SERVER['ROUTE_PARAMS'] = substr($request, strlen($route));
        require_once __DIR__ . '/' . $file;
        exit(0);
    }
}

// No route found
http_response_code(404);
echo json_encode(['error' => 'Route not found', 'requested' => $request]);
