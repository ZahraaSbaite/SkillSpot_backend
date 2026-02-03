<?php
header('Content-Type: application/json');
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = isset($_GET['email']) ? trim($_GET['email']) : '';

    if (empty($email)) {
        echo json_encode([
            'success' => false,
            'message' => 'Email is required'
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM transactions 
             WHERE sender_email = ? OR receiver_email = ? 
             ORDER BY created_at DESC 
             LIMIT 50"
        );
        $stmt->execute([$email, $email]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'transactions' => $transactions
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
?>
