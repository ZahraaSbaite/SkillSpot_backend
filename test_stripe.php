<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: Starting...\n";

if (file_exists('vendor/autoload.php')) {
    echo "Step 2: Vendor folder found\n";
    require_once 'vendor/autoload.php';
} else {
    die("ERROR: vendor/autoload.php not found!");
}

if (file_exists('config.php')) {
    echo "Step 3: Config found\n";
    require_once 'config.php';
} else {
    die("ERROR: config.php not found!");
}

echo "Step 4: Loading environment variables...\n";
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "Step 5: Setting Stripe key...\n";
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

echo "Step 6: All good! Stripe is ready.\n";
echo json_encode(['status' => 'success', 'message' => 'Stripe integration working!']);
