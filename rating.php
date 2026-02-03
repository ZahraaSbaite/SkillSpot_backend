<?php
// =============================================================================
// Ratings API - Handles all rating operations
// Supports: POST (submit), GET (fetch ratings)
// Database: capstone
// =============================================================================

// CORS Headers - Must be first
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in response
ini_set('log_errors', 1);

// --- DATABASE CONNECTION ---
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]);
    exit();
}

// --- INPUT VALIDATION HELPER ---
function validateInt($value, $min = 1) {
    if (!is_numeric($value) || $value < $min || intval($value) != $value) {
        return false;
    }
    return (int)$value;
}

// --- PARSE REQUEST ---
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Log request for debugging
error_log("Rating API Request - Action: $action, Method: $method");

try {
    switch ($action) {
        
        // ============================================
        // TEST ENDPOINT
        // ============================================
        case 'test':
            echo json_encode([
                'success' => true,
                'message' => 'Ratings API is working perfectly',
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $method,
                'database' => 'connected'
            ]);
            break;

        // ============================================
        // SUBMIT OR UPDATE RATING
        // ============================================
        case 'create':
        case 'submit':
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }

            $rawInput = file_get_contents('php://input');
            error_log("Raw input: " . $rawInput);

            $data = json_decode($rawInput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }

            if (!isset($data['user_id']) || !isset($data['rateable_type']) || 
                !isset($data['rateable_id']) || !isset($data['rating'])) {
                throw new Exception('Missing required fields: user_id, rateable_type, rateable_id, rating');
            }

            $user_id = validateInt($data['user_id']);
            $rateable_id = validateInt($data['rateable_id']);
            $rating = (float)$data['rating'];
            $rateable_type = trim($data['rateable_type']);
            $review = isset($data['review']) ? trim($data['review']) : null;

            if (!$user_id || !$rateable_id) {
                throw new Exception('Invalid user_id or rateable_id');
            }

            if ($rating < 1 || $rating > 5) {
                throw new Exception('Rating must be between 1 and 5');
            }

            if (!in_array($rateable_type, ['skill', 'community'])) {
                throw new Exception('Invalid rateable_type. Must be "skill" or "community"');
            }

            // Check if already rated
            $check = $pdo->prepare("SELECT id FROM ratings WHERE user_id = ? AND rateable_type = ? AND rateable_id = ?");
            $check->execute([$user_id, $rateable_type, $rateable_id]);

            if ($check->fetch()) {
                // Update existing
                $sql = "UPDATE ratings SET rating = ?, review = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE user_id = ? AND rateable_type = ? AND rateable_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$rating, $review, $user_id, $rateable_type, $rateable_id]);
                $message = 'Rating updated successfully';
            } else {
                // Insert new
                $sql = "INSERT INTO ratings (user_id, rateable_type, rateable_id, rating, review) 
                        VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$user_id, $rateable_type, $rateable_id, $rating, $review]);
                $message = 'Rating created successfully';
            }

            echo json_encode(['success' => true, 'message' => $message]);
            break;

        // ============================================
        // GET USER'S RATING FOR A SPECIFIC ITEM
        // ============================================
        case 'get_user_rating':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $user_id = validateInt($_GET['user_id'] ?? null);
            $rateable_type = $_GET['rateable_type'] ?? '';
            $rateable_id = validateInt($_GET['rateable_id'] ?? null);

            if (!$user_id || !$rateable_id || !in_array($rateable_type, ['skill', 'community'])) {
                throw new Exception('Invalid parameters');
            }

            $stmt = $pdo->prepare("SELECT * FROM ratings WHERE user_id = ? AND rateable_type = ? AND rateable_id = ?");
            $stmt->execute([$user_id, $rateable_type, $rateable_id]);
            $rating = $stmt->fetch();

            if ($rating) {
                echo json_encode(['success' => true, 'data' => $rating]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No rating found']);
            }
            break;

        // ============================================
        // ✅ NEW: GET ALL USER'S RATINGS BY TYPE
        // ============================================
        case 'get_user_ratings':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $user_id = validateInt($_GET['user_id'] ?? null);
            $rateable_type = $_GET['rateable_type'] ?? '';

            if (!$user_id) {
                throw new Exception('Invalid user_id');
            }

            if (!in_array($rateable_type, ['skill', 'community', ''])) {
                throw new Exception('Invalid rateable_type. Must be "skill", "community", or empty for all');
            }

            if ($rateable_type) {
                // Get ratings for specific type
                $stmt = $pdo->prepare("SELECT rateable_id, rating FROM ratings WHERE user_id = ? AND rateable_type = ?");
                $stmt->execute([$user_id, $rateable_type]);
            } else {
                // Get all ratings for user
                $stmt = $pdo->prepare("SELECT rateable_id, rateable_type, rating FROM ratings WHERE user_id = ?");
                $stmt->execute([$user_id]);
            }

            $ratings = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'ratings' => $ratings,
                'count' => count($ratings)
            ]);
            break;

        // ============================================
        // GET ALL RATINGS FOR AN ITEM + AVERAGE
        // ============================================
        case 'get_item_ratings':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $rateable_type = $_GET['rateable_type'] ?? '';
            $rateable_id = validateInt($_GET['rateable_id'] ?? null);

            if (!$rateable_id || !in_array($rateable_type, ['skill', 'community'])) {
                throw new Exception('Invalid rateable_type or rateable_id');
            }

            // Fetch ratings with user names
            $stmt = $pdo->prepare("
                SELECT r.*, u.name as user_name 
                FROM ratings r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.rateable_type = ? AND r.rateable_id = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$rateable_type, $rateable_id]);
            $ratings = $stmt->fetchAll();

            // Get average
            $avgStmt = $pdo->prepare("
                SELECT AVG(rating) as average, COUNT(*) as count 
                FROM ratings 
                WHERE rateable_type = ? AND rateable_id = ?
            ");
            $avgStmt->execute([$rateable_type, $rateable_id]);
            $avg = $avgStmt->fetch();

            echo json_encode([
                'success' => true,
                'ratings' => $ratings,
                'average_rating' => $avg['average'] ? round((float)$avg['average'], 1) : 0,
                'rating_count' => (int)$avg['count']
            ]);
            break;

        // ============================================
        // GET TOP RATED ITEMS
        // ============================================
        case 'get_top_rated':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $type = $_GET['type'] ?? 'all';
            $limit = min(max((int)($_GET['limit'] ?? 10), 1), 50);

            if ($type === 'skill') {
                $stmt = $pdo->prepare("
                    SELECT s.*, AVG(r.rating) as average_rating, COUNT(r.id) as rating_count 
                    FROM skills s 
                    INNER JOIN ratings r ON r.rateable_id = s.id AND r.rateable_type = 'skill'
                    GROUP BY s.id 
                    HAVING rating_count >= 1
                    ORDER BY average_rating DESC, rating_count DESC 
                    LIMIT ?
                ");
                $stmt->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt->execute();
                $items = $stmt->fetchAll();
                foreach ($items as &$item) {
                    $item['type'] = 'skill';
                    $item['average_rating'] = round((float)$item['average_rating'], 1);
                }
                echo json_encode(['success' => true, 'data' => $items]);
                
            } elseif ($type === 'community') {
                $stmt = $pdo->prepare("
                    SELECT c.*, AVG(r.rating) as average_rating, COUNT(r.id) as rating_count 
                    FROM communities c 
                    INNER JOIN ratings r ON r.rateable_id = c.id AND r.rateable_type = 'community'
                    GROUP BY c.id 
                    HAVING rating_count >= 1
                    ORDER BY average_rating DESC, rating_count DESC 
                    LIMIT ?
                ");
                $stmt->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt->execute();
                $items = $stmt->fetchAll();
                foreach ($items as &$item) {
                    $item['type'] = 'community';
                    $item['average_rating'] = round((float)$item['average_rating'], 1);
                }
                echo json_encode(['success' => true, 'data' => $items]);
                
            } else {
                // Both types
                $stmt1 = $pdo->prepare("
                    SELECT 'skill' as type, s.id, s.name, s.description, 
                           AVG(r.rating) as average_rating, COUNT(r.id) as rating_count 
                    FROM skills s 
                    INNER JOIN ratings r ON r.rateable_id = s.id AND r.rateable_type = 'skill'
                    GROUP BY s.id 
                    HAVING rating_count >= 1
                    ORDER BY average_rating DESC, rating_count DESC 
                    LIMIT ?
                ");
                $stmt1->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt1->execute();
                $skills = $stmt1->fetchAll();
                foreach ($skills as &$skill) {
                    $skill['average_rating'] = round((float)$skill['average_rating'], 1);
                }

                $stmt2 = $pdo->prepare("
                    SELECT 'community' as type, c.id, c.name, c.description, 
                           AVG(r.rating) as average_rating, COUNT(r.id) as rating_count 
                    FROM communities c 
                    INNER JOIN ratings r ON r.rateable_id = c.id AND r.rateable_type = 'community'
                    GROUP BY c.id 
                    HAVING rating_count >= 1
                    ORDER BY average_rating DESC, rating_count DESC 
                    LIMIT ?
                ");
                $stmt2->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt2->execute();
                $communities = $stmt2->fetchAll();
                foreach ($communities as &$community) {
                    $community['average_rating'] = round((float)$community['average_rating'], 1);
                }

                echo json_encode([
                    'success' => true,
                    'skills' => $skills,
                    'communities' => $communities
                ]);
            }
            break;

        // ============================================
        // ANALYTICS: SKILL RATINGS FOR CHART
        // ============================================
        case 'get_skill_rating':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $limit = min(max((int)($_GET['limit'] ?? 10), 1), 20);

            $stmt = $pdo->prepare("
                SELECT s.name as skill_name, 
                       ROUND(AVG(r.rating), 1) as average_rating,
                       COUNT(r.id) as rating_count
                FROM skills s
                INNER JOIN ratings r ON r.rateable_id = s.id AND r.rateable_type = 'skill'
                GROUP BY s.id, s.name
                HAVING rating_count >= 1
                ORDER BY average_rating DESC, rating_count DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            if (empty($results)) {
                echo json_encode([
                    'success' => true,
                    'labels' => [],
                    'data' => [],
                    'message' => 'No skill ratings available'
                ]);
            } else {
                $labels = array_map(function($row) { return $row['skill_name']; }, $results);
                $data = array_map(function($row) { return (float)$row['average_rating']; }, $results);

                echo json_encode([
                    'success' => true,
                    'labels' => $labels,
                    'data' => $data,
                    'count' => count($results)
                ]);
            }
            break;

        // ============================================
        // ANALYTICS: COMMUNITY RATINGS FOR CHART
        // ============================================
        case 'get_community_rating':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $limit = min(max((int)($_GET['limit'] ?? 10), 1), 20);

            $stmt = $pdo->prepare("
                SELECT c.name as community_name, 
                       ROUND(AVG(r.rating), 1) as average_rating,
                       COUNT(r.id) as rating_count
                FROM communities c
                INNER JOIN ratings r ON r.rateable_id = c.id AND r.rateable_type = 'community'
                GROUP BY c.id, c.name
                HAVING rating_count >= 1
                ORDER BY average_rating DESC, rating_count DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            if (empty($results)) {
                echo json_encode([
                    'success' => true,
                    'labels' => [],
                    'data' => [],
                    'message' => 'No community ratings available'
                ]);
            } else {
                $labels = array_map(function($row) { return $row['community_name']; }, $results);
                $data = array_map(function($row) { return (float)$row['average_rating']; }, $results);

                echo json_encode([
                    'success' => true,
                    'labels' => $labels,
                    'data' => $data,
                    'count' => count($results)
                ]);
            }
            break;

        // ============================================
        // INVALID ACTION
        // ============================================
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action',
                'available_actions' => [
                    'test',
                    'submit',
                    'get_user_rating',
                    'get_user_ratings',
                    'get_item_ratings',
                    'get_top_rated',
                    'get_skill_rating',
                    'get_community_rating'
                ]
            ]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    error_log("Rating API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'action' => $action
    ]);
}
?>