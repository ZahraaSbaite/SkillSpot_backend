<?php
require_once 'vendor/autoload.php';
require_once 'config.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

// Your webhook signing secret from environment
$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET'];

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit();
}

// Handle the checkout.session.completed event
if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;

    // Get metadata
    $userId = $session->metadata->user_id;
    $coins = $session->metadata->coins;
    $packageId = $session->metadata->package_id;

    try {
        // Check if payment already processed (prevent double crediting)
        $stmt = $pdo->prepare("SELECT id FROM coin_transactions WHERE stripe_session_id = ?");
        $stmt->execute([$session->id]);

        if ($stmt->rowCount() === 0) {
            // Start transaction
            $pdo->beginTransaction();

            try {
                // Add coins to user
                $stmt = $pdo->prepare("UPDATE users SET coins = coins + ? WHERE id = ?");
                $stmt->execute([$coins, $userId]);

                // Record in coin_transactions table
                $amount = $session->amount_total / 100; // Convert cents to dollars
                $description = "Purchased {$coins} coins via Stripe ({$packageId} package)";

                $stmt = $pdo->prepare("
                    INSERT INTO coin_transactions 
                    (user_id, type, amount, description, stripe_session_id, package_id, status) 
                    VALUES (?, 'purchase', ?, ?, ?, ?, 'completed')
                ");
                $stmt->execute([$userId, $amount, $description, $session->id, $packageId]);

                $pdo->commit();

                // Log success
                error_log("✅ Coins added: User $userId received $coins coins");
            } catch (Exception $e) {
                $pdo->rollback();
                error_log("❌ Transaction failed: " . $e->getMessage());
                http_response_code(500);
                exit();
            }
        } else {
            error_log("⚠️ Duplicate webhook: Session {$session->id} already processed");
        }
    } catch (Exception $e) {
        error_log("❌ Webhook error: " . $e->getMessage());
        http_response_code(500);
        exit();
    }
}

http_response_code(200);
