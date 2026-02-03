<?php
// get_user.php - Fetch a single user by email OR id

// === CORS Headers ===
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed. Use GET."]);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "capstone";

// Connect to DB
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}
$conn->set_charset("utf8mb4");

// Check which parameter is provided: 'id' or 'email'
$user_id = $_GET['id'] ?? null;
$email = trim($_GET['email'] ?? '');

// Validate input: must have one of them
if (empty($user_id) && empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Either 'id' or 'email' parameter is required"]);
    exit();
}

// Optional: Log for debugging (safe in dev only)
error_log("=== get_user.php DEBUG ===");
if (!empty($user_id)) {
    error_log("Fetching user with ID: '$user_id'");
} else {
    error_log("Fetching user with email: '$email'");
}

// Prepare query based on input
if (!empty($user_id)) {
    // Validate that ID is numeric
    if (!is_numeric($user_id) || $user_id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid user ID"]);
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if (!$stmt) {
        error_log("MySQL prepare error (by ID): " . $conn->error);
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error"]);
        $conn->close();
        exit();
    }
    $int_id = (int)$user_id;
    $stmt->bind_param("i", $int_id);
} else {
    // Fetch by email (original logic)
    $stmt = $conn->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(?)");
    if (!$stmt) {
        error_log("MySQL prepare error (by email): " . $conn->error);
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error"]);
        $conn->close();
        exit();
    }
    $stmt->bind_param("s", $email);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo json_encode([
        "success" => true,
        "message" => "User found",
        "user" => $user
    ]);
} else {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "User not found"
    ]);
}

$stmt->close();
$conn->close();
