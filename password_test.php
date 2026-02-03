<?php
// cors.php - Include this at the top of your PHP files
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// Test script to debug password issues
require "db.php";

$email = "zahsb@gmail.com";
$test_password = "12345678"; // Change this to whatever password you're trying

// Get user from database
$stmt = $conn->prepare("SELECT email, password FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    echo "<h3>Password Debug Info:</h3>";
    echo "<p><strong>Email:</strong> " . $user['email'] . "</p>";
    echo "<p><strong>Stored password hash:</strong> " . $user['password'] . "</p>";
    echo "<p><strong>Hash length:</strong> " . strlen($user['password']) . "</p>";
    echo "<p><strong>Test password:</strong> " . $test_password . "</p>";

    // Test password verification
    $is_valid = password_verify($test_password, $user['password']);
    echo "<p><strong>Password verification result:</strong> " . ($is_valid ? "VALID ✅" : "INVALID ❌") . "</p>";

    // Show what the hash should look like
    $proper_hash = password_hash($test_password, PASSWORD_DEFAULT);
    echo "<p><strong>What hash should look like:</strong> " . $proper_hash . "</p>";

    // Check if stored password looks like a proper hash
    $looks_like_hash = (strlen($user['password']) >= 60 && (strpos($user['password'], '$2y$') === 0 || strpos($user['password'], '$2b$') === 0));
    echo "<p><strong>Stored password looks like proper hash:</strong> " . ($looks_like_hash ? "YES ✅" : "NO ❌") . "</p>";
} else {
    echo "<p>User not found!</p>";
}

$conn->close();
?>

<style>
    body {
        font-family: Arial, sans-serif;
        padding: 20px;
    }

    h3 {
        color: #333;
    }

    p {
        margin: 10px 0;
    }
</style>