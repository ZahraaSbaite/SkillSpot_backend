<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'capstone';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = $_GET['action'] ?? '';

// Helper function to update skill statuses based on dates
function updateSkillStatusesByDate($pdo, $user_id = null)
{
    try {
        $current_date = date('Y-m-d');

        // Update to 'in_progress' if start_date is today or has passed and status is 'enrolled'
        $sql_progress = "
            UPDATE user_learnings 
            SET status = 'in_progress', updated_at = NOW() 
            WHERE status = 'enrolled' 
            AND start_date <= ?
            AND (completion_date IS NULL OR completion_date > ?)
        ";
        if ($user_id) {
            $sql_progress .= " AND user_id = ?";
            $stmt = $pdo->prepare($sql_progress);
            $stmt->execute([$current_date, $current_date, $user_id]);
        } else {
            $stmt = $pdo->prepare($sql_progress);
            $stmt->execute([$current_date, $current_date]);
        }

        // 🔧 FIXED: Update to 'completed' if completion_date has passed
        // Automatically set progress to 100% and mark as completed when end date passes
        $sql_complete = "
            UPDATE user_learnings 
            SET status = 'completed', 
                progress_percentage = 100,
                updated_at = NOW() 
            WHERE status IN ('enrolled', 'in_progress')
            AND completion_date IS NOT NULL
            AND completion_date <= ?
        ";
        if ($user_id) {
            $sql_complete .= " AND user_id = ?";
            $stmt = $pdo->prepare($sql_complete);
            $stmt->execute([$current_date, $user_id]);
        } else {
            $stmt = $pdo->prepare($sql_complete);
            $stmt->execute([$current_date]);
        }

        return true;
    } catch (PDOException $e) {
        error_log("Error updating statuses: " . $e->getMessage());
        return false;
    }
}

