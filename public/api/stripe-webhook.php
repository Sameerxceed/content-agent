<?php
/**
 * Stripe Webhook endpoint.
 * POST /api/stripe-webhook.php
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/billing.php';

$db = require __DIR__ . '/../../includes/db.php';

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhook_secret = config('stripe_webhook_secret');

// Verify signature if webhook secret is set
if ($webhook_secret && $sig) {
    $elements = [];
    foreach (explode(',', $sig) as $part) {
        [$key, $value] = explode('=', $part, 2);
        $elements[trim($key)] = trim($value);
    }
    $timestamp = $elements['t'] ?? '';
    $signature = $elements['v1'] ?? '';

    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $webhook_secret);

    if (!hash_equals($expected, $signature)) {
        http_response_code(400);
        exit('Invalid signature');
    }
}

$event = json_decode($payload, true);
if (!$event || empty($event['type'])) {
    http_response_code(400);
    exit('Invalid payload');
}

billing_handle_webhook($db, $event);

http_response_code(200);
echo 'OK';
