<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 30);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'capstone';

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["success" => false, "message" => "Database connection failed"]));
}
$conn->set_charset("utf8");

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_categories_with_courses':
        getCategoriesWithCourses($conn);
        break;
    case 'create':
        createSkill($conn);
        break;
    case 'get':
        getSkills($conn);
        break;
    case 'get_user_skills':
        getUserSkills($conn);
        break;
    case 'update':
        updateSkill($conn);
        break;
    case 'delete':
        deleteSkill($conn);
        break;
    case 'get_by_id':
        getSkillById($conn); // 🔑 CRITICAL: Returns single object (NO wrapper)
        break;
    case 'get_categories':
        getCategories($conn);
        break;
    case 'get_courses':
        getCourses($conn);
        break;
    case 'get_by_course':
        getSkillsByCourse($conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();

// ==================== HELPER FUNCTION: Format Skill Data ====================
function formatSkillData($row)
{
    return [
        'id' => strval($row['id']),
        'user_id' => strval($row['user_id']),
        'name' => $row['name'],
        'description' => $row['description'],
        'level' => $row['level'],
        'category_id' => $row['category_id'] !== null ? strval($row['category_id']) : null,
        'category_name' => $row['category_name'] ?? '',
        'course_id' => $row['course_code'] !== null ? strval($row['course_code']) : null,
        'course_code' => $row['course_code'] !== null ? strval($row['course_code']) : null,
        'course_name' => $row['course_name'] ?? $row['course_title'] ?? null,
        'coins' => $row['coins'] !== null ? (int)$row['coins'] : null,
        'duration_hours' => $row['duration_hours'] !== null ? (int)$row['duration_hours'] : null,
        'duration_days' => $row['duration_days'] !== null ? (int)$row['duration_days'] : null,
        'created_at' => $row['created_at'],
        'provider_name' => $row['provider_name'] ?? null,
        'provider_email' => $row['provider_email'] ?? null
    ];
}

// ==================== GET CATEGORIES ====================
function getCategories($conn)
{
    $result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch categories']);
        return;
    }

    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            'id' => strval($row['id']),
            'name' => $row['name']
        ];
    }
    echo json_encode($categories, JSON_UNESCAPED_UNICODE);
}

// ==================== GET COURSES ====================
function getCourses($conn)
{
    $category_id = $_GET['category_id'] ?? null;

    if ($category_id === null || trim($category_id) === '') {
        echo json_encode([]);
        return;
    }

    $stmt = $conn->prepare("SELECT code, title FROM courses WHERE category_id = ? ORDER BY code ASC");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $stmt->bind_param("s", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = [
            'id' => $row['code'],
            'name' => $row['title']
        ];
    }

    echo json_encode($courses, JSON_UNESCAPED_UNICODE);
    $stmt->close();
}

