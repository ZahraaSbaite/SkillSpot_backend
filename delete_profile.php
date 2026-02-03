<?php
// Enable errors temporarily for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Headers MUST come before any output
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

// Start output buffering to catch any errors
ob_start();

try {
    // Database connection
    $conn = new mysqli("localhost", "root", "", "capstone");

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $conn->set_charset("utf8");

    // Get and parse input
    $input = file_get_contents('php://input');

    if (empty($input)) {
        throw new Exception("Empty request body");
    }

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }

    // Get user_id
    $user_id = $data['user_id'] ?? $data['id'] ?? null;

    if (empty($user_id)) {
        throw new Exception("User ID is required");
    }

    $user_id = (int)$user_id;

    // Check if user exists
    $check = $conn->prepare("SELECT id FROM users WHERE id = ?");

    if (!$check) {
        throw new Exception("Prepare check failed: " . $conn->error);
    }

    $check->bind_param("i", $user_id);

    if (!$check->execute()) {
        throw new Exception("Execute check failed: " . $check->error);
    }

    $result = $check->get_result();

    if ($result->num_rows === 0) {
        $check->close();
        $conn->close();

        ob_end_clean();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "User not found"]);
        exit;
    }

    $check->close();

    // Helper function to check if table exists
    function tableExists($conn, $tableName)
    {
        $result = $conn->query("SHOW TABLES LIKE '$tableName'");
        return $result && $result->num_rows > 0;
    }

    // Helper function to check if column exists
    function columnExists($conn, $tableName, $columnName)
    {
        $result = $conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return $result && $result->num_rows > 0;
    }

    // Start transaction for safe deletion
    $conn->begin_transaction();

    try {
        // Delete from tables that reference users (check what columns exist first)

        // Messages table
        if (tableExists($conn, 'messages')) {
            $whereClauses = [];
            if (columnExists($conn, 'messages', 'sender_id')) {
                $whereClauses[] = "sender_id = $user_id";
            }
            if (columnExists($conn, 'messages', 'receiver_id')) {
                $whereClauses[] = "receiver_id = $user_id";
            }
            if (columnExists($conn, 'messages', 'user_id')) {
                $whereClauses[] = "user_id = $user_id";
            }
            if (!empty($whereClauses)) {
                $conn->query("DELETE FROM messages WHERE " . implode(" OR ", $whereClauses));
            }
        }

        // Calendar events
        if (tableExists($conn, 'calendar_events') && columnExists($conn, 'calendar_events', 'user_id')) {
            $conn->query("DELETE FROM calendar_events WHERE user_id = $user_id");
        }

        // Community members
        if (tableExists($conn, 'community_members') && columnExists($conn, 'community_members', 'user_id')) {
            $conn->query("DELETE FROM community_members WHERE user_id = $user_id");
        }

        // Communities
        if (tableExists($conn, 'communities') && columnExists($conn, 'communities', 'created_by')) {
            $conn->query("DELETE FROM communities WHERE created_by = $user_id");
        }

        // Transactions
        if (tableExists($conn, 'transactions')) {
            $whereClauses = [];
            if (columnExists($conn, 'transactions', 'sender_id')) {
                $whereClauses[] = "sender_id = $user_id";
            }
            if (columnExists($conn, 'transactions', 'receiver_id')) {
                $whereClauses[] = "receiver_id = $user_id";
            }
            if (columnExists($conn, 'transactions', 'user_id')) {
                $whereClauses[] = "user_id = $user_id";
            }
            if (!empty($whereClauses)) {
                $conn->query("DELETE FROM transactions WHERE " . implode(" OR ", $whereClauses));
            }
        }

        // Skills
        if (tableExists($conn, 'skills') && columnExists($conn, 'skills', 'user_id')) {
            $conn->query("DELETE FROM skills WHERE user_id = $user_id");
        }

        // Finally, delete the user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");

        if (!$stmt) {
            throw new Exception("Prepare delete failed: " . $conn->error);
        }

        $stmt->bind_param("i", $user_id);

        if (!$stmt->execute()) {
            throw new Exception("Execute delete failed: " . $stmt->error);
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        // Commit transaction
        $conn->commit();
        $conn->close();

        // Clear buffer and send success response
        ob_end_clean();

        if ($affected > 0) {
            echo json_encode([
                "success" => true,
                "message" => "Profile and all related data deleted successfully"
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "No rows were deleted"
            ]);
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    // Clear buffer
    ob_end_clean();

    // Log error
    error_log("Delete profile error: " . $e->getMessage());

    // Send error response
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}
