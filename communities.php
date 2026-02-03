<?php
// 🛡️ Handle CORS preflight FIRST — before ANY output or errors
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    exit(0);
}

// Prevent default PHP errors from breaking headers
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// Set headers for actual requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

require_once 'db.php';

// Handle file upload separately (multipart/form-data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if (!isset($_POST['action']) || $_POST['action'] !== 'upload_resource') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid upload action']);
        exit;
    }

    $communityId = intval($_POST['community_id'] ?? 0);
    $userId = intval($_POST['user_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');

    if ($communityId <= 0 || $userId <= 0 || empty($title)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing community ID, user ID, or title']);
        exit;
    }

    $file = $_FILES['file'];
    $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: pdf, doc, docx, jpg, png']);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File upload error']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newFilename = uniqid('res_') . '.' . $ext;
    $destPath = $uploadDir . $newFilename;
    $relativePath = 'uploads/' . $newFilename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    $sql = "INSERT INTO community_resources (community_id, user_id, title, type, file_path) 
            VALUES (?, ?, ?, 'document', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $communityId, $userId, $title, $relativePath);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Resource uploaded successfully',
            'resource_id' => $conn->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// Main logic for JSON-based actions
$action = isset($_GET['action']) ? $_GET['action'] : 'get_all';

try {
    switch ($action) {

        // ==================== GET ALL COMMUNITIES ====================
        case 'get_all':
            $sql = "SELECT 
                        c.id,
                        c.name,
                        c.description,
                        c.category,
                        c.category_id,
                        c.course_code,
                        c.course_name,
                        c.level,
                        c.start_date,
                        c.end_date,
                        c.start_time,
                        c.end_time,
                        c.activities,
                        c.outcome,
                        c.created_by,
                        u.name as created_by_name,
                        c.created_at,
                        COUNT(DISTINCT cm.user_id) as member_count
                    FROM communities c
                    LEFT JOIN users u ON c.created_by = u.id
                    LEFT JOIN community_members cm ON c.id = cm.community_id
                    GROUP BY c.id
                    ORDER BY c.created_at DESC";

            $result = $conn->query($sql);
            $communities = [];
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $row['id'] = (int)$row['id'];
                    $row['created_by'] = (int)$row['created_by'];
                    $row['member_count'] = (int)$row['member_count'];
                    $communities[] = $row;
                }
            }

            echo json_encode([
                'success' => true,
                'communities' => $communities,
                'count' => count($communities)
            ]);
            break;

        // ==================== GET USER'S COMMUNITIES (JOINED) ====================
        case 'get_user_communities':
            $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
            if ($userId <= 0) throw new Exception('Invalid user ID');

            $sql = "SELECT 
                        c.id,
                        c.name,
                        c.description,
                        c.category,
                        c.category_id,
                        c.course_code,
                        c.course_name,
                        c.level,
                        c.start_date,
                        c.end_date,
                        c.start_time,
                        c.end_time,
                        c.activities,
                        c.outcome,
                        c.created_by,
                        u.name as created_by_name,
                        c.created_at,
                        COUNT(DISTINCT cm2.user_id) as member_count
                    FROM community_members cm
                    INNER JOIN communities c ON cm.community_id = c.id
                    LEFT JOIN users u ON c.created_by = u.id
                    LEFT JOIN community_members cm2 ON c.id = cm2.community_id
                    WHERE cm.user_id = ? AND c.created_by != ?
                    GROUP BY c.id
                    ORDER BY cm.joined_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $userId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            $communities = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['created_by'] = (int)$row['created_by'];
                $row['member_count'] = (int)$row['member_count'];
                $communities[] = $row;
            }

            echo json_encode([
                'success' => true,
                'communities' => $communities,
                'count' => count($communities)
            ]);
            $stmt->close();
            break;

        // ==================== GET CREATED COMMUNITIES ====================
        case 'get_created_communities':
            $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
            if ($userId <= 0) throw new Exception('Invalid user ID');

            $sql = "SELECT 
                        c.id,
                        c.name,
                        c.description,
                        c.category,
                        c.category_id,
                        c.course_code,
                        c.course_name,
                        c.level,
                        c.start_date,
                        c.end_date,
                        c.start_time,
                        c.end_time,
                        c.activities,
                        c.outcome,
                        c.created_by,
                        u.name as created_by_name,
                        c.created_at,
                        COUNT(DISTINCT cm.user_id) as member_count
                    FROM communities c
                    LEFT JOIN users u ON c.created_by = u.id
                    LEFT JOIN community_members cm ON c.id = cm.community_id
                    WHERE c.created_by = ?
                    GROUP BY c.id
                    ORDER BY c.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            $communities = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['created_by'] = (int)$row['created_by'];
                $row['member_count'] = (int)$row['member_count'];
                $communities[] = $row;
            }

            echo json_encode([
                'success' => true,
                'communities' => $communities,
                'count' => count($communities)
            ]);
            $stmt->close();
            break;

        // ==================== GET SINGLE COMMUNITY BY ID (NEWLY ADDED) ====================
        case 'get_by_id':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id <= 0) throw new Exception('Community ID required');

            $sql = "SELECT 
                        c.id,
                        c.name,
                        c.description,
                        c.category,
                        c.category_id,
                        c.course_code,
                        c.course_name,
                        c.level,
                        c.start_date,
                        c.end_date,
                        c.start_time,
                        c.end_time,
                        c.activities,
                        c.outcome,
                        c.created_by,
                        u.name as created_by_name,
                        c.created_at,
                        COUNT(DISTINCT cm.user_id) as member_count
                    FROM communities c
                    LEFT JOIN users u ON c.created_by = u.id
                    LEFT JOIN community_members cm ON c.id = cm.community_id
                    WHERE c.id = ?
                    GROUP BY c.id";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                // Cast numeric fields
                $row['id'] = (int)$row['id'];
                $row['created_by'] = (int)$row['created_by'];
                $row['member_count'] = (int)$row['member_count'];

                echo json_encode([
                    'success' => true,
                    'community' => $row
                ]);
            } else {
                throw new Exception('Community not found');
            }
            $stmt->close();
            break;

        // ==================== GET SINGLE COMMUNITY (LEGACY - KEPT FOR BACKWARD COMPATIBILITY) ====================
        case 'get_community':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id <= 0) throw new Exception('Invalid community ID');

            $stmt = $conn->prepare("SELECT * FROM communities WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $comm = $result->fetch_assoc();

            if (!$comm) {
                throw new Exception('Community not found');
            }

            // Cast numeric fields
            $comm['id'] = (int)$comm['id'];
            $comm['created_by'] = (int)$comm['created_by'];

            echo json_encode(['success' => true, 'community' => $comm]);
            $stmt->close();
            break;

        // ==================== CREATE COMMUNITY ====================
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $category = trim($data['category'] ?? 'General');
            $categoryId = trim($data['category_id'] ?? '');
            $courseCode = trim($data['course_code'] ?? '');
            $courseName = trim($data['course_name'] ?? '');
            $level = trim($data['level'] ?? 'Beginner');
            $startDate = trim($data['start_date'] ?? '');
            $endDate = trim($data['end_date'] ?? '');
            $startTime = trim($data['start_time'] ?? '');
            $endTime = trim($data['end_time'] ?? '');
            $activities = trim($data['activities'] ?? '');
            $outcome = trim($data['outcome'] ?? '');
            $createdBy = intval($data['created_by'] ?? 0);

            if (empty($name)) throw new Exception('Community name is required');
            if ($createdBy <= 0) throw new Exception('Invalid creator ID');
            if (empty($startDate) || empty($endDate)) throw new Exception('Start and end dates are required');

            $validLevels = ['Beginner', 'Intermediate', 'Advanced', 'All Levels'];
            if (!in_array($level, $validLevels)) $level = 'Beginner';

            $checkStmt = $conn->prepare("SELECT id FROM communities WHERE name = ?");
            $checkStmt->bind_param("s", $name);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('A community with this name already exists');
            }
            $checkStmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO communities (
                    name, description, category, category_id, course_code, course_name,
                    level, start_date, end_date, start_time, end_time, activities, outcome,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param(
                "sssssssssssssi",
                $name,
                $description,
                $category,
                $categoryId,
                $courseCode,
                $courseName,
                $level,
                $startDate,
                $endDate,
                $startTime,
                $endTime,
                $activities,
                $outcome,
                $createdBy
            );

            if ($stmt->execute()) {
                $communityId = $conn->insert_id;
                $memStmt = $conn->prepare("INSERT INTO community_members (community_id, user_id, joined_at) VALUES (?, ?, NOW())");
                $memStmt->bind_param("ii", $communityId, $createdBy);
                $memStmt->execute();
                $memStmt->close();

                echo json_encode([
                    'success' => true,
                    'message' => 'Community created successfully',
                    'community_id' => $communityId
                ]);
            } else {
                throw new Exception('Failed to create community');
            }
            $stmt->close();
            break;

        // ==================== UPDATE COMMUNITY ====================
        case 'update_community':
            $data = json_decode(file_get_contents('php://input'), true);

            // Validate input
            $communityId = intval($data['community_id'] ?? 0);
            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $level = trim($data['level'] ?? 'Beginner');
            $startDate = trim($data['start_date'] ?? '');
            $endDate = trim($data['end_date'] ?? '');
            $startTime = trim($data['start_time'] ?? '');
            $endTime = trim($data['end_time'] ?? '');
            $activities = trim($data['activities'] ?? '');
            $outcome = trim($data['outcome'] ?? '');

            if ($communityId <= 0 || empty($name) || empty($description)) {
                throw new Exception('Missing required fields');
            }

            $validLevels = ['Beginner', 'Intermediate', 'Advanced', 'All Levels'];
            if (!in_array($level, $validLevels)) {
                $level = 'Beginner';
            }

            // Optional: Add ownership validation here if needed

            $stmt = $conn->prepare(
                "UPDATE communities SET 
                    name = ?,
                    description = ?,
                    level = ?,
                    start_date = ?,
                    end_date = ?,
                    start_time = ?,
                    end_time = ?,
                    activities = ?,
                    outcome = ?
                WHERE id = ?"
            );

            $stmt->bind_param(
                "sssssssssi",
                $name,
                $description,
                $level,
                $startDate,
                $endDate,
                $startTime,
                $endTime,
                $activities,
                $outcome,
                $communityId
            );

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Community updated successfully'
                ]);
            } else {
                throw new Exception('Failed to update community: ' . $stmt->error);
            }
            $stmt->close();
            break;

        // ==================== JOIN COMMUNITY WITH COIN REWARD ====================
        case 'join':
            $data = json_decode(file_get_contents('php://input'), true);
            $userId = intval($data['user_id'] ?? 0);
            $communityId = intval($data['community_id'] ?? 0);

            if ($userId <= 0 || $communityId <= 0) {
                throw new Exception('Invalid user ID or community ID');
            }

            // Get community and creator info
            $checkStmt = $conn->prepare("SELECT created_by FROM communities WHERE id = ?");
            $checkStmt->bind_param("i", $communityId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows == 0) {
                $checkStmt->close();
                throw new Exception('Community not found');
            }

            $community = $result->fetch_assoc();
            $creatorId = intval($community['created_by']);
            $checkStmt->close();

            // Prevent self-join
            if ($userId == $creatorId) {
                throw new Exception('You cannot join your own community');
            }

            // Check existing membership
            $memberCheck = $conn->prepare("SELECT id FROM community_members WHERE community_id = ? AND user_id = ?");
            $memberCheck->bind_param("ii", $communityId, $userId);
            $memberCheck->execute();

            if ($memberCheck->get_result()->num_rows > 0) {
                $memberCheck->close();
                throw new Exception('You are already a member of this community');
            }
            $memberCheck->close();

            // Start transaction
            $conn->begin_transaction();

            try {
                // Add member
                $joinStmt = $conn->prepare("INSERT INTO community_members (community_id, user_id, joined_at) VALUES (?, ?, NOW())");
                $joinStmt->bind_param("ii", $communityId, $userId);

                if (!$joinStmt->execute()) {
                    throw new Exception('Failed to join community');
                }
                $joinStmt->close();

                // Award coins to creator
                $coinsStmt = $conn->prepare("UPDATE users SET coins = COALESCE(coins, 0) + 5 WHERE id = ?");
                $coinsStmt->bind_param("i", $creatorId);

                if (!$coinsStmt->execute()) {
                    throw new Exception('Failed to award coins');
                }
                $coinsStmt->close();

                // Commit
                $conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Successfully joined community! Creator awarded 5 coins.'
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        // ==================== LEAVE COMMUNITY ====================
        case 'leave':
            $data = json_decode(file_get_contents('php://input'), true);
            $userId = intval($data['user_id'] ?? 0);
            $communityId = intval($data['community_id'] ?? 0);

            if ($userId <= 0 || $communityId <= 0) {
                throw new Exception('Invalid IDs');
            }

            $stmt = $conn->prepare("DELETE FROM community_members WHERE community_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $communityId, $userId);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Left successfully']);
            } else {
                throw new Exception('Not a member or failed to leave');
            }
            $stmt->close();
            break;

        // ==================== GET COMMENTS ====================
        case 'get_comments':
            $communityId = isset($_GET['community_id']) ? intval($_GET['community_id']) : 0;
            if ($communityId <= 0) throw new Exception('Invalid community ID');

            $sql = "SELECT 
                        cc.id,
                        cc.user_id,
                        u.name as user_name,
                        cc.content,
                        cc.created_at
                    FROM community_comments cc
                    JOIN users u ON cc.user_id = u.id
                    WHERE cc.community_id = ?
                    ORDER BY cc.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $communityId);
            $stmt->execute();
            $result = $stmt->get_result();

            $comments = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['user_id'] = (int)$row['user_id'];
                $comments[] = $row;
            }

            echo json_encode(['success' => true, 'comments' => $comments]);
            $stmt->close();
            break;

        // ==================== POST COMMENT ====================
        case 'post_comment':
            $data = json_decode(file_get_contents('php://input'), true);
            $communityId = intval($data['community_id'] ?? 0);
            $userId = intval($data['user_id'] ?? 0);
            $content = trim($data['content'] ?? '');

            if ($communityId <= 0 || $userId <= 0 || empty($content)) {
                throw new Exception('Missing required fields');
            }

            $stmt = $conn->prepare("INSERT INTO community_comments (community_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iis", $communityId, $userId, $content);

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Comment posted',
                    'comment_id' => $conn->insert_id
                ]);
            } else {
                throw new Exception('Failed to post comment');
            }
            $stmt->close();
            break;

        // ==================== GET RESOURCES ====================
        case 'get_resources':
            $communityId = isset($_GET['community_id']) ? intval($_GET['community_id']) : 0;
            if ($communityId <= 0) throw new Exception('Invalid community ID');

            $sql = "SELECT 
                        cr.id,
                        cr.user_id,
                        u.name as user_name,
                        cr.title,
                        cr.type,
                        cr.file_path,
                        cr.url,
                        cr.uploaded_at
                    FROM community_resources cr
                    JOIN users u ON cr.user_id = u.id
                    WHERE cr.community_id = ?
                    ORDER BY cr.uploaded_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $communityId);
            $stmt->execute();
            $result = $stmt->get_result();

            $resources = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['user_id'] = (int)$row['user_id'];
                $resources[] = $row;
            }

            echo json_encode(['success' => true, 'resources' => $resources]);
            $stmt->close();
            break;

        // ==================== DELETE COMMENT ====================
        case 'delete_comment':
            $data = json_decode(file_get_contents('php://input'), true);
            $commentId = intval($data['comment_id'] ?? 0);
            $userId = intval($data['user_id'] ?? 0);

            if ($commentId <= 0 || $userId <= 0) {
                throw new Exception('Invalid IDs');
            }

            // Check ownership
            $checkStmt = $conn->prepare("SELECT user_id FROM community_comments WHERE id = ?");
            $checkStmt->bind_param("i", $commentId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Comment not found');
            }

            $comment = $result->fetch_assoc();
            if ($comment['user_id'] != $userId) {
                throw new Exception('You can only delete your own comments');
            }
            $checkStmt->close();

            // Delete
            $stmt = $conn->prepare("DELETE FROM community_comments WHERE id = ?");
            $stmt->bind_param("i", $commentId);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Comment deleted']);
            } else {
                throw new Exception('Failed to delete comment');
            }
            $stmt->close();
            break;

        // ==================== EDIT COMMENT ====================
        case 'edit_comment':
            $data = json_decode(file_get_contents('php://input'), true);
            $commentId = intval($data['comment_id'] ?? 0);
            $userId = intval($data['user_id'] ?? 0);
            $content = trim($data['content'] ?? '');

            if ($commentId <= 0 || $userId <= 0 || empty($content)) {
                throw new Exception('Missing required fields');
            }

            // Check ownership
            $checkStmt = $conn->prepare("SELECT user_id FROM community_comments WHERE id = ?");
            $checkStmt->bind_param("i", $commentId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Comment not found');
            }

            $comment = $result->fetch_assoc();
            if ($comment['user_id'] != $userId) {
                throw new Exception('You can only edit your own comments');
            }
            $checkStmt->close();

            // Update
            $stmt = $conn->prepare("UPDATE community_comments SET content = ? WHERE id = ?");
            $stmt->bind_param("si", $content, $commentId);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Comment updated']);
            } else {
                throw new Exception('Failed to update comment');
            }
            $stmt->close();
            break;

        // ==================== DELETE RESOURCE ====================
        case 'delete_resource':
            $data = json_decode(file_get_contents('php://input'), true);
            $resourceId = intval($data['resource_id'] ?? 0);
            $userId = intval($data['user_id'] ?? 0);

            if ($resourceId <= 0 || $userId <= 0) {
                throw new Exception('Invalid IDs');
            }

            // Check ownership
            $checkStmt = $conn->prepare("SELECT user_id, type, file_path FROM community_resources WHERE id = ?");
            $checkStmt->bind_param("i", $resourceId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Resource not found');
            }

            $resource = $result->fetch_assoc();
            if ($resource['user_id'] != $userId) {
                throw new Exception('You can only delete your own resources');
            }
            $checkStmt->close();

            // Delete file if it's a document
            if ($resource['type'] == 'document' && !empty($resource['file_path'])) {
                $filePath = __DIR__ . '/' . $resource['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Delete from database
            $stmt = $conn->prepare("DELETE FROM community_resources WHERE id = ?");
            $stmt->bind_param("i", $resourceId);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Resource deleted']);
            } else {
                throw new Exception('Failed to delete resource');
            }
            $stmt->close();
            break;

        // ==================== SHARE LINK ====================
        case 'share_link':
            $data = json_decode(file_get_contents('php://input'), true);
            $communityId = intval($data['community_id'] ?? 0);
            $userId = intval($data['user_id'] ?? 0);
            $title = trim($data['title'] ?? '');
            $url = trim($data['url'] ?? '');

            if ($communityId <= 0 || $userId <= 0 || empty($title) || empty($url)) {
                throw new Exception('Title and URL are required');
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid URL');
            }

            $stmt = $conn->prepare("INSERT INTO community_resources (community_id, user_id, title, type, url, uploaded_at) VALUES (?, ?, ?, 'link', ?, NOW())");
            $stmt->bind_param("iiss", $communityId, $userId, $title, $url);

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Link shared',
                    'resource_id' => $conn->insert_id
                ]);
            } else {
                throw new Exception('Failed to share link');
            }
            $stmt->close();
            break;

        default:
            throw new Exception('Invalid action');
    }

    $conn->close();
} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
