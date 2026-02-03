<?php
// NO SPACES OR EMPTY LINES BEFORE <?php — THIS IS CRITICAL!

// CORS Headers (MUST come first)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Only POST method allowed"]);
    exit();
}

// Database config
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit();
}

// Extract and sanitize
$name = trim($input['name'] ?? '');
$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$phone = trim($input['phone'] ?? '');

// Validate required fields
if (!$name || !$username || !$email || !$password || !$phone) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Invalid email format"]);
    exit();
}

// Validate Lebanese phone (basic: starts with 3,7,8 and 7-8 digits)
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);
if (substr($cleanPhone, 0, 3) === '961') {
    $cleanPhone = substr($cleanPhone, 3);
} elseif (substr($cleanPhone, 0, 1) === '0') {
    $cleanPhone = substr($cleanPhone, 1);
}
if (!preg_match('/^[378]\d{6,7}$/', $cleanPhone)) {
    echo json_encode(["success" => false, "message" => "Invalid Lebanese phone number"]);
    exit();
}
$formattedPhone = $cleanPhone; // Store clean version

// Check duplicate email or username
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email OR username = :username");
$stmt->execute(['email' => $email, 'username' => $username]);

if ($stmt->rowCount() > 0) {
    echo json_encode(["success" => false, "message" => "Email or username already exists"]);
    exit();
}

// Hash password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Insert user (with 25 coins as in your Flutter app)
$stmt = $pdo->prepare("INSERT INTO users (name, username, email, password, phone_number, coins) VALUES (:name, :username, :email, :password, :phone, 25)");
$result = $stmt->execute([
    ':name' => $name,
    ':username' => $username,
    ':email' => $email,
    ':password' => $hashedPassword,
    ':phone' => $formattedPhone
]);

if ($result) {
    echo json_encode([
        "success" => true,
        "message" => "Registration successful",
        "user_id" => $pdo->lastInsertId()
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Registration failed. Please try again."]);
}

$pdo = null;
