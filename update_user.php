<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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
    echo json_encode(["success" => false, "message" => "DB connection failed: " . $conn->connect_error]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

error_log("Update user request: " . json_encode($data));

$id = $data['id'] ?? null;
$email = $data['email'] ?? null;
$name = $data['name'] ?? null;
$phoneNumber = $data['phone_number'] ?? null;
$location = $data['location'] ?? null;
$description = $data['description'] ?? null;

// Allow lookup by either id or email
if (empty($id) && empty($email)) {
    echo json_encode(["success" => false, "message" => "ID or email is required"]);
    exit;
}

// Find user by id or email
if (!empty($id)) {
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $checkStmt->bind_param("i", $id);
} else {
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $checkStmt->bind_param("s", $email);
}

$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

// Get the user ID if we looked up by email
if (empty($id)) {
    $row = $result->fetch_assoc();
    $id = $row['id'];
}
$checkStmt->close();

// Build dynamic update query
$updates = [];
$types = "";
$params = [];

if ($name !== null) {
    $updates[] = "name = ?";
    $types .= "s";
    $params[] = $name;
}
if ($email !== null && !isset($data['update_description_only'])) {
    $updates[] = "email = ?";
    $types .= "s";
    $params[] = $email;
}
if ($phoneNumber !== null) {
    $updates[] = "phone_number = ?";
    $types .= "s";
    $params[] = $phoneNumber;
}
if ($location !== null) {
    $updates[] = "location = ?";
    $types .= "s";
    $params[] = $location;
}
if ($description !== null) {
    $updates[] = "description = ?";
    $types .= "s";
    $params[] = $description;
}

if (empty($updates)) {
    echo json_encode(["success" => false, "message" => "No fields to update"]);
    exit;
}

// Add id to params
$types .= "i";
$params[] = $id;

$sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";

error_log("SQL: $sql");
error_log("Params: " . json_encode($params));

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "User updated successfully"]);
} else {
    error_log("SQL Error: " . $stmt->error);
    echo json_encode(["success" => false, "message" => "Failed to update user: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>