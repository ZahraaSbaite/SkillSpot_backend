<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
// cors.php - Include this at the top of your PHP files
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
require_once 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $senderEmail = isset($data['sender_email']) ? trim($data['sender_email']) : '';
    $receiverEmail = isset($data['receiver_email']) ? trim($data['receiver_email']) : '';
    $amount = isset($data['amount']) ? intval($data['amount']) : 0;
    $skillId = isset($data['skill_id']) ? trim($data['skill_id']) : '';
    $transactionType = isset($data['transaction_type']) ? trim($data['transaction_type']) : '';

    if (empty($senderEmail) || empty($receiverEmail) || $amount <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid transaction data'
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Get sender's current balance
        $stmt = $pdo->prepare("SELECT coins FROM users WHERE email = ?");
        $stmt->execute([$senderEmail]);
        $sender = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sender || $sender['coins'] < $amount) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Insufficient coins'
            ]);
            exit;
        }

        // Deduct from sender
        $stmt = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE email = ?");
        $stmt->execute([$amount, $senderEmail]);

        // Add to receiver
        $stmt = $pdo->prepare("UPDATE users SET coins = coins + ? WHERE email = ?");
        $stmt->execute([$amount, $receiverEmail]);

        // Record transaction
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (sender_email, receiver_email, amount, skill_id, transaction_type, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$senderEmail, $receiverEmail, $amount, $skillId, $transactionType]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Transaction completed successfully'
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Transaction failed: ' . $e->getMessage()
        ]);
    }
}
