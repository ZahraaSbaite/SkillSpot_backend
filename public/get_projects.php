<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connection.php';

// Get path_id from query parameters
$path_id = isset($_GET['path_id']) ? intval($_GET['path_id']) : 0;

if ($path_id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid Path ID"
    ]);
    exit;
}

try {
    // Fetch all projects for the given path_id
    // Note: Your table has 'name' and 'level' instead of 'title' and 'difficulty_level'
    // And no 'live_demo' or 'created_at' columns
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
            WHERE path_id = ?
            ORDER BY 
                CASE level
                    WHEN 'Beginner' THEN 1
                    WHEN 'Intermediate' THEN 2
                    WHEN 'Advanced' THEN 3
                    ELSE 4
                END,
                id ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }

    $stmt->bind_param("i", $path_id);

    if (!$stmt->execute()) {
        throw new Exception("Failed to execute statement: " . $stmt->error);
    }

    $result = $stmt->get_result();

    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $projects[] = [
            'id' => (int)$row['id'],
            'path_id' => (int)$row['path_id'],
            'name' => $row['name'],
            'level' => $row['level'],
            'description' => $row['description'],
            'technologies' => $row['technologies'], // JSON string
            'github_url' => $row['github_url'] ?? '',
            'estimated_hours' => (int)($row['estimated_hours'] ?? 0)
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        "status" => "success",
        "data" => $projects,
        "count" => count($projects)
    ]);
} catch (Exception $e) {
    error_log("Error in get_projects.php: " . $e->getMessage());

    echo json_encode([
        "status" => "error",
        "message" => "Server error: " . $e->getMessage()
    ]);
}
