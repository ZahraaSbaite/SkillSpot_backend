<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'capstone';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = $_GET['action'] ?? '';

// Check if a request already exists
if ($action === 'check_request') {
    $skill_id = $_GET['skill_id'] ?? null;
    $requester_id = $_GET['requester_id'] ?? null;

    if (!$skill_id || !$requester_id) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, skill_id, requester_user_id, skill_owner_user_id, status, message, 
                   learning_mode, start_date, end_date, created_at 
            FROM skill_requests 
            WHERE skill_id = ? AND requester_user_id = ?
        ");
        $stmt->execute([$skill_id, $requester_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($request) {
            echo json_encode(['success' => true, 'request' => $request]);
        } else {
            echo json_encode(['success' => true, 'request' => null]);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Create a new join request
if ($action === 'create_request') {
    $data = json_decode(file_get_contents('php://input'), true);

    $skill_id = $data['skill_id'] ?? null;
    $requester_user_id = $data['requester_user_id'] ?? null;
    $skill_owner_user_id = $data['skill_owner_user_id'] ?? null;
    $message = $data['message'] ?? '';
    $learning_mode = $data['learning_mode'] ?? 'online';
    $start_date = $data['start_date'] ?? null;
    $end_date = $data['end_date'] ?? null;

    if (!$skill_id || !$requester_user_id || !$skill_owner_user_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    if (!$start_date || !$end_date) {
        echo json_encode(['success' => false, 'message' => 'Start date and end date are required']);
        exit();
    }

    $start = strtotime($start_date);
    $end = strtotime($end_date);

    if ($end <= $start) {
        echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
        exit();
    }

    if ($requester_user_id == $skill_owner_user_id) {
        echo json_encode(['success' => false, 'message' => 'You cannot request to join your own skill']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id FROM skill_requests 
            WHERE skill_id = ? AND requester_user_id = ?
        ");
        $stmt->execute([$skill_id, $requester_user_id]);

        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You have already sent a request for this skill']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO skill_requests 
            (skill_id, requester_user_id, skill_owner_user_id, message, learning_mode, start_date, end_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$skill_id, $requester_user_id, $skill_owner_user_id, $message, $learning_mode, $start_date, $end_date]);

        echo json_encode([
            'success' => true,
            'message' => 'Request sent successfully',
            'request_id' => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Get all requests for a skill owner (WITH FULL SKILL DETAILS)
if ($action === 'get_owner_requests') {
    $owner_id = $_GET['owner_id'] ?? null;

    if (!$owner_id) {
        echo json_encode(['success' => false, 'message' => 'Owner ID required']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                sr.id as request_id,
                sr.skill_id,
                sr.requester_user_id,
                sr.status as request_status,
                sr.message as request_message,
                sr.learning_mode,
                sr.start_date as request_start_date,
                sr.end_date as request_end_date,
                sr.created_at as request_created_at,
                sr.updated_at as request_updated_at,
                s.id as skill_id,
                s.user_id as skill_user_id,
                s.name as skill_name,
                s.description as skill_description,
                s.level as skill_level,
                s.category_id as skill_category_id,
                s.course_code as skill_course_code,
                s.coins as skill_coins,
                s.duration_hours as skill_duration_hours,
                s.duration_days as skill_duration_days,
                s.created_at as skill_created_at,
                c.name as category_name,
                u.id as requester_id,
                u.username as requester_name,
                u.email as requester_email
            FROM skill_requests sr
            JOIN skills s ON sr.skill_id = s.id
            LEFT JOIN categories c ON s.category_id = c.id
            JOIN users u ON sr.requester_user_id = u.id
            WHERE sr.skill_owner_user_id = ?
            ORDER BY sr.created_at DESC
        ");
        $stmt->execute([$owner_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Get all requests made by a user (WITH FULL SKILL DETAILS)
if ($action === 'get_user_requests') {
    $user_id = $_GET['user_id'] ?? null;

    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                sr.id as request_id,
                sr.skill_id,
                sr.skill_owner_user_id,
                sr.status as request_status,
                sr.message as request_message,
                sr.learning_mode,
                sr.start_date as request_start_date,
                sr.end_date as request_end_date,
                sr.created_at as request_created_at,
                sr.updated_at as request_updated_at,
                s.id as skill_id,
                s.user_id as skill_user_id,
                s.name as skill_name,
                s.description as skill_description,
                s.level as skill_level,
                s.category_id as skill_category_id,
                s.course_code as skill_course_code,
                s.coins as skill_coins,
                s.duration_hours as skill_duration_hours,
                s.duration_days as skill_duration_days,
                s.created_at as skill_created_at,
                c.name as category_name,
                u.id as owner_id,
                u.username as owner_name,
                u.email as owner_email
            FROM skill_requests sr
            JOIN skills s ON sr.skill_id = s.id
            LEFT JOIN categories c ON s.category_id = c.id
            JOIN users u ON sr.skill_owner_user_id = u.id
            WHERE sr.requester_user_id = ?
            ORDER BY sr.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Update request status (accept/reject) - WITH COIN DEDUCTION
if ($action === 'update_status') {
    $data = json_decode(file_get_contents('php://input'), true);

    $request_id = $data['request_id'] ?? null;
    $status = $data['status'] ?? null;
    $owner_id = $data['owner_id'] ?? null;

    if (!$request_id || !$status || !$owner_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    if (!in_array($status, ['accepted', 'rejected'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Fetch full request with skill details including coins
        $stmt = $pdo->prepare("
            SELECT 
                sr.skill_id, 
                sr.requester_user_id, 
                sr.start_date, 
                sr.end_date,
                s.name AS skill_name,
                s.coins AS skill_coins,
                s.user_id AS skill_owner_id,
                u.username AS requester_name,
                u.coins AS requester_coins
            FROM skill_requests sr
            JOIN skills s ON sr.skill_id = s.id
            JOIN users u ON sr.requester_user_id = u.id
            WHERE sr.id = ? AND sr.skill_owner_user_id = ?
        ");
        $stmt->execute([$request_id, $owner_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Request not found or access denied']);
            exit();
        }

        // If accepting, handle coin transaction
        if ($status === 'accepted') {
            $skill_coins = (int)($request['skill_coins'] ?? 0);
            $requester_coins = (int)($request['requester_coins'] ?? 0);
            $skill_owner_id = $request['skill_owner_id'];
            $requester_user_id = $request['requester_user_id'];

            // Only process coins if skill has a coin requirement
            if ($skill_coins > 0) {
                // Check if requester has enough coins
                if ($requester_coins < $skill_coins) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Insufficient coins. Required: $skill_coins, Available: $requester_coins"
                    ]);
                    exit();
                }

                // Deduct coins from requester
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET coins = coins - ? 
                    WHERE id = ?
                ");
                $stmt->execute([$skill_coins, $requester_user_id]);

                // Add coins to skill owner
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET coins = coins + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$skill_coins, $skill_owner_id]);

                // Log the transaction (optional but recommended)
                $stmt = $pdo->prepare("
                    INSERT INTO coin_transactions 
                    (from_user_id, to_user_id, amount, transaction_type, skill_id, created_at) 
                    VALUES (?, ?, ?, 'skill_enrollment', ?, NOW())
                ");

                // Check if coin_transactions table exists, if not, skip logging
                try {
                    $stmt->execute([$requester_user_id, $skill_owner_id, $skill_coins, $request['skill_id']]);
                } catch (PDOException $e) {
                    // Table might not exist, continue without logging
                    error_log("Coin transaction logging failed (table may not exist): " . $e->getMessage());
                }
            }

            // Check if already enrolled
            $stmt = $pdo->prepare("SELECT 1 FROM user_learnings WHERE user_id = ? AND skill_id = ?");
            $stmt->execute([$requester_user_id, $request['skill_id']]);

            if (!$stmt->fetch()) {
                // Enroll user in the skill
                $stmt = $pdo->prepare("
                    INSERT INTO user_learnings 
                    (user_id, skill_id, status, progress_percentage, enrollment_date, start_date, completion_date, last_accessed, created_at, updated_at) 
                    VALUES (?, ?, 'enrolled', 0, NOW(), ?, ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([
                    $requester_user_id,
                    $request['skill_id'],
                    $request['start_date'],
                    $request['end_date']
                ]);
            }
        }

        // Update request status
        $stmt = $pdo->prepare("UPDATE skill_requests SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $request_id]);

        $pdo->commit();

        $response = [
            'success' => true,
            'message' => "Request $status successfully"
        ];

        // Add coin transaction info to response if applicable
        if ($status === 'accepted' && isset($skill_coins) && $skill_coins > 0) {
            $response['coins_transferred'] = $skill_coins;
            $response['message'] .= " - $skill_coins coins transferred";
        }

        echo json_encode($response);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("❌ update_status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
    }
    exit();
}

// Delete a request
if ($action === 'delete_request') {
    $data = json_decode(file_get_contents('php://input'), true);

    $request_id = $data['request_id'] ?? null;
    $user_id = $data['user_id'] ?? null;

    if (!$request_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM skill_requests 
            WHERE id = ? AND requester_user_id = ?
        ");
        $stmt->execute([$request_id, $user_id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Request deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Request not found or unauthorized']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
