<?php
// =============================================================================
// Ratings API - Handles all rating operations
// Supports: POST (submit), GET (fetch ratings)
// Database: capstone
// =============================================================================

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- DATABASE CONNECTION ---
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// --- INPUT VALIDATION HELPER ---
function validateInt($value, $min = 1)
{
    if (!is_numeric($value) || $value < $min || intval($value) != $value) {
        return false;
    }
    return (int)$value;
}

// --- PARSE REQUEST ---
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        // Test endpoint
        case 'test':
            echo json_encode([
                'success' => true,
                'message' => 'Ratings API is working',
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $method
            ]);
            break;

        // Submit or update a rating
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

            if (
                !isset($data['user_id']) ||
                !isset($data['rateable_type']) ||
                !isset($data['rateable_id']) ||
                !isset($data['rating'])
            ) {
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

        // Get user's rating for a specific item
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
                echo json_encode($rating);
            } else {
                // Return empty result instead of 404 for user ratings
                echo json_encode(['success' => false, 'message' => 'No rating found']);
            }
            break;

        // Get all ratings for an item + average
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

        // Get top-rated items
        case 'get_top_rated':
            if ($method !== 'GET') {
                throw new Exception('GET method required');
            }

            $type = $_GET['type'] ?? 'all';
            $limit = min(max((int)($_GET['limit'] ?? 10), 1), 50); // Limit between 1-50

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
                foreach ($items as &$item) $item['type'] = 'skill';
                echo json_encode($items);
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
                foreach ($items as &$item) $item['type'] = 'community';
                echo json_encode($items);
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

                echo json_encode([
                    'success' => true,
                    'skills' => $skills,
                    'communities' => $communities
                ]);
            }
            break;

        default:
            http_response_code(400);
            throw new Exception('Invalid action. Use: test, submit, get_user_rating, get_item_ratings, get_top_rated');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
