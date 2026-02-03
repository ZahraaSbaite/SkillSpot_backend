<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database configuration
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}

// Get the action from query string or POST
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Route to appropriate function
switch ($action) {
    case 'send_message':
        sendMessage($pdo);
        break;
    case 'get_messages':
        getMessages($pdo);
        break;
    case 'get_conversations':
        getConversations($pdo);
        break;
    case 'mark_as_read':
        markAsRead($pdo);
        break;
    case 'get_unread_count':
        getUnreadCount($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ==================== SEND MESSAGE ====================
function sendMessage($pdo)
{
    try {
        // Get JSON input or form data
        $input = json_decode(file_get_contents('php://input'), true);

        $sender_id = $input['sender_id'] ?? $_POST['sender_id'] ?? null;
        $receiver_id = $input['receiver_id'] ?? $_POST['receiver_id'] ?? null;
        $message = $input['message'] ?? $_POST['message'] ?? '';
        $message_type = $input['message_type'] ?? $_POST['message_type'] ?? 'text';

        if (!$sender_id || !$receiver_id) {
            echo json_encode(['success' => false, 'message' => 'Sender and receiver are required']);
            return;
        }

        // Handle file upload
        $file_url = null;
        $thumbnail_url = null;
        $file_name = null;
        $file_size = null;

        if ($message_type !== 'text' && isset($_FILES['file'])) {
            $upload_result = handleFileUpload($_FILES['file'], $message_type);
            if ($upload_result['success']) {
                $file_url = $upload_result['file_url'];
                $thumbnail_url = $upload_result['thumbnail_url'] ?? null;
                $file_name = $upload_result['file_name'];
                $file_size = $upload_result['file_size'];
            } else {
                echo json_encode(['success' => false, 'message' => $upload_result['message']]);
                return;
            }
        }

        // Insert message into database
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, message, message_type, file_url, thumbnail_url, file_name, file_size, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $sender_id,
            $receiver_id,
            $message,
            $message_type,
            $file_url,
            $thumbnail_url,
            $file_name,
            $file_size
        ]);

        $message_id = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully',
            'message_id' => $message_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ==================== GET MESSAGES ====================
function getMessages($pdo)
{
    try {
        $sender_id = $_GET['sender_id'] ?? null;
        $receiver_id = $_GET['receiver_id'] ?? null;
        $last_message_id = $_GET['last_message_id'] ?? 0;

        if (!$sender_id || !$receiver_id) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters', 'messages' => []]);
            return;
        }

        // Get messages between two users
        $stmt = $pdo->prepare("
            SELECT * FROM messages 
            WHERE id > ? AND (
                (sender_id = ? AND receiver_id = ?) OR 
                (sender_id = ? AND receiver_id = ?)
            )
            ORDER BY created_at ASC
        ");

        $stmt->execute([$last_message_id, $sender_id, $receiver_id, $receiver_id, $sender_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark messages as read (messages sent TO the current user FROM the other user)
        if (!empty($messages)) {
            $message_ids = array_column($messages, 'id');
            $placeholders = str_repeat('?,', count($message_ids) - 1) . '?';

            $update_stmt = $pdo->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE id IN ($placeholders) AND receiver_id = ? AND sender_id = ?
            ");

            $update_stmt->execute(array_merge($message_ids, [$sender_id, $receiver_id]));
        }

        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'messages' => []
        ]);
    }
}

// ==================== GET CONVERSATIONS ====================
function getConversations($pdo)
{
    try {
        $user_id = $_GET['user_id'] ?? null;

        if (!$user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required', 'conversations' => []]);
            return;
        }

        // Get conversations with last message and unread count
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END as other_user_id,
                u.name as other_user_name,
                m.message as last_message,
                m.message_type as last_message_type,
                m.sender_id as last_sender_id,
                m.created_at as last_message_time,
                (
                    SELECT COUNT(*) 
                    FROM messages 
                    WHERE receiver_id = ? 
                    AND sender_id = (
                        CASE 
                            WHEN m.sender_id = ? THEN m.receiver_id 
                            ELSE m.sender_id 
                        END
                    )
                    AND is_read = 0
                ) as unread_count
            FROM messages m
            INNER JOIN (
                SELECT 
                    CASE 
                        WHEN sender_id = ? THEN receiver_id
                        ELSE sender_id
                    END as contact_id,
                    MAX(created_at) as max_time
                FROM messages
                WHERE sender_id = ? OR receiver_id = ?
                GROUP BY contact_id
            ) latest ON (
                (m.sender_id = ? AND m.receiver_id = latest.contact_id) OR
                (m.receiver_id = ? AND m.sender_id = latest.contact_id)
            ) AND m.created_at = latest.max_time
            INNER JOIN users u ON u.id = latest.contact_id
            ORDER BY m.created_at DESC
        ");

        $stmt->execute([
            $user_id,  // CASE for other_user_id
            $user_id,  // unread subquery receiver_id
            $user_id,  // unread subquery CASE
            $user_id,  // inner join CASE
            $user_id,  // WHERE sender_id
            $user_id,  // WHERE receiver_id
            $user_id,  // ON sender_id
            $user_id   // ON receiver_id
        ]);

        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'conversations' => $conversations
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'conversations' => []
        ]);
    }
}

