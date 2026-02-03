<?php

/**
 * CAPSTONE API ENDPOINT
 * Handles Roadmap Data and User Progress
 */

// 1. CORS & Headers - MUST come before any output
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight "OPTIONS" requests (sent by browsers before POST)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Error Reporting - Set to E_ALL for debugging, 0 for production
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// 3. Database Connection
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'capstone';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]);
    exit;
}
$conn->set_charset("utf8mb4");

// === HELPER FUNCTION ===
function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Determine the action
$action = $_GET['action'] ?? '';

// Log incoming request
error_log("📡 Incoming request: action=$action, method=" . $_SERVER['REQUEST_METHOD']);

// --- ACTION: GET ALL PATHS ---
if ($action === 'get_all_paths') {
    error_log("📡 Fetching all development paths");

    // Optional: Ensure categories exist
    $conn->query("
        INSERT IGNORE INTO roadmap_categories (name, icon) VALUES
        ('Software Development & Engineering', '🌐'),
        ('Data Science & Artificial Intelligence', '🤖'),
        ('Cybersecurity & Infrastructure', '🛡️'),
        ('Specialized & Emerging Fields', '🔬'),
        ('Research & Leadership', '🎓')
    ");

    $query = "
        SELECT 
            p.id, p.category_id, c.name AS category_name, p.name, 
            p.icon AS icon_name, p.description, p.detailed_description, 
            p.difficulty, p.estimated_duration, p.key_skills
        FROM development_paths p
        JOIN roadmap_categories c ON p.category_id = c.id
        ORDER BY c.id, p.id
    ";

    $result = $conn->query($query);
    $paths = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $keySkills = json_decode($row['key_skills'], true);
            $row['key_skills'] = is_array($keySkills) ? $keySkills : [];
            $paths[] = $row;
        }
        error_log("✅ Fetched " . count($paths) . " paths");
    } else {
        error_log("⚠️ No paths found in database");
    }

    jsonResponse(['success' => true, 'data' => $paths]);
}

