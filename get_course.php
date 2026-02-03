<?php
// cors.php - Include this at the top of your PHP files
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
include 'db.php';

$sql = "SELECT code, title, description, category_id FROM courses ORDER BY code";
$result = $conn->query($sql);

$courses = [];
while ($row = $result->fetch_assoc()) {
    $courses[] = [
        "code" => $row['code'],
        "title" => $row['title'],
        "description" => $row['description'],
        "category_id" => $row['category_id'] // to link with categories if needed
    ];
}

echo json_encode($courses, JSON_UNESCAPED_UNICODE);
$conn->close();
