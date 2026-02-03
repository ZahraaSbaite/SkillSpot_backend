<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
ini_set('max_execution_time', 30);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "capstone";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $conn->connect_error
    ]);
    exit;
}
$conn->set_charset("utf8");

try {
    // OPTIMIZED: Single query with LEFT JOIN instead of nested queries
    $sql = "SELECT 
                cat.id as category_id,
                cat.name as category_name,
                c.code,
                c.title,
                c.description,
                c.category_id as course_category_id
            FROM categories cat
            LEFT JOIN courses c ON cat.id = c.category_id
            ORDER BY cat.id, c.code";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("Error fetching data: " . $conn->error);
    }

    $categories = [];
    $currentCategoryId = null;
    $currentCategory = null;

    while ($row = $result->fetch_assoc()) {
        // New category detected
        if ($currentCategoryId !== $row['category_id']) {
            // Save previous category if exists
            if ($currentCategory !== null) {
                $categories[] = $currentCategory;
            }

            // Initialize new category
            $currentCategoryId = $row['category_id'];
            $currentCategory = [
                'id' => strval($row['category_id']),
                'name' => $row['category_name'],
                'courses' => []
            ];
        }

        // Add course to current category if course exists
        if ($row['code'] !== null) {
            $currentCategory['courses'][] = [
                'id' => $row['code'],
                'code' => $row['code'],
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'category_id' => strval($row['course_category_id'])
            ];
        }
    }

    // Don't forget to add the last category
    if ($currentCategory !== null) {
        $categories[] = $currentCategory;
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'count' => count($categories)
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}

$conn->close();
