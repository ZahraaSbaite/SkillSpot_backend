<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
// cors.php - Include this at the top of your PHP filesss
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Check required fields (name, email, password are required)
    if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields: name, email, password']);
        exit;
    }

    $name = $data['name'];
    $email = $data['email'];
    $password = $data['password'];
    $phone_number = $data['phone_number'] ?? null;
    $location = $data['location'] ?? null;

    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        $checkStmt->close();
        $conn->close();
        exit;
    }
    $checkStmt->close();

    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, phone_number, location) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $name, $email, $hashedPassword, $phone_number, $location);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'User created successfully',
            'id' => $conn->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
