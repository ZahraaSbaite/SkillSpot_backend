<?php
header("Content-Type: application/json");
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

$user_id = (int)($_GET['user_id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'conversations' => []]);
    exit;
}

// Get all conversations with last message and unread count
$query = "
    SELECT 
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id 
            ELSE m.sender_id 
        END as other_user_id,
        u.name as other_user_name,
        u.email as other_user_email,
        m.message as last_message,
        m.message_type as last_message_type,
        m.created_at as last_message_time,
        m.sender_id as last_sender_id,
        (SELECT COUNT(*) 
         FROM messages 
         WHERE receiver_id = ? 
         AND sender_id = (CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END)
         AND is_read = 0
        ) as unread_count
    FROM messages m
    INNER JOIN (
        SELECT 
            CASE 
                WHEN sender_id = ? THEN receiver_id 
                ELSE sender_id 
            END as contact_id,
            MAX(id) as max_id
        FROM messages
        WHERE sender_id = ? OR receiver_id = ?
        GROUP BY contact_id
    ) latest ON m.id = latest.max_id
    LEFT JOIN users u ON u.id = latest.contact_id
    ORDER BY m.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);

$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'conversations' => $conversations
]);
