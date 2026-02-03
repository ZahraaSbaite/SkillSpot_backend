<?php
// internships.php - All internship endpoints with file upload support

// CRITICAL: Disable display_errors to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0);  // MUST be 0 for JSON responses
ini_set('log_errors', 1);      // Log errors instead of displaying them

// Clear any previous output
if (ob_get_level()) ob_end_clean();
ob_start();

// Set headers FIRST before any output
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    ob_end_clean(); // Clear any output
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/uploads/resumes/';
if (!file_exists($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// Get the action parameter
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Route to appropriate function
try {
    switch ($action) {
        case 'get_internships':
            getInternships($pdo);
            break;

        case 'get_my_internships':
            getMyInternships($pdo);
            break;

        case 'add_internship':
            addInternship($pdo);
            break;

        case 'apply_internship':
            applyInternship($pdo, $uploadDir);
            break;

        case 'get_applicants':
            getApplicants($pdo);
            break;

        case 'update_application_status':
            updateApplicationStatus($pdo);
            break;

        case 'get_applied_internships':
            getAppliedInternships($pdo);
            break;

        default:
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action parameter']);
            break;
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error occurred']);
}

// ==================== FUNCTIONS ====================

function getInternships($pdo)
{
    try {
        $sql = "SELECT i.*, 
                COALESCE((SELECT COUNT(*) FROM applications WHERE internship_id = i.id), 0) as applicants_count
                FROM internships i
                ORDER BY i.posted_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $internships = $stmt->fetchAll();

        // Format dates and ensure consistent data types
        foreach ($internships as &$internship) {
            $internship['posted_date'] = date('M d, Y', strtotime($internship['posted_date']));
            $internship['applicants_count'] = (int)$internship['applicants_count'];
            $internship['id'] = (int)$internship['id'];
            $internship['posted_by'] = (int)$internship['posted_by'];
        }

        ob_end_clean();
        http_response_code(200);
        echo json_encode($internships);
        exit();
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}

function getMyInternships($pdo)
{
    if (!isset($_GET['user_id'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Missing user_id parameter']);
        exit();
    }

    $userId = (int)$_GET['user_id'];

    try {
        $sql = "SELECT i.*, 
                COALESCE((SELECT COUNT(*) FROM applications WHERE internship_id = i.id), 0) as applicants_count
                FROM internships i
                WHERE i.posted_by = :user_id
                ORDER BY i.posted_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $internships = $stmt->fetchAll();

        // Format dates and ensure consistent data types
        foreach ($internships as &$internship) {
            $internship['posted_date'] = date('M d, Y', strtotime($internship['posted_date']));
            $internship['applicants_count'] = (int)$internship['applicants_count'];
            $internship['id'] = (int)$internship['id'];
            $internship['posted_by'] = (int)$internship['posted_by'];
        }

        ob_end_clean();
        http_response_code(200);
        echo json_encode($internships);
        exit();
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}

function addInternship($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['title']) || !isset($data['company']) || !isset($data['description']) ||
        !isset($data['location']) || !isset($data['duration']) || !isset($data['requirements']) ||
        !isset($data['posted_by'])
    ) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit();
    }

    try {
        $sql = "INSERT INTO internships 
                (title, company, description, location, duration, requirements, posted_by, posted_date) 
                VALUES 
                (:title, :company, :description, :location, :duration, :requirements, :posted_by, NOW())";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':title' => $data['title'],
            ':company' => $data['company'],
            ':description' => $data['description'],
            ':location' => $data['location'],
            ':duration' => $data['duration'],
            ':requirements' => $data['requirements'],
            ':posted_by' => (int)$data['posted_by']
        ]);

        ob_end_clean();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'id' => (int)$pdo->lastInsertId()
        ]);
        exit();
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}

function applyInternship($pdo, $uploadDir)
{
    // Validate required POST fields
    if (
        !isset($_POST['internship_id']) || !isset($_POST['user_id']) || !isset($_POST['name']) ||
        !isset($_POST['email']) || !isset($_POST['phone']) || !isset($_POST['cover_letter'])
    ) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit();
    }

    // Check if user is trying to apply to their own internship
    try {
        $checkOwnerSql = "SELECT posted_by FROM internships WHERE id = :internship_id";
        $checkOwnerStmt = $pdo->prepare($checkOwnerSql);
        $checkOwnerStmt->execute([':internship_id' => (int)$_POST['internship_id']]);
        $internship = $checkOwnerStmt->fetch();

        if ($internship && (int)$internship['posted_by'] === (int)$_POST['user_id']) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'You cannot apply to your own internship']);
            exit();
        }
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }

    // Validate file upload
    if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Resume file is required']);
        exit();
    }

    $file = $_FILES['resume'];

    // Validate file size (5MB max)
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxFileSize) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'File size must be less than 5MB']);
        exit();
    }

    // Validate file type
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Only PDF, DOC, and DOCX files are allowed']);
            exit();
        }
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('resume_') . '_' . time() . '.' . $extension;
    $filePath = $uploadDir . $fileName;

    // Move uploaded file
    if (!@move_uploaded_file($file['tmp_name'], $filePath)) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to upload file']);
        exit();
    }

    try {
        // Check if user already applied
        $checkSql = "SELECT id FROM applications 
                     WHERE internship_id = :internship_id AND user_id = :user_id";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([
            ':internship_id' => (int)$_POST['internship_id'],
            ':user_id' => (int)$_POST['user_id']
        ]);

        if ($checkStmt->fetch()) {
            // Delete uploaded file if user already applied
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            ob_end_clean();
            http_response_code(409);
            echo json_encode(['error' => 'You have already applied for this internship']);
            exit();
        }

        // Insert application
        $sql = "INSERT INTO applications 
                (internship_id, user_id, name, email, phone, resume, cover_letter, applied_date, status) 
                VALUES 
                (:internship_id, :user_id, :name, :email, :phone, :resume, :cover_letter, NOW(), 'pending')";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':internship_id' => (int)$_POST['internship_id'],
            ':user_id' => (int)$_POST['user_id'],
            ':name' => $_POST['name'],
            ':email' => $_POST['email'],
            ':phone' => $_POST['phone'],
            ':resume' => $fileName, // Store only the filename
            ':cover_letter' => $_POST['cover_letter']
        ]);

        ob_end_clean();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'id' => (int)$pdo->lastInsertId()
        ]);
        exit();
    } catch (PDOException $e) {
        // Delete uploaded file on database error
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}