// ==================== MARK AS READ ====================
function markAsRead($pdo)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $user_id = $input['user_id'] ?? null;
        $other_user_id = $input['other_user_id'] ?? null;

        if (!$user_id || !$other_user_id) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            return;
        }

        // Mark all messages from other_user to user as read
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE receiver_id = ? AND sender_id = ? AND is_read = 0
        ");

        $stmt->execute([$user_id, $other_user_id]);
        $affected_rows = $stmt->rowCount();

        echo json_encode([
            'success' => true,
            'message' => 'Messages marked as read',
            'affected_rows' => $affected_rows
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}

// ==================== GET UNREAD COUNT ====================
function getUnreadCount($pdo)
{
    try {
        $user_id = $_GET['user_id'] ?? null;

        if (!$user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required', 'total_unread' => 0]);
            return;
        }

        // Get total unread messages for this user
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_unread 
            FROM messages 
            WHERE receiver_id = ? AND is_read = 0
        ");

        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'total_unread' => (int)$result['total_unread']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'total_unread' => 0
        ]);
    }
}

// ==================== FILE UPLOAD HANDLER ====================
function handleFileUpload($file, $type)
{
    $upload_dir = __DIR__ . '/../uploads/';
    $thumb_dir = __DIR__ . '/../uploads/thumbnails/';

    // Create directories if they don't exist
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    if (!file_exists($thumb_dir)) mkdir($thumb_dir, 0777, true);

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_name = $file['name'];
    $file_size = $file['size'];
    $unique_name = uniqid() . '_' . time() . '.' . $file_extension;
    $file_path = $upload_dir . $unique_name;
    $relative_path = 'uploads/' . $unique_name;

    // Validate file type
    if ($type === 'image') {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($file_extension, $allowed)) {
            return ['success' => false, 'message' => 'Invalid image format'];
        }

        // Check file size (max 5MB for images)
        if ($file_size > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Image too large (max 5MB)'];
        }
    } elseif ($type === 'document') {
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($file_extension, $allowed)) {
            return ['success' => false, 'message' => 'Invalid document format'];
        }

        // Check file size (max 10MB for documents)
        if ($file_size > 10 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Document too large (max 10MB)'];
        }
    }

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        $result = [
            'success' => true,
            'file_url' => $relative_path,
            'file_name' => $file_name,
            'file_size' => $file_size
        ];

        // Create thumbnail for images
        if ($type === 'image') {
            $thumbnail_name = 'thumb_' . $unique_name;
            $thumbnail_path = $thumb_dir . $thumbnail_name;
            $relative_thumb_path = 'uploads/thumbnails/' . $thumbnail_name;

            if (createThumbnail($file_path, $thumbnail_path, 300)) {
                $result['thumbnail_url'] = $relative_thumb_path;
            }
        }

        return $result;
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

// ==================== THUMBNAIL CREATOR ====================
function createThumbnail($source, $destination, $max_size)
{
    list($width, $height, $type) = getimagesize($source);

    if (!$width || !$height) return false;

    $ratio = $width / $height;
    if ($width > $height) {
        $new_width = $max_size;
        $new_height = $max_size / $ratio;
    } else {
        $new_height = $max_size;
        $new_width = $max_size * $ratio;
    }

    $thumb = imagecreatetruecolor($new_width, $new_height);

    switch ($type) {
        case IMAGETYPE_JPEG:
            $source_img = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $source_img = imagecreatefrompng($source);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            break;
        case IMAGETYPE_GIF:
            $source_img = imagecreatefromgif($source);
            break;
        case IMAGETYPE_WEBP:
            $source_img = imagecreatefromwebp($source);
            break;
        default:
            return false;
    }

    imagecopyresampled($thumb, $source_img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($thumb, $destination, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($thumb, $destination, 8);
            break;
        case IMAGETYPE_GIF:
            imagegif($thumb, $destination);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($thumb, $destination, 85);
            break;
    }

    imagedestroy($source_img);
    imagedestroy($thumb);

    return true;
}
