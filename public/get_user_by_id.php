<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'capstone';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "message" => "DB connection failed: " . $conn->connect_error
    ]);
    exit;
}

$conn->set_charset("utf8");

// Get user_id from query parameter
$user_id = $_GET['user_id'] ?? null;

if (empty($user_id)) {
    echo json_encode([
        "success" => false,
        "message" => "user_id parameter is required"
    ]);
    exit;
}

// First, get all available columns from the users table
$columnsQuery = $conn->query("SHOW COLUMNS FROM users");
$availableColumns = [];
while ($col = $columnsQuery->fetch_assoc()) {
    $availableColumns[] = $col['Field'];
}

// Build SELECT query with only available columns
$selectColumns = ['id', 'name', 'email', 'phone_number', 'description', 'coins', 'created_at'];
$columnsToSelect = array_intersect($selectColumns, $availableColumns);

// Add optional columns if they exist
if (in_array('username', $availableColumns)) {
    $columnsToSelect[] = 'username';
}
if (in_array('location', $availableColumns)) {
    $columnsToSelect[] = 'location';
}

$columnsList = implode(', ', array_unique($columnsToSelect));

// Prepare query to get user by ID
$stmt = $conn->prepare("SELECT $columnsList FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "User not found with ID: $user_id"
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

$user = $result->fetch_assoc();

// Build response with available fields
$userResponse = [
    "id" => (int)$user['id'],
    "name" => $user['name'],
    "email" => $user['email'],
    "phone_number" => $user['phone_number'] ?? null,
    "description" => $user['description'] ?? null,
    "coins" => (int)($user['coins'] ?? 0),
    "created_at" => $user['created_at'] ?? null
];

// Add optional fields if they exist
if (isset($user['username'])) {
    $userResponse['username'] = $user['username'];
}
if (isset($user['location'])) {
    $userResponse['location'] = $user['location'];
}

// Return user data with all fields including description
echo json_encode([
    "success" => true,
    "message" => "User found",
    "user" => $userResponse
], JSON_UNESCAPED_UNICODE);

$stmt->close();
$conn->close();