// --- ACTION: GET USER ROADMAP ---
if ($action === 'get_user_roadmap') {
    $userId = $_GET['user_id'] ?? null;

    error_log("📡 Fetching roadmap for user_id: $userId");

    if (!$userId || !is_numeric($userId)) {
        error_log("❌ Invalid user ID: $userId");
        jsonResponse(['success' => false, 'message' => 'Invalid user ID'], 400);
    }

    $stmt = $conn->prepare("
        SELECT 
            ur.user_id,
            ur.path_id,
            ur.started_at,
            ur.current_level,
            ur.progress_percentage,
            p.name AS path_name, 
            p.icon AS path_icon
        FROM user_roadmaps ur
        JOIN development_paths p ON ur.path_id = p.id
        WHERE ur.user_id = ?
    ");

    if (!$stmt) {
        error_log("❌ SQL prepare failed: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $roadmap = $result->fetch_assoc();

    if ($roadmap) {
        error_log("✅ Roadmap found for user $userId: " . $roadmap['path_name']);
    } else {
        error_log("ℹ️ No roadmap found for user $userId");
    }

    // ✅ Always return success:true — data may be null (no roadmap yet)
    jsonResponse(['success' => true, 'data' => $roadmap]);
}

// --- ACTION: SELECT PATH (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'select_path') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['user_id'] ?? null;
    $pathId = $input['path_id'] ?? null;

    error_log("🚀 Selecting path: user_id=$userId, path_id=$pathId");

    if (!$userId || !$pathId) {
        error_log("❌ Missing user_id or path_id");
        jsonResponse(['success' => false, 'message' => 'Missing user_id or path_id'], 400);
    }

    // Verify path exists
    $checkStmt = $conn->prepare("SELECT id FROM development_paths WHERE id = ?");
    $checkStmt->bind_param("i", $pathId);
    $checkStmt->execute();
    $pathExists = $checkStmt->get_result()->num_rows > 0;

    if (!$pathExists) {
        error_log("❌ Path ID $pathId does not exist");
        jsonResponse(['success' => false, 'message' => 'Invalid path_id'], 400);
    }

    // Check if user already has a roadmap
    $checkUserStmt = $conn->prepare("SELECT user_id FROM user_roadmaps WHERE user_id = ?");
    $checkUserStmt->bind_param("i", $userId);
    $checkUserStmt->execute();
    $userHasRoadmap = $checkUserStmt->get_result()->num_rows > 0;

    if ($userHasRoadmap) {
        // Update existing roadmap
        error_log("ℹ️ Updating existing roadmap for user $userId");
        $stmt = $conn->prepare("
            UPDATE user_roadmaps 
            SET path_id = ?, current_level = 'Beginner', progress_percentage = 0, started_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->bind_param("ii", $pathId, $userId);
    } else {
        // Insert new roadmap
        error_log("ℹ️ Creating new roadmap for user $userId");
        $stmt = $conn->prepare("
            INSERT INTO user_roadmaps (user_id, path_id, current_level, progress_percentage, started_at)
            VALUES (?, ?, 'Beginner', 0, NOW())
        ");
        $stmt->bind_param("ii", $userId, $pathId);
    }

    if (!$stmt) {
        error_log("❌ SQL prepare failed: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $success = $stmt->execute();

    if ($success) {
        error_log("✅ Path selected successfully for user $userId");
        jsonResponse(['success' => true, 'message' => 'Path selected successfully']);
    } else {
        error_log("❌ Failed to insert/update: " . $stmt->error);
        jsonResponse(['success' => false, 'message' => 'Failed to save path: ' . $stmt->error], 500);
    }
}

// --- ACTION: UPDATE PROGRESS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_progress') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['user_id'] ?? null;
    $level = $input['current_level'] ?? 'Beginner';
    $progress = $input['progress_percentage'] ?? 0;

    error_log("🔄 Updating progress: user_id=$userId, level=$level, progress=$progress%");

    if (!$userId) {
        error_log("❌ Missing user_id");
        jsonResponse(['success' => false, 'message' => 'Missing user_id'], 400);
    }

    // Validate level (must match ENUM values)
    $validLevels = ['Beginner', 'Intermediate', 'Advanced'];
    if (!in_array($level, $validLevels)) {
        error_log("❌ Invalid level: $level");
        jsonResponse(['success' => false, 'message' => 'Invalid level. Must be Beginner, Intermediate, or Advanced'], 400);
    }

    // Validate progress (0-100)
    if ($progress < 0 || $progress > 100) {
        error_log("❌ Invalid progress: $progress");
        jsonResponse(['success' => false, 'message' => 'Progress must be between 0 and 100'], 400);
    }

    $stmt = $conn->prepare("
        UPDATE user_roadmaps 
        SET current_level = ?, progress_percentage = ? 
        WHERE user_id = ?
    ");

    if (!$stmt) {
        error_log("❌ SQL prepare failed: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $stmt->bind_param("sii", $level, $progress, $userId);
    $success = $stmt->execute();

    if ($success) {
        $affectedRows = $stmt->affected_rows;
        if ($affectedRows > 0) {
            error_log("✅ Progress updated successfully for user $userId");
            jsonResponse(['success' => true, 'message' => 'Progress updated']);
        } else {
            error_log("⚠️ No rows updated - user might not have a roadmap");
            jsonResponse(['success' => false, 'message' => 'No roadmap found for this user'], 404);
        }
    } else {
        error_log("❌ Failed to update progress: " . $stmt->error);
        jsonResponse(['success' => false, 'message' => 'Failed to update: ' . $stmt->error], 500);
    }
}

// --- ACTION: GET PATH LEVELS ---
if ($action === 'get_path_levels') {
    $pathId = $_GET['path_id'] ?? null;

    error_log("📡 Fetching levels for path_id: $pathId");

    if (!$pathId || !is_numeric($pathId)) {
        jsonResponse(['success' => false, 'message' => 'Invalid path ID'], 400);
    }

    $stmt = $conn->prepare("
        SELECT * FROM roadmap_levels 
        WHERE path_id = ? 
        ORDER BY level_order ASC
    ");
    $stmt->bind_param("i", $pathId);
    $stmt->execute();
    $result = $stmt->get_result();
    $levels = [];

    while ($row = $result->fetch_assoc()) {
        $levels[] = $row;
    }

    error_log("✅ Fetched " . count($levels) . " levels");
    jsonResponse(['success' => true, 'data' => $levels]);
}

// --- ACTION: GET PATH RESOURCES ---
if ($action === 'get_path_resources') {
    $pathId = $_GET['path_id'] ?? null;

    if (!$pathId || !is_numeric($pathId)) {
        jsonResponse(['success' => false, 'message' => 'Invalid path ID'], 400);
    }

    $type = $_GET['type'] ?? null;
    $level = $_GET['level'] ?? null;

    $sql = "SELECT * FROM roadmap_resources WHERE path_id = ?";
    $params = [$pathId];
    $types = "i";

    if ($type) {
        $sql .= " AND type = ?";
        $params[] = $type;
        $types .= "s";
    }
    if ($level) {
        $sql .= " AND level = ?";
        $params[] = $level;
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $resources = [];

    while ($row = $result->fetch_assoc()) {
        $resources[] = $row;
    }

    error_log("✅ Fetched " . count($resources) . " resources");
    jsonResponse(['success' => true, 'data' => $resources]);
}

// --- ACTION: GET PATH PROJECTS ---
if ($action === 'get_path_projects') {
    $pathId = $_GET['path_id'] ?? null;

    error_log("📡 Fetching projects for path_id: $pathId");

    if (!$pathId || !is_numeric($pathId)) {
        error_log("❌ Invalid path ID: $pathId");
        jsonResponse(['success' => false, 'message' => 'Invalid path ID'], 400);
    }

    $level = $_GET['level'] ?? null;

    // Select specific columns to match RoadmapProject model
    $sql = "SELECT 
                id,
                path_id,
                name,
                level,
                description,
                technologies,
                github_url,
                estimated_hours
            FROM roadmap_projects 
            WHERE path_id = ?";

    $params = [$pathId];
    $types = "i";

    if ($level) {
        $sql .= " AND level = ?";
        $params[] = $level;
        $types .= "s";
    }

    // Order by difficulty level
    $sql .= " ORDER BY 
                CASE level
                    WHEN 'Beginner' THEN 1
                    WHEN 'Intermediate' THEN 2
                    WHEN 'Advanced' THEN 3
                    ELSE 4
                END,
                id ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("❌ SQL prepare failed: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("❌ Query execution failed: " . $stmt->error);
        jsonResponse(['success' => false, 'message' => 'Query failed: ' . $stmt->error], 500);
    }

    $result = $stmt->get_result();
    $projects = [];

    while ($row = $result->fetch_assoc()) {
        // Ensure data types are correct
        $projects[] = [
            'id' => (int)$row['id'],
            'path_id' => (int)$row['path_id'],
            'name' => $row['name'],
            'level' => $row['level'],
            'description' => $row['description'],
            'technologies' => $row['technologies'], // JSON string - will be parsed by Flutter
            'github_url' => $row['github_url'] ?? null,
            'estimated_hours' => (int)($row['estimated_hours'] ?? 0)
        ];
    }

    error_log("✅ Fetched " . count($projects) . " projects for path_id: $pathId");
    jsonResponse(['success' => true, 'data' => $projects, 'count' => count($projects)]);
}

// --- ACTION: GET RECOMMENDATIONS ---
if ($action === 'get_recommendations') {
    $pathId = $_GET['path_id'] ?? null;

    if (!$pathId || !is_numeric($pathId)) {
        jsonResponse(['success' => false, 'message' => 'Invalid path ID'], 400);
    }

    // Example: Return static or dynamic recommendations
    $recommendations = [
        'recommended_skills' => ['Git', 'Docker', 'REST APIs'],
        'learning_tips' => 'Practice daily and build real projects.',
        'next_steps' => 'Join our community Discord for support.'
    ];

    jsonResponse(['success' => true, 'data' => $recommendations]);
}

// Default response if no action matches
error_log("❌ Invalid action: $action");
jsonResponse(['success' => false, 'message' => 'Invalid action or method'], 400);
