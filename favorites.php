<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php'; // or 'db_connection.php'

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($action) {
        case 'check':
            $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
            $skill_id = isset($_GET['skill_id']) ? $_GET['skill_id'] : '';

            // Check in favorites table using skill_id column
            $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND skill_id = ?");
            $stmt->bind_param("is", $user_id, $skill_id);
            $stmt->execute();
            $result = $stmt->get_result();

            echo json_encode([
                'success' => true,
                'is_favorite' => $result->num_rows > 0
            ]);
            $stmt->close();
            break;

        case 'add':
            $data = json_decode(file_get_contents('php://input'), true);
            $user_id = intval($data['user_id']);
            $skill_id = $data['skill_id'];

            $stmt = $conn->prepare("INSERT INTO favorites (user_id, skill_id, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE created_at=NOW()");
            $stmt->bind_param("is", $user_id, $skill_id);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => 'Added to favorites']);
            $stmt->close();
            break;

        case 'remove':
            $data = json_decode(file_get_contents('php://input'), true);
            $user_id = intval($data['user_id']);
            $skill_id = $data['skill_id'];

            $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND skill_id = ?");
            $stmt->bind_param("is", $user_id, $skill_id);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => 'Removed from favorites']);
            $stmt->close();
            break;

        case 'get_all':
            $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

            $stmt = $conn->prepare("
                SELECT s.* 
                FROM favorites f
                JOIN skills s ON f.skill_id = s.id
                WHERE f.user_id = ?
                ORDER BY f.created_at DESC
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $favorites = [];
            while ($row = $result->fetch_assoc()) {
                $favorites[] = $row;
            }

            echo json_encode(['success' => true, 'favorites' => $favorites]);
            $stmt->close();
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