// ==================== GET CATEGORIES WITH COURSES ====================
function getCategoriesWithCourses($conn)
{
    try {
        $sql = "SELECT 
                    cat.id as category_id,
                    cat.name as category_name,
                    c.code,
                    c.title
                FROM categories cat
                LEFT JOIN courses c ON cat.id = c.category_id
                ORDER BY cat.name, c.code";

        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }

        $categories = [];
        $grouped = [];

        while ($row = $result->fetch_assoc()) {
            $catId = $row['category_id'];
            if (!isset($grouped[$catId])) {
                $grouped[$catId] = [
                    'id' => strval($catId),
                    'name' => $row['category_name'],
                    'courses' => []
                ];
            }
            if ($row['code'] !== null) {
                $grouped[$catId]['courses'][] = [
                    'id' => $row['code'],
                    'code' => $row['code'],
                    'title' => $row['title']
                ];
            }
        }

        $categories = array_values($grouped);
        echo json_encode(['success' => true, 'categories' => $categories], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ==================== GET SKILLS BY COURSE ====================
function getSkillsByCourse($conn)
{
    if (!isset($_GET['course_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'course_id is required']);
        return;
    }

    $course_id = $_GET['course_id'];
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

    $sql = "SELECT s.id, s.user_id, s.name, s.description, s.level,
            s.category_id, cat.name AS category_name, s.course_code,
            co.title AS course_name, co.title AS course_title,
            s.coins, s.duration_hours, s.duration_days, s.created_at,
            u.username AS provider_name, u.email AS provider_email
            FROM skills s
            LEFT JOIN categories cat ON s.category_id = cat.id
            LEFT JOIN courses co ON s.course_code = co.code
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.course_code = ?";

    if ($user_id !== null) $sql .= " AND s.user_id = ?";
    $sql .= " ORDER BY s.created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($user_id !== null) {
        $stmt->bind_param("si", $course_id, $user_id);
    } else {
        $stmt->bind_param("s", $course_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $skills = [];
    while ($row = $result->fetch_assoc()) {
        $skills[] = formatSkillData($row);
    }

    echo json_encode($skills, JSON_UNESCAPED_UNICODE);
    $stmt->close();
}

// ==================== CREATE SKILL ====================
function createSkill($conn)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        return;
    }

    if (
        empty($data['name']) ||
        empty($data['category_id']) ||
        empty($data['level']) ||
        !isset($data['course_code'])
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }

    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 1;
    $coins = isset($data['coins']) ? (int)$data['coins'] : null;
    $duration_hours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
    $duration_days = isset($data['duration_days']) ? (int)$data['duration_days'] : null;

    $stmt = $conn->prepare("
        INSERT INTO skills
        (user_id, name, description, category_id, course_code, level, coins, duration_hours, duration_days)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $stmt->bind_param(
        "isssssiii",
        $user_id,
        $data['name'],
        $data['description'],
        $data['category_id'],
        $data['course_code'],
        $data['level'],
        $coins,
        $duration_hours,
        $duration_days
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Skill created successfully',
            'id' => $conn->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create skill: ' . $stmt->error]);
    }

    $stmt->close();
}

// ==================== GET ALL SKILLS ====================
function getSkills($conn)
{
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

    $sql = "SELECT s.*, cat.name AS category_name,
            co.title AS course_name, co.title AS course_title,
            u.username AS provider_name, u.email AS provider_email
            FROM skills s
            LEFT JOIN categories cat ON s.category_id = cat.id
            LEFT JOIN courses co ON s.course_code = co.code
            LEFT JOIN users u ON s.user_id = u.id";

    if ($user_id !== null) {
        $sql .= " WHERE s.user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $result = $conn->query($sql);
    }

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Query failed']);
        return;
    }

    $skills = [];
    while ($row = $result->fetch_assoc()) {
        $skills[] = formatSkillData($row);
    }

    echo json_encode($skills, JSON_UNESCAPED_UNICODE);
}

// ==================== GET USER SKILLS ====================
function getUserSkills($conn)
{
    if (!isset($_GET['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id is required']);
        return;
    }

    $user_id = (int)$_GET['user_id'];

    $stmt = $conn->prepare("
        SELECT s.*, cat.name AS category_name,
        co.title AS course_name, co.title AS course_title,
        u.username AS provider_name, u.email AS provider_email
        FROM skills s
        LEFT JOIN categories cat ON s.category_id = cat.id
        LEFT JOIN courses co ON s.course_code = co.code
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed']);
        return;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $skills = [];
    while ($row = $result->fetch_assoc()) {
        $skills[] = formatSkillData($row);
    }

    echo json_encode(['success' => true, 'skills' => $skills], JSON_UNESCAPED_UNICODE);
    $stmt->close();
}

// ==================== GET SKILL BY ID (CRITICAL FIX) ====================
// 🔑 RETURNS: Single skill object (NOT wrapped in {success, skill})
// 🔑 MATCHES: Flutter's SkillService.getSkillById() expectations
function getSkillById($conn)
{
    $id = $_GET['id'] ?? '';

    // Validate ID
    if (!$id || !is_numeric($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid skill ID required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT s.*, cat.name AS category_name,
        co.title AS course_name, co.title AS course_title,
        u.username AS provider_name, u.email AS provider_email
        FROM skills s
        LEFT JOIN categories cat ON s.category_id = cat.id
        LEFT JOIN courses co ON s.course_code = co.code
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        return;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    // 🔑 CRITICAL: Return NULL/404 if not found (Flutter expects this)
    if ($result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(null); // Flutter checks for null
        $stmt->close();
        return;
    }

    // 🔑 CRITICAL: Return RAW skill object (NO wrapper)
    // Flutter expects: {id, name, description, ...} NOT {success: true, skill: {...}}
    $skill = $result->fetch_assoc();
    echo json_encode(formatSkillData($skill), JSON_UNESCAPED_UNICODE);

    $stmt->close();
}

// ==================== UPDATE SKILL ====================
function updateSkill($conn)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        return;
    }

    if (empty($data['id']) || !is_numeric($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid ID required']);
        return;
    }

    $id = (int)$data['id'];
    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 1;
    $coins = isset($data['coins']) ? (int)$data['coins'] : null;
    $duration_hours = isset($data['duration_hours']) ? (int)$data['duration_hours'] : null;
    $duration_days = isset($data['duration_days']) ? (int)$data['duration_days'] : null;

    $stmt = $conn->prepare("
        UPDATE skills SET
            user_id = ?, name = ?, description = ?, 
            category_id = ?, course_code = ?, level = ?, coins = ?, 
            duration_hours = ?, duration_days = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $stmt->bind_param(
        "isssssiiii",
        $user_id,
        $data['name'],
        $data['description'],
        $data['category_id'],
        $data['course_code'],
        $data['level'],
        $coins,
        $duration_hours,
        $duration_days,
        $id
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => $stmt->affected_rows > 0,
            'message' => $stmt->affected_rows > 0
                ? 'Skill updated successfully'
                : 'No changes made'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
    }

    $stmt->close();
}

// ==================== DELETE SKILL ====================
function deleteSkill($conn)
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        return;
    }

    if (empty($data['id']) || !is_numeric($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid ID required']);
        return;
    }

    $id = (int)$data['id'];
    $stmt = $conn->prepare("DELETE FROM skills WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed']);
        return;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Skill deleted successfully']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Skill not found']);
    }

    $stmt->close();
}
