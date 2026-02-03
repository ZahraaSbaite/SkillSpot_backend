<?php

/**
 * ============================================================================
 * CERTIFICATE GENERATION SYSTEM - COMPLETE VERSION
 * ============================================================================
 * 
 * Complete certificate generation system for Flutter app
 * 
 * Features:
 * - Generate PDF certificates with professional design
 * - Retrieve certificate details
 * - Check certificate existence
 * - Get user certificates list
 * - Delete certificates
 * - Automatic directory creation
 * - Comprehensive error handling
 * 
 * Requirements:
 * - PHP 7.4+
 * - MySQL database
 * - FPDF library in fpdf/ folder
 * - Write permissions on certificates/ folder
 * 
 * ============================================================================
 */

// ============================================================================
// CONFIGURATION
// ============================================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'capstone');
define('DB_USER', 'root');
define('DB_PASS', '');

define('UPLOAD_DIR', __DIR__ . '/certificates/');
define('FPDF_PATH', __DIR__ . '/fpdf/fpdf.php');
define('BASE_URL', 'http://localhost/flutter_backend/');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================
function getDatabaseConnection()
{
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        sendResponse(false, [
            'message' => 'Database connection failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

$pdo = getDatabaseConnection();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Send JSON response
 */
function sendResponse($success, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data));
    exit();
}

/**
 * Get input data
 */
function getInput()
{
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

/**
 * Ensure upload directory exists
 */
function ensureUploadDirectory()
{
    if (!file_exists(UPLOAD_DIR)) {
        if (!mkdir(UPLOAD_DIR, 0777, true)) {
            throw new Exception('Failed to create certificates directory');
        }
        error_log("Created certificates directory: " . UPLOAD_DIR);
    }

    if (!is_writable(UPLOAD_DIR)) {
        throw new Exception('Certificates directory is not writable: ' . UPLOAD_DIR);
    }
}

/**
 * Get level colors for PDF
 */
function getLevelColor($level)
{
    $colors = [
        'BEGINNER' => ['r' => 46, 'g' => 204, 'b' => 113],      // Green
        'INTERMEDIATE' => ['r' => 241, 'g' => 196, 'b' => 15],  // Yellow
        'ADVANCED' => ['r' => 230, 'g' => 126, 'b' => 34],      // Orange
        'EXPERT' => ['r' => 231, 'g' => 76, 'b' => 60],         // Red
    ];

    $level = strtoupper($level ?? 'BEGINNER');
    return $colors[$level] ?? ['r' => 149, 'g' => 165, 'b' => 166]; // Grey default
}

/**
 * Sanitize text for PDF (handle special characters)
 */
function sanitizeText($text)
{
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Convert UTF-8 to ISO-8859-1 for FPDF
    if (function_exists('iconv')) {
        $text = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    } else {
        // Fallback: remove non-ASCII characters
        $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    }

    return $text;
}

/**
 * Log message
 */
function logMessage($message)
{
    error_log(date('[Y-m-d H:i:s] ') . $message);
}

// ============================================================================
// CERTIFICATE PDF GENERATION
// ============================================================================

/**
 * Generate certificate PDF
 */
function generateCertificatePDF($userData, $skillData, $certificateId)
{
    logMessage("Starting PDF generation for user {$userData['id']}, skill {$skillData['id']}");

    // Check FPDF library
    if (!file_exists(FPDF_PATH)) {
        throw new Exception('FPDF library not found at: ' . FPDF_PATH);
    }

    require_once(FPDF_PATH);

    try {
        // Create PDF - Landscape, A4
        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);

        // ========================================
        // DECORATIVE BORDERS
        // ========================================
        $pdf->SetLineWidth(1.5);
        $pdf->SetDrawColor(41, 128, 185);
        $pdf->Rect(5, 5, 287, 200);

        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor(52, 152, 219);
        $pdf->Rect(8, 8, 281, 194);

        // Decorative corners
        $pdf->SetLineWidth(2);
        $pdf->SetDrawColor(41, 128, 185);
        // Top left
        $pdf->Line(10, 10, 30, 10);
        $pdf->Line(10, 10, 10, 30);
        // Top right
        $pdf->Line(267, 10, 287, 10);
        $pdf->Line(287, 10, 287, 30);
        // Bottom left
        $pdf->Line(10, 200, 30, 200);
        $pdf->Line(10, 180, 10, 200);
        // Bottom right
        $pdf->Line(267, 200, 287, 200);
        $pdf->Line(287, 180, 287, 200);

        // ========================================
        // HEADER
        // ========================================
        $pdf->SetFont('Arial', 'B', 42);
        $pdf->SetTextColor(41, 128, 185);
        $pdf->SetY(25);
        $pdf->Cell(0, 15, 'CERTIFICATE', 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell(0, 10, 'OF COMPLETION', 0, 1, 'C');

        // Decorative line
        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor(241, 196, 15);
        $pdf->Line(100, $pdf->GetY() + 3, 197, $pdf->GetY() + 3);

        // ========================================
        // CONTENT
        // ========================================
        $pdf->SetY($pdf->GetY() + 12);

        $pdf->SetFont('Arial', 'I', 14);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 10, 'This is to certify that', 0, 1, 'C');

        $pdf->SetY($pdf->GetY() + 3);

        // User name
        $pdf->SetFont('Arial', 'B', 32);
        $pdf->SetTextColor(0, 0, 0);
        $userName = strtoupper(sanitizeText($userData['name']));
        $pdf->Cell(0, 15, $userName, 0, 1, 'C');

        // Line under name
        $nameWidth = $pdf->GetStringWidth($userName);
        $startX = (297 - $nameWidth) / 2;
        $pdf->SetLineWidth(0.3);
        $pdf->SetDrawColor(241, 196, 15);
        $pdf->Line($startX, $pdf->GetY(), $startX + $nameWidth, $pdf->GetY());

        $pdf->SetY($pdf->GetY() + 8);

        $pdf->SetFont('Arial', '', 14);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 8, 'has successfully completed the skill', 0, 1, 'C');

        $pdf->SetY($pdf->GetY() + 3);

        // Skill name
        $pdf->SetFont('Arial', 'B', 26);
        $pdf->SetTextColor(41, 128, 185);
        $skillName = strtoupper(sanitizeText($skillData['name']));
        $pdf->Cell(0, 12, $skillName, 0, 1, 'C');

        $pdf->SetY($pdf->GetY() + 5);

        // Level badge
        $level = strtoupper($skillData['level'] ?? 'BEGINNER');
        $levelColors = getLevelColor($level);

        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFillColor($levelColors['r'], $levelColors['g'], $levelColors['b']);

        $levelText = $level . ' LEVEL';
        $levelWidth = $pdf->GetStringWidth($levelText) + 24;
        $levelX = (297 - $levelWidth) / 2;

        $pdf->SetX($levelX);
        $pdf->Cell($levelWidth, 10, $levelText, 0, 1, 'C', true);

        // ========================================
        // FOOTER
        // ========================================
        $pdf->SetY(155);

        $pdf->SetFont('Arial', '', 12);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 8, 'Issued on ' . date('F d, Y'), 0, 1, 'C');

        // Signature lines
        $pdf->SetY(170);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(70, 175, 130, 175);
        $pdf->Line(167, 175, 227, 175);

        $pdf->SetY(177);
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetX(70);
        $pdf->Cell(60, 5, 'Skill Provider', 0, 0, 'C');
        $pdf->SetX(167);
        $pdf->Cell(60, 5, 'Platform Director', 0, 1, 'C');

        // Certificate ID
        $pdf->SetY(192);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(0, 5, 'Certificate ID: ' . $certificateId, 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(41, 128, 185);
        $pdf->Cell(0, 5, 'SkillShare Platform', 0, 1, 'C');

        // ========================================
        // SAVE PDF
        // ========================================
        ensureUploadDirectory();

        $filename = 'certificate_' . $userData['id'] . '_' . $skillData['id'] . '_' . time() . '.pdf';
        $filepath = UPLOAD_DIR . $filename;

        logMessage("Saving PDF to: " . $filepath);

        $pdf->Output('F', $filepath);

        if (!file_exists($filepath)) {
            throw new Exception('Failed to save certificate file');
        }

        $filesize = filesize($filepath);
        logMessage("PDF saved successfully. Size: " . $filesize . " bytes");

        $url = BASE_URL . 'certificates/' . $filename;
        logMessage("Certificate URL: " . $url);

        return $url;
    } catch (Exception $e) {
        logMessage("Error generating PDF: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================================
// API ENDPOINTS
// ============================================================================

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ====================================================================
        // GENERATE CERTIFICATE
        // ====================================================================
        case 'generate':
            logMessage("=== GENERATE CERTIFICATE REQUEST ===");

            $input = getInput();
            $userId = $input['user_id'] ?? null;
            $skillId = $input['skill_id'] ?? null;

            logMessage("User ID: $userId, Skill ID: $skillId");

            if (!$userId || !$skillId) {
                sendResponse(false, ['message' => 'user_id and skill_id are required'], 400);
            }

            // Check if already exists
            $stmt = $pdo->prepare("
                SELECT id, certificate_url, issued_date
                FROM certificates
                WHERE user_id = ? AND skill_id = ?
            ");
            $stmt->execute([$userId, $skillId]);
            $existing = $stmt->fetch();

            if ($existing) {
                logMessage("Certificate already exists: " . $existing['certificate_url']);
                sendResponse(true, [
                    'message' => 'Certificate already exists',
                    'certificate_id' => $existing['id'],
                    'certificate_url' => $existing['certificate_url'],
                    'issued_date' => $existing['issued_date'],
                    'already_exists' => true
                ]);
            }

            // Get user data
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();

            if (!$userData) {
                logMessage("User not found: $userId");
                sendResponse(false, ['message' => 'User not found'], 404);
            }

            logMessage("User found: " . $userData['name']);
            logMessage("User email: " . $userData['email']);
            logMessage("User ID: " . $userData['id']);

            // Get skill data
            $stmt = $pdo->prepare("SELECT id, name, level, description FROM skills WHERE id = ?");
            $stmt->execute([$skillId]);
            $skillData = $stmt->fetch();

            if (!$skillData) {
                logMessage("Skill not found: $skillId");
                sendResponse(false, ['message' => 'Skill not found'], 404);
            }

            logMessage("Skill found: " . $skillData['name'] . " (" . $skillData['level'] . ")");

            // Generate certificate ID
            $certificateId = 'CERT-' .
                str_pad($userId, 4, '0', STR_PAD_LEFT) . '-' .
                str_pad($skillId, 4, '0', STR_PAD_LEFT) . '-' .
                date('Ymd-His');

            logMessage("Certificate ID: " . $certificateId);

            // Debug: Log the data being passed to PDF generation
            logMessage("PDF Generation - User Name: " . $userData['name']);
            logMessage("PDF Generation - User Email: " . $userData['email']);
            logMessage("PDF Generation - Skill Name: " . $skillData['name']);

            // Create PDF
            $certificateUrl = generateCertificatePDF($userData, $skillData, $certificateId);

            // Save to database
            $stmt = $pdo->prepare("
                INSERT INTO certificates (user_id, skill_id, certificate_url, issued_date)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $skillId, $certificateUrl]);

            $insertId = $pdo->lastInsertId();
            logMessage("Certificate saved to database with ID: $insertId");

            sendResponse(true, [
                'message' => 'Certificate generated successfully',
                'certificate_id' => $insertId,
                'certificate_url' => $certificateUrl,
                'issued_date' => date('Y-m-d H:i:s'),
                'already_exists' => false
            ]);
            break;

        // ====================================================================
        // GET CERTIFICATE
        // ====================================================================
        case 'get_certificate':
            $userId = $_GET['user_id'] ?? null;
            $skillId = $_GET['skill_id'] ?? null;

            if (!$userId || !$skillId) {
                sendResponse(false, ['message' => 'user_id and skill_id are required'], 400);
            }

            $stmt = $pdo->prepare("
                SELECT 
                    c.*,
                    u.name as user_name,
                    u.email as user_email,
                    s.name as skill_name,
                    s.level as skill_level
                FROM certificates c
                INNER JOIN users u ON c.user_id = u.id
                INNER JOIN skills s ON c.skill_id = s.id
                WHERE c.user_id = ? AND c.skill_id = ?
            ");
            $stmt->execute([$userId, $skillId]);
            $certificate = $stmt->fetch();

            if ($certificate) {
                sendResponse(true, ['certificate' => $certificate]);
            } else {
                // Return 200 OK - Flutter will generate certificate when it sees success=false
                sendResponse(false, ['message' => 'Certificate not found', 'certificate' => null], 200);
            }
            break;

        // ====================================================================
        // CHECK IF CERTIFICATE EXISTS
        // ====================================================================
        case 'check':
            $userId = $_GET['user_id'] ?? null;
            $skillId = $_GET['skill_id'] ?? null;

            if (!$userId || !$skillId) {
                sendResponse(false, ['message' => 'user_id and skill_id are required'], 400);
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count, certificate_url, issued_date
                FROM certificates
                WHERE user_id = ? AND skill_id = ?
            ");
            $stmt->execute([$userId, $skillId]);
            $result = $stmt->fetch();

            sendResponse(true, [
                'has_certificate' => $result['count'] > 0,
                'certificate_url' => $result['certificate_url'] ?? null,
                'issued_date' => $result['issued_date'] ?? null
            ]);
            break;

        // ====================================================================
        // GET USER'S ALL CERTIFICATES
        // ====================================================================
        case 'user_certificates':
            $userId = $_GET['user_id'] ?? null;

            if (!$userId) {
                sendResponse(false, ['message' => 'user_id is required'], 400);
            }

            $stmt = $pdo->prepare("
                SELECT 
                    c.*,
                    s.name as skill_name,
                    s.level as skill_level
                FROM certificates c
                INNER JOIN skills s ON c.skill_id = s.id
                WHERE c.user_id = ?
                ORDER BY c.issued_date DESC
            ");
            $stmt->execute([$userId]);
            $certificates = $stmt->fetchAll();

            sendResponse(true, [
                'certificates' => $certificates,
                'count' => count($certificates)
            ]);
            break;

        // ====================================================================
        // DELETE CERTIFICATE
        // ====================================================================
        case 'delete':
            $input = getInput();
            $certificateId = $input['certificate_id'] ?? null;

            if (!$certificateId) {
                sendResponse(false, ['message' => 'certificate_id is required'], 400);
            }

            // Get certificate details
            $stmt = $pdo->prepare("SELECT certificate_url FROM certificates WHERE id = ?");
            $stmt->execute([$certificateId]);
            $certificate = $stmt->fetch();

            if (!$certificate) {
                sendResponse(false, ['message' => 'Certificate not found'], 404);
            }

            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
            $stmt->execute([$certificateId]);

            // Delete file
            $filename = basename($certificate['certificate_url']);
            $filepath = UPLOAD_DIR . $filename;
            if (file_exists($filepath)) {
                unlink($filepath);
                logMessage("Deleted certificate file: " . $filepath);
            }

            sendResponse(true, ['message' => 'Certificate deleted successfully']);
            break;

        // ====================================================================
        // TEST ENDPOINT
        // ====================================================================
        case 'test':
            $tests = [
                'PHP Version' => PHP_VERSION,
                'FPDF Library' => file_exists(FPDF_PATH) ? '✅ Found' : '❌ Not Found',
                'Certificates Directory' => file_exists(UPLOAD_DIR) ? '✅ Exists' : '❌ Missing',
                'Directory Writable' => is_writable(UPLOAD_DIR) ? '✅ Yes' : '❌ No',
                'Database Connection' => '✅ Connected',
                'Database Name' => DB_NAME,
                'Base URL' => BASE_URL,
            ];

            sendResponse(true, ['tests' => $tests, 'status' => 'System OK']);
            break;

        // ====================================================================
        // INVALID ACTION
        // ====================================================================
        default:
            sendResponse(false, [
                'message' => 'Invalid action',
                'available_actions' => [
                    'generate' => 'POST with {user_id, skill_id}',
                    'get_certificate' => 'GET with user_id and skill_id',
                    'check' => 'GET with user_id and skill_id',
                    'user_certificates' => 'GET with user_id',
                    'delete' => 'POST with {certificate_id}',
                    'test' => 'GET - Test system configuration'
                ]
            ], 400);
            break;
    }
} catch (Exception $e) {
    logMessage("API Error: " . $e->getMessage());
    sendResponse(false, [
        'message' => 'Server error',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 500);
}
