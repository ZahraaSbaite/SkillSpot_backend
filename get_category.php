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

$sql = "SELECT id, name FROM categories ORDER BY id";
$result = $conn->query($sql);

$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = [
        "id" => $row['id'],
        "name" => $row['name']
    ];
}

echo json_encode($categories, JSON_UNESCAPED_UNICODE);
$conn->close();
