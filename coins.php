<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

// Get action and user_id from request
$action = isset($_GET['action']) ? $_GET['action'] : '';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit();
}

if ($user_id <= 0 && $action !== 'test') {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Handle different actions
switch ($action) {
    case 'get_balance':
        getBalance($conn, $user_id);
        break;

    case 'get_history':
        getHistory($conn, $user_id);
        break;

    case 'add_coins':
        addCoins($conn, $user_id);
        break;

    case 'deduct_coins':
        deductCoins($conn, $user_id);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

// Function to get user's coin balance
function getBalance($conn, $user_id)
{
    try {
        $stmt = $conn->prepare("SELECT coins FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            echo json_encode([
                'success' => true,
                'balance' => intval($row['coins'] ?? 0)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching balance: ' . $e->getMessage()
        ]);
    }
}

// Function to get transaction history
function getHistory($conn, $user_id)
{
    try {
        $stmt = $conn->prepare("
            SELECT 
                id,
                type,
                amount,
                description,
                DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p') as date
            FROM coin_transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }

        echo json_encode([
            'success' => true,
            'history' => $transactions
        ]);
        $stmt->close();
    } catch (Exception $e) {
        // If table doesn't exist, return empty history
        echo json_encode([
            'success' => true,
            'history' => []
        ]);
    }
}

// Function to add coins (credit)
function addCoins($conn, $user_id)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $amount = isset($data['amount']) ? intval($data['amount']) : 0;
        $description = isset($data['description']) ? $data['description'] : 'Coins added';

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid amount']);
            return;
        }

        $conn->begin_transaction();

        try {
            // Update user's balance
            $stmt = $conn->prepare("UPDATE users SET coins = coins + ? WHERE id = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();

            // Record transaction
            $stmt = $conn->prepare("
                INSERT INTO coin_transactions (user_id, type, amount, description, created_at) 
                VALUES (?, 'credit', ?, ?, NOW())
            ");
            $stmt->bind_param("iis", $user_id, $amount, $description);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            // Get new balance
            $stmt = $conn->prepare("SELECT coins FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Coins added successfully',
                'new_balance' => intval($row['coins'])
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error adding coins: ' . $e->getMessage()
        ]);
    }
}

// Function to deduct coins (debit)
function deductCoins($conn, $user_id)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $amount = isset($data['amount']) ? intval($data['amount']) : 0;
        $description = isset($data['description']) ? $data['description'] : 'Coins spent';

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid amount']);
            return;
        }

        $conn->begin_transaction();

        try {
            // Check if user has enough coins
            $stmt = $conn->prepare("SELECT coins FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row || $row['coins'] < $amount) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Insufficient coins']);
                return;
            }

            // Update user's balance
            $stmt = $conn->prepare("UPDATE users SET coins = coins - ? WHERE id = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();

            // Record transaction
            $stmt = $conn->prepare("
                INSERT INTO coin_transactions (user_id, type, amount, description, created_at) 
                VALUES (?, 'debit', ?, ?, NOW())
            ");
            $stmt->bind_param("iis", $user_id, $amount, $description);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            // Get new balance
            $stmt = $conn->prepare("SELECT coins FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Coins deducted successfully',
                'new_balance' => intval($row['coins'])
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error deducting coins: ' . $e->getMessage()
        ]);
    }
}

$conn->close();
