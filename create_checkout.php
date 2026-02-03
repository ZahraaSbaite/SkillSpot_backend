<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'vendor/autoload.php';
require_once 'config.php';

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
} else {
    http_response_code(500);
    echo json_encode(['error' => '.env file not found. Please create it with your Stripe keys.']);
    exit;
}

// Coin packages - Updated to match your Flutter prices
$packages = [
    'small' => ['coins' => 20, 'price' => 1000, 'name' => '20 Coins'],      // $10.00
    'medium' => ['coins' => 50, 'price' => 2000, 'name' => '50 Coins'],     // $20.00
    'large' => ['coins' => 300, 'price' => 10000, 'name' => '300 Coins']    // $100.00
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $userId = $data['user_id'] ?? null;
    $packageId = $data['package_id'] ?? null;

    if (!$userId || !$packageId || !isset($packages[$packageId])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $package = $packages[$packageId];

    try {
        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $package['name'],
                        'description' => 'Purchase ' . $package['coins'] . ' coins'
                    ],
                    'unit_amount' => $package['price'],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => 'http://localhost/capstone/success.html?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://localhost/capstone/cancel.html',
            'metadata' => [
                'user_id' => $userId,
                'package_id' => $packageId,
                'coins' => $package['coins']
            ]
        ]);

        echo json_encode(['checkout_url' => $checkout_session->url]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
