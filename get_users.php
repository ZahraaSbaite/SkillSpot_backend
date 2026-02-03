<?php
// get_users.php - Returns ALL users (for chat search)
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "capstone";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit();
}

$conn->set_charset("utf8mb4");

try {
    // ✅ ONLY SELECT COLUMNS THAT EXIST IN YOUR TABLE
    // Based on your error, 'location' does NOT exist → remove it!
    $sql = "SELECT id, name, email, phone_number FROM users ORDER BY name ASC";

    // Optional: Exclude current user if email is passed (improves UX)
    if (!empty($_GET['exclude_email'])) {
        $sql = "SELECT id, name, email, phone_number FROM users WHERE email != ? ORDER BY name ASC";
        $stmt = $conn->prepare($sql);
        $excludeEmail = $_GET['exclude_email'];
        $stmt->bind_param("s", $excludeEmail);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    error_log("✅ get_users.php: Returning " . count($users) . " users");

    // Return array directly (your Flutter expects List<Map>)
    echo json_encode($users);
} catch (Exception $e) {
    error_log("❌ get_users.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}

$conn->close();
