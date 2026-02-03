<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
    die(json_encode(["success" => false, "message" => "Connection failed"]));
}
$conn->set_charset("utf8");

// Get search parameters from Flutter (sending 'q')
$query = $_GET['q'] ?? $_GET['query'] ?? '';
$searchTerm = "%$query%";

try {
    $results = [];

    // 1. Search for COURSES
    $sqlCourses = "SELECT c.code, c.title, c.description, c.category_id, cat.name as category_name 
                   FROM courses c 
                   LEFT JOIN categories cat ON c.category_id = cat.id 
                   WHERE c.title LIKE ? OR c.code LIKE ?
                   LIMIT 15";

    $stmt1 = $conn->prepare($sqlCourses);
    $stmt1->bind_param("ss", $searchTerm, $searchTerm);
    $stmt1->execute();
    $courseRes = $stmt1->get_result();
    while ($row = $courseRes->fetch_assoc()) {
        $results[] = [
            "type" => "course",
            "code" => $row['code'],
            "title" => $row['title'],
            "description" => $row['description'] ?? '',
            "category_id" => $row['category_id'],
            "category_name" => $row['category_name'] ?? ''
        ];
    }

    // 2. Search for SKILLS (Fetching Provider Name and Durations)
    $sqlSkills = "SELECT s.*, cat.name AS category_name, co.title AS course_title, 
                         u.name AS provider_name, u.email AS provider_email
                  FROM skills s
                  LEFT JOIN categories cat ON s.category_id = cat.id
                  LEFT JOIN courses co ON s.course_code = co.code
                  LEFT JOIN users u ON s.user_id = u.id
                  WHERE s.name LIKE ? OR s.description LIKE ?
                  ORDER BY s.created_at DESC 
                  LIMIT 20";

    $stmt2 = $conn->prepare($sqlSkills);
    $stmt2->bind_param("ss", $searchTerm, $searchTerm);
    $stmt2->execute();
    $skillRes = $stmt2->get_result();
    while ($row = $skillRes->fetch_assoc()) {
        $results[] = [
            "type" => "skill",
            "id" => $row['id'],
            "user_id" => $row['user_id'],
            "name" => $row['name'],
            "description" => $row['description'] ?? '',
            "level" => $row['level'] ?? 'Beginner',
            "coins" => $row['coins'] !== null ? (int)$row['coins'] : 0,
            "course_code" => $row['course_code'],
            "course_title" => $row['course_title'] ?? '',
            "category_name" => $row['category_name'] ?? '',
            "provider_name" => $row['provider_name'] ?? 'System User',
            "provider_email" => $row['provider_email'] ?? '',
            "duration_hours" => $row['duration_hours'],
            "duration_days" => $row['duration_days'],
            "is_system_skill" => (int)$row['user_id'] === 0
        ];
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results)
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