// Shared helper to format skill data (without certificate)
function formatSkillForLearning($row)
{
    return [
        'id' => strval($row['id']),
        'user_id' => strval($row['user_id']),
        'name' => $row['name'],
        'description' => $row['description'],
        'level' => $row['level'],
        'coins' => $row['coins'] !== null ? (int)$row['coins'] : null,
        'duration_hours' => $row['duration_hours'] !== null ? (int)$row['duration_hours'] : null,
        'duration_days' => $row['duration_days'] !== null ? (int)$row['duration_days'] : null,
        'course_id' => $row['course_code'],
        'course_code' => $row['course_code'],
        'course_name' => $row['course_name'],
        'category_id' => $row['category_id'] !== null ? strval($row['category_id']) : null,
        'category_name' => $row['category_name'],
        'provider_name' => $row['provider_name'],
        'provider_email' => $row['provider_email'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'progress_percentage' => $row['progress_percentage'],
        // No certificate_url
    ];
}

// Get enrolled skills
if ($action === 'get_enrolled') {
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }

    updateSkillStatusesByDate($pdo, $user_id);

    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.user_id,
                s.name,
                s.description,
                s.level,
                s.coins,
                s.duration_hours,
                s.duration_days,
                s.course_code,
                s.category_id,
                cat.name as category_name,
                co.title as course_name,
                u.username as provider_name,
                u.email as provider_email,
                ul.start_date,
                ul.completion_date as end_date,
                ul.enrollment_date,
                ul.progress_percentage,
                ul.status,
                ul.last_accessed,
                ul.notes
            FROM user_learnings ul
            JOIN skills s ON ul.skill_id = s.id
            LEFT JOIN categories cat ON s.category_id = cat.id
            LEFT JOIN courses co ON s.course_code = co.code
            LEFT JOIN users u ON s.user_id = u.id
            WHERE ul.user_id = ? AND ul.status = 'enrolled'
            ORDER BY ul.enrollment_date DESC
        ");
        $stmt->execute([$user_id]);
        $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedSkills = array_map('formatSkillForLearning', $skills);

        echo json_encode(['success' => true, 'skills' => $formattedSkills]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Get in-progress skills
if ($action === 'get_in_progress') {
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }

    updateSkillStatusesByDate($pdo, $user_id);

    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.user_id,
                s.name,
                s.description,
                s.level,
                s.coins,
                s.duration_hours,
                s.duration_days,
                s.course_code,
                s.category_id,
                cat.name as category_name,
                co.title as course_name,
                u.username as provider_name,
                u.email as provider_email,
                ul.start_date,
                ul.completion_date as end_date,
                ul.enrollment_date,
                ul.progress_percentage,
                ul.status,
                ul.last_accessed,
                ul.notes
            FROM user_learnings ul
            JOIN skills s ON ul.skill_id = s.id
            LEFT JOIN categories cat ON s.category_id = cat.id
            LEFT JOIN courses co ON s.course_code = co.code
            LEFT JOIN users u ON s.user_id = u.id
            WHERE ul.user_id = ? AND ul.status = 'in_progress'
            ORDER BY ul.last_accessed DESC
        ");
        $stmt->execute([$user_id]);
        $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedSkills = array_map('formatSkillForLearning', $skills);

        echo json_encode(['success' => true, 'skills' => $formattedSkills]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Get completed skills
if ($action === 'get_completed') {
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }

    updateSkillStatusesByDate($pdo, $user_id);

    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.user_id,
                s.name,
                s.description,
                s.level,
                s.coins,
                s.duration_hours,
                s.duration_days,
                s.course_code,
                s.category_id,
                cat.name as category_name,
                co.title as course_name,
                u.username as provider_name,
                u.email as provider_email,
                ul.start_date,
                ul.completion_date as end_date,
                ul.enrollment_date,
                ul.progress_percentage,
                ul.status,
                ul.last_accessed,
                ul.notes
            FROM user_learnings ul
            JOIN skills s ON ul.skill_id = s.id
            LEFT JOIN categories cat ON s.category_id = cat.id
            LEFT JOIN courses co ON s.course_code = co.code
            LEFT JOIN users u ON s.user_id = u.id
            WHERE ul.user_id = ? AND ul.status = 'completed'
            ORDER BY ul.completion_date DESC
        ");
        $stmt->execute([$user_id]);
        $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedSkills = array_map('formatSkillForLearning', $skills);

        echo json_encode(['success' => true, 'skills' => $formattedSkills]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Update status manually
if ($action === 'update_status') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $data['user_id'] ?? null;
    $skill_id = $data['skill_id'] ?? null;
    $status = $data['status'] ?? null;

    if (!$user_id || !$skill_id || !$status || !in_array($status, ['enrolled', 'in_progress', 'completed'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE user_learnings SET status = ?, updated_at = NOW() WHERE user_id = ? AND skill_id = ?");
        $stmt->execute([$status, $user_id, $skill_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? 'Updated' : 'Not found']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Update progress
if ($action === 'update_progress') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $data['user_id'] ?? null;
    $skill_id = $data['skill_id'] ?? null;
    $progress = $data['progress_percentage'] ?? null;

    if (!$user_id || !$skill_id || $progress === null || $progress < 0 || $progress > 100) {
        echo json_encode(['success' => false, 'message' => 'Invalid progress']);
        exit();
    }

    $current_date = date('Y-m-d');

    try {
        $stmt = $pdo->prepare("SELECT start_date, completion_date, status FROM user_learnings WHERE user_id = ? AND skill_id = ?");
        $stmt->execute([$user_id, $skill_id]);
        $learning = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$learning) {
            echo json_encode(['success' => false, 'message' => 'Learning record not found']);
            exit();
        }

        $newStatus = $learning['status'];

        if ($progress == 100 && $learning['completion_date'] && $learning['completion_date'] <= $current_date) {
            $newStatus = 'completed';
        } elseif ($progress > 0 && $learning['start_date'] && $learning['start_date'] <= $current_date) {
            $newStatus = 'in_progress';
        } elseif ($progress == 0) {
            $newStatus = 'enrolled';
        }

        $stmt = $pdo->prepare("
            UPDATE user_learnings 
            SET progress_percentage = ?, status = ?, last_accessed = NOW(), updated_at = NOW() 
            WHERE user_id = ? AND skill_id = ?
        ");
        $stmt->execute([$progress, $newStatus, $user_id, $skill_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'new_status' => $newStatus]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Get all (debug)
if ($action === 'get_all') {
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }

    updateSkillStatusesByDate($pdo, $user_id);

    try {
        $stmt = $pdo->prepare("
            SELECT 
                ul.*,
                s.id as skill_id,
                s.user_id as skill_owner_id,
                s.name as skill_name,
                s.description,
                s.level,
                s.coins,
                s.duration_hours,
                s.duration_days,
                s.course_code,
                s.category_id,
                cat.name as category_name,
                co.title as course_name,
                provider.username as provider_name,
                provider.email as provider_email
            FROM user_learnings ul
            JOIN skills s ON ul.skill_id = s.id
            LEFT JOIN categories cat ON s.category_id = cat.id
            LEFT JOIN courses co ON s.course_code = co.code
            LEFT JOIN users provider ON s.user_id = provider.id
            WHERE ul.user_id = ?
            ORDER BY ul.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $learnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedLearnings = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'skill_id' => strval($row['skill_id']),
                'skill' => [
                    'id' => strval($row['skill_id']),
                    'user_id' => strval($row['skill_owner_id']),
                    'name' => $row['skill_name'],
                    'description' => $row['description'],
                    'level' => $row['level'],
                    'coins' => $row['coins'] !== null ? (int)$row['coins'] : null,
                    'duration_hours' => $row['duration_hours'] !== null ? (int)$row['duration_hours'] : null,
                    'duration_days' => $row['duration_days'] !== null ? (int)$row['duration_days'] : null,
                    'course_id' => $row['course_code'],
                    'course_code' => $row['course_code'],
                    'course_name' => $row['course_name'],
                    'category_id' => $row['category_id'] !== null ? strval($row['category_id']) : null,
                    'category_name' => $row['category_name'],
                    'provider_name' => $row['provider_name'],
                    'provider_email' => $row['provider_email']
                ],
                'start_date' => $row['start_date'],
                'end_date' => $row['completion_date'],
                'enrollment_date' => $row['enrollment_date'],
                'progress_percentage' => $row['progress_percentage'],
                'status' => $row['status'],
                'last_accessed' => $row['last_accessed'],
                'notes' => $row['notes']
                // No certificate_url
            ];
        }, $learnings);

        echo json_encode(['success' => true, 'learnings' => $formattedLearnings]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