function getApplicants($pdo)
{
    if (!isset($_GET['internship_id'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Missing internship_id parameter']);
        exit();
    }

    $internshipId = (int)$_GET['internship_id'];

    try {
        $sql = "SELECT a.*, 
                CONCAT('http://127.0.0.1/flutter_backend/uploads/resumes/', a.resume) as resume_url
                FROM applications a
                WHERE a.internship_id = :internship_id 
                ORDER BY a.applied_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':internship_id' => $internshipId]);

        $applicants = $stmt->fetchAll();

        // Format dates and ensure consistent data types
        foreach ($applicants as &$applicant) {
            $applicant['applied_date'] = date('M d, Y', strtotime($applicant['applied_date']));
            $applicant['id'] = (int)$applicant['id'];
            $applicant['internship_id'] = (int)$applicant['internship_id'];
            $applicant['user_id'] = (int)$applicant['user_id'];
        }

        ob_end_clean();
        http_response_code(200);
        echo json_encode($applicants);
        exit();
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}

function updateApplicationStatus($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['application_id']) || !isset($data['status'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit();
    }

    // Validate status
    $validStatuses = ['pending', 'accepted', 'rejected'];
    if (!in_array(strtolower($data['status']), $validStatuses)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status value']);
        exit();
    }

    try {
        $sql = "UPDATE applications 
                SET status = :status 
                WHERE id = :application_id";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':status' => strtolower($data['status']),
            ':application_id' => (int)$data['application_id']
        ]);

        if ($stmt->rowCount() === 0) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Application not found']);
            exit();
        }

        ob_end_clean();
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit();
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}

function getAppliedInternships($pdo)
{
    if (!isset($_GET['user_id'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Missing user_id parameter']);
        exit();
    }

    $userId = (int)$_GET['user_id'];

    try {
        $sql = "SELECT i.*, a.status, a.id as application_id, a.applied_date
                FROM internships i
                INNER JOIN applications a ON i.id = a.internship_id
                WHERE a.user_id = :user_id
                ORDER BY a.applied_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $internships = $stmt->fetchAll();

        // Format dates and ensure consistent data types
        foreach ($internships as &$internship) {
            $internship['posted_date'] = date('M d, Y', strtotime($internship['posted_date']));
            $internship['applied_date'] = date('M d, Y', strtotime($internship['applied_date']));
            $internship['id'] = (int)$internship['id'];
            $internship['posted_by'] = (int)$internship['posted_by'];
            $internship['application_id'] = (int)$internship['application_id'];
        }

        ob_end_clean();
        http_response_code(200);
        echo json_encode($internships);
        exit();
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit();
    }
}
