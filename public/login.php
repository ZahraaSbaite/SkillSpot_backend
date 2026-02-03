<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
// cors.php - Include this at the top of your PHP files
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Get JSON input from Flutter
    $data = json_decode(file_get_contents('php://input'), true);

    $email = $data["email"] ?? '';
    $password = $data["password"] ?? '';

    // Log for debugging
    error_log("=== LOGIN ATTEMPT ===");
    error_log("Email: " . $email);
    error_log("Password length: " . strlen($password));

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            error_log("User found in database");
            error_log("User ID: " . $user["id"]);
            error_log("User ID type: " . gettype($user["id"]));
            error_log("User email: " . $user["email"]);
            error_log("Stored password: " . $user["password"]);
            error_log("Password length: " . strlen($user["password"]));

            // Check if password is hashed (starts with $2y$ or $2b$ and is long enough)
            $is_hashed = (strlen($user["password"]) >= 60 &&
                (strpos($user["password"], '$2y$') === 0 ||
                    strpos($user["password"], '$2b$') === 0));

            $password_valid = false;

            if ($is_hashed) {
                // Use password_verify for hashed passwords
                $password_valid = password_verify($password, $user["password"]);
                error_log("Using password_verify - Result: " . ($password_valid ? "true" : "false"));
            } else {
                // Direct comparison for plain text (TEMPORARY - remove in production)
                $password_valid = ($password === $user["password"]);
                error_log("Using direct comparison - Result: " . ($password_valid ? "true" : "false"));

                // Auto-hash the password if login is successful
                if ($password_valid) {
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                    $update_stmt->bind_param("ss", $new_hash, $email);
                    $update_stmt->execute();
                    $update_stmt->close();
                    error_log("Password auto-hashed for user: " . $email);
                }
            }

            if ($password_valid) {
                // Remove password from response for security
                unset($user["password"]);
                
                // DEBUG: Log what we're sending back
                error_log("=== LOGIN SUCCESS ===");
                error_log("Full user data being sent: " . json_encode($user));
                error_log("User ID being sent: " . $user["id"]);
                error_log("User ID type: " . gettype($user["id"]));
                error_log("User email: " . $user["email"]);
                error_log("User keys: " . implode(", ", array_keys($user)));
                
                // Ensure ID is an integer
                $user["id"] = (int)$user["id"];
                error_log("User ID after cast: " . $user["id"] . " (type: " . gettype($user["id"]) . ")");
                error_log("====================");
                
                $response = [
                    "success" => true,
                    "message" => "Login successful",
                    "user" => $user
                ];
                
                error_log("Final JSON response: " . json_encode($response));
                echo json_encode($response);
            } else {
                error_log("Password validation failed");
                echo json_encode(["success" => false, "message" => "Invalid password"]);
            }
        } else {
            error_log("User not found with email: " . $email);
            echo json_encode(["success" => false, "message" => "User not found"]);
        }
        $stmt->close();
    } else {
        error_log("Missing email or password");
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Email and password are required"]);
    }

    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
}
?>