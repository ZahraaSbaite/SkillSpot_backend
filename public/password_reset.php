<?php
// ============================================================
// CRITICAL: PREVENT ALL NON-JSON OUTPUT (MUST BE FIRST LINES)
// ============================================================
ob_start(); // Capture ALL accidental output (BOM, whitespace, warnings)
ini_set('display_errors', 0); // NEVER show errors to client
ini_set('log_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Force DB exceptions

// ============================================================
// CORS & JSON HEADERS (SET BEFORE ANY LOGIC)
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');

// ============================================================
// INPUT VALIDATION
// ============================================================
$raw_input = file_get_contents("php://input");
if (empty($raw_input)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Empty request body"]);
    exit();
}

$data = json_decode($raw_input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON format: " . json_last_error_msg()
    ]);
    exit();
}

// Extract parameters with validation
$action = isset($data['action']) ? trim($data['action']) : '';
$contact_method = (isset($data['contact_method']) && in_array($data['contact_method'], ['email', 'phone']))
    ? $data['contact_method']
    : 'email';
$email = isset($data['email']) ? filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL) : '';
$phone = isset($data['phone']) ? preg_replace('/[^0-9+]/', '', trim($data['phone'])) : '';
$session_id = isset($data['session_id']) ? trim($data['session_id']) : '';
$code = isset($data['code']) ? trim($data['code']) : '';
$new_password = isset($data['new_password']) ? trim($data['new_password']) : '';

// ============================================================
// SESSION HANDLING (SAFE RESUME OR FRESH START)
// ============================================================
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if ($action === 'send_code') {
        // FRESH SESSION FOR NEW VERIFICATION FLOW
        session_start();
        session_regenerate_id(true); // Prevent fixation
        // Clear any stale reset data
        unset(
            $_SESSION['reset_contact_method'],
            $_SESSION['reset_identifier'],
            $_SESSION['reset_code'],
            $_SESSION['reset_expiry'],
            $_SESSION['reset_verified']
        );
    } else {
        // VALIDATE SESSION RESUME FOR OTHER ACTIONS
        if (empty($session_id)) {
            throw new Exception("Session ID required for this action", 400);
        }

        if (!preg_match('/^[a-zA-Z0-9,-]{24,128}$/', $session_id)) {
            throw new Exception("Invalid session ID format", 400);
        }

        session_id($session_id);
        if (@session_start() === false) {
            throw new Exception("Invalid or expired session", 400);
        }

        // Verify session contains reset data
        if (!isset($_SESSION['reset_code'])) {
            session_destroy();
            throw new Exception("Session missing verification data. Request new code.", 400);
        }
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit();
}

// ============================================================
// DATABASE CONNECTION
// ============================================================
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "capstone";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log("DB Connection Failed: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database unavailable. Please try again later."
    ]);
    exit();
}

// ============================================================
// MAIN ACTION HANDLER (WITH EXCEPTION SAFETY)
// ============================================================
try {
    $response = null;

    switch ($action) {
        case 'send_code':
            $response = sendVerificationCode($conn, $contact_method, $email, $phone);
            break;

        case 'verify_code':
            $response = verifyCode($conn, $contact_method, $email, $phone, $code);
            break;

        case 'reset_password':
            $response = resetPassword($conn, $contact_method, $email, $phone, $new_password);
            break;

        default:
            $response = [
                "success" => false,
                "message" => "Invalid action. Supported: send_code, verify_code, reset_password"
            ];
    }

    // Add session_id to responses where relevant
    if ($response['success'] && in_array($action, ['send_code', 'verify_code'])) {
        $response['session_id'] = session_id();
    }
} catch (Exception $e) {
    error_log("Action Handler Error [{$action}]: " . $e->getMessage());
    $response = [
        "success" => false,
        "message" => "Verification service temporarily unavailable"
    ];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

// ============================================================
// FINAL OUTPUT (GUARANTEED CLEAN JSON)
// ============================================================
ob_end_clean(); // DISCARD ALL ACCIDENTAL OUTPUT
http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
exit();

// ============================================================
// FUNCTION: Send Verification Code
// ============================================================
function sendVerificationCode($conn, $contact_method, $email, $phone)
{
    $identifier = $contact_method === 'email' ? $email : $phone;
    $column = $contact_method === 'email' ? 'email' : 'phone_number';

    // Validate identifier
    if (empty($identifier)) {
        return [
            "success" => false,
            "message" => ucfirst($contact_method) . " is required"
        ];
    }

    if ($contact_method === 'email' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            "success" => false,
            "message" => "Invalid email format"
        ];
    }

    if ($contact_method === 'phone' && (strlen($phone) < 10 || strlen($phone) > 15)) {
        return [
            "success" => false,
            "message" => "Invalid phone number format"
        ];
    }

    // Check user exists
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE `$column` = ? LIMIT 1");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return [
                "success" => false,
                "message" => "No account found with this " . $contact_method
            ];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("DB Error (send_code): " . $e->getMessage());
        return [
            "success" => false,
            "message" => "Account verification failed"
        ];
    }

    // Generate and store code
    $verification_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['reset_contact_method'] = $contact_method;
    $_SESSION['reset_identifier'] = $identifier;
    $_SESSION['reset_code'] = $verification_code;
    $_SESSION['reset_expiry'] = time() + (15 * 60); // 15 minutes
    $_SESSION['reset_verified'] = false;

    // SECURITY: NEVER return code in production! Remove "code" key before deployment
    return [
        "success" => true,
        "message" => "Verification code sent to your " . $contact_method,
        "code" => $verification_code, // ⚠️ REMOVE IN PRODUCTION
        "expires_in" => 900 // 15 minutes in seconds
    ];
}

// ============================================================
// FUNCTION: Verify Code
// ============================================================
function verifyCode($conn, $contact_method, $email, $phone, $code)
{
    $identifier = $contact_method === 'email' ? $email : $phone;

    if (empty($identifier) || empty($code)) {
        return [
            "success" => false,
            "message" => ucfirst($contact_method) . " and verification code are required"
        ];
    }

    // Session validation (already checked in main flow, but verify again)
    $required_keys = ['reset_contact_method', 'reset_identifier', 'reset_code', 'reset_expiry'];
    foreach ($required_keys as $key) {
        if (!isset($_SESSION[$key])) {
            return [
                "success" => false,
                "message" => "Verification session expired. Request new code."
            ];
        }
    }

    // Validate session data matches request
    if ($_SESSION['reset_contact_method'] !== $contact_method) {
        return [
            "success" => false,
            "message" => "Contact method mismatch. Use same method as code request."
        ];
    }

    if ($_SESSION['reset_identifier'] !== $identifier) {
        return [
            "success" => false,
            "message" => ucfirst($contact_method) . " mismatch. Use same address/number as code request."
        ];
    }

    // Check expiry
    if (time() > $_SESSION['reset_expiry']) {
        session_destroy();
        return [
            "success" => false,
            "message" => "Verification code expired. Request new code."
        ];
    }

    // Verify code
    if (!hash_equals($_SESSION['reset_code'], $code)) {
        return [
            "success" => false,
            "message" => "Invalid verification code. " .
                (isset($_SESSION['attempts']) ? "Attempts left: " . (3 - ++$_SESSION['attempts']) : "")
        ];
    }

    // Prevent brute force
    if (!isset($_SESSION['attempts'])) {
        $_SESSION['attempts'] = 0;
    }
    if ($_SESSION['attempts'] >= 3) {
        session_destroy();
        return [
            "success" => false,
            "message" => "Too many failed attempts. Session terminated."
        ];
    }

    // Mark verified
    $_SESSION['reset_verified'] = true;
    unset($_SESSION['attempts']);

    return [
        "success" => true,
        "message" => "Code verified successfully. Proceed to reset password."
    ];
}

// ============================================================
// FUNCTION: Reset Password
// ============================================================
function resetPassword($conn, $contact_method, $email, $phone, $new_password)
{
    $identifier = $contact_method === 'email' ? $email : $phone;
    $column = $contact_method === 'email' ? 'email' : 'phone_number';

    // Validate inputs
    if (empty($identifier) || empty($new_password)) {
        return [
            "success" => false,
            "message" => ucfirst($contact_method) . " and new password are required"
        ];
    }

    if (strlen($new_password) < 8) {
        return [
            "success" => false,
            "message" => "Password must be at least 8 characters"
        ];
    }

    // Session validation
    if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true) {
        return [
            "success" => false,
            "message" => "Verification required. Complete code verification first."
        ];
    }

    if (time() > $_SESSION['reset_expiry']) {
        session_destroy();
        return [
            "success" => false,
            "message" => "Session expired. Restart password reset process."
        ];
    }

    // Verify session matches request
    if (
        $_SESSION['reset_contact_method'] !== $contact_method ||
        $_SESSION['reset_identifier'] !== $identifier
    ) {
        session_destroy();
        return [
            "success" => false,
            "message" => "Security mismatch. Restart password reset process."
        ];
    }

    // Update password
    try {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE `$column` = ?");
        $stmt->bind_param("ss", $hashed_password, $identifier);

        if (!$stmt->execute() || $stmt->affected_rows === 0) {
            $stmt->close();
            return [
                "success" => false,
                "message" => "Password update failed. Account not found."
            ];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Password Reset DB Error: " . $e->getMessage());
        return [
            "success" => false,
            "message" => "Password update failed. Contact support."
        ];
    }

    // Cleanup session
    session_destroy();

    return [
        "success" => true,
        "message" => "Password successfully reset. You may now log in."
    ];
}
