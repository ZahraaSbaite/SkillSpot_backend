<?php
// test_user.php - Test if getUserByEmail works
header('Content-Type: text/html; charset=utf-8');

$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

echo "<h1>User API Test</h1>";
echo "<pre>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Database connected\n\n";

    // Get all users from database
    echo "1. Users in database:\n";
    $stmt = $pdo->query("SELECT id, name, email FROM users LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "❌ No users found in database!\n";
        echo "   You need to create some users first.\n\n";
    } else {
        echo "✅ Found " . count($users) . " users:\n";
        foreach ($users as $user) {
            echo "   - ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
        }
        echo "\n";

        // Test get_user_by_email.php with first user
        $testEmail = $users[0]['email'];
        echo "2. Testing get_user_by_email.php with: $testEmail\n\n";

        // Simulate the API call
        $url = 'http://localhost/flutter_backend/get_user_by_email.php';
        $data = json_encode(['email' => $testEmail]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "HTTP Status: $httpCode\n";
        echo "Response:\n";
        echo $response . "\n\n";

        $decoded = json_decode($response, true);

        if ($decoded && isset($decoded['success']) && $decoded['success']) {
            echo "✅ get_user_by_email.php is working correctly!\n";
            echo "   Returned user: " . json_encode($decoded['user'], JSON_PRETTY_PRINT) . "\n\n";
        } else {
            echo "❌ get_user_by_email.php returned an error or wrong format\n";
            echo "   Expected format: {'success': true, 'user': {...}}\n";
            echo "   Got: " . ($decoded ? json_encode($decoded) : 'null') . "\n\n";
        }

        // Show what the Flutter app expects
        echo "3. What Flutter expects from get_user_by_email.php:\n";
        echo "{\n";
        echo "  \"success\": true,\n";
        echo "  \"user\": {\n";
        echo "    \"id\": \"1\",\n";
        echo "    \"name\": \"John Doe\",\n";
        echo "    \"email\": \"john@test.com\",\n";
        echo "    \"phone_number\": \"+1234567890\",\n";
        echo "    \"location\": \"New York\"\n";
        echo "  }\n";
        echo "}\n\n";
    }

    // Check if get_user_by_email.php exists
    $filePath = __DIR__ . '/get_user_by_email.php';
    echo "4. Checking get_user_by_email.php file:\n";
    if (file_exists($filePath)) {
        echo "✅ File exists at: $filePath\n";
    } else {
        echo "❌ File NOT found at: $filePath\n";
        echo "   This is the problem! Create this file.\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";

// Provide a quick fix
echo "<hr>";
echo "<h2>Quick Fix - Create get_user_by_email.php</h2>";
echo "<p>If the file doesn't exist or isn't working, copy this code into <code>get_user_by_email.php</code>:</p>";
echo "<textarea rows='30' cols='80' style='font-family: monospace;'>";
echo <<<'PHP'
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database connection
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get email from POST request
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? null;

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode([
            'success' => true,
            'user' => $user
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
    }
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
PHP;
echo "</textarea>";
