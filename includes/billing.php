<?php
/**
 * Billing / Stripe helpers.
 * Handles subscription management, plan limits, and Stripe webhook processing.
 */

require_once __DIR__ . '/helpers.php';

// Plan definitions
define('BILLING_PLANS', [
    'free' => [
        'name'        => 'Free',
        'price'       => 0,
        'sites_limit' => 1,
        'posts_limit' => 4,    // per month
        'features'    => ['1 site', '4 posts/month', 'SEO audit', 'Basic keywords'],
    ],
    'starter' => [
        'name'        => 'Starter',
        'price'       => 2900, // $29 in cents
        'sites_limit' => 1,
        'posts_limit' => 8,
        'features'    => ['1 site', '8 posts/month', 'SEO audit + fix', 'Keyword research', 'News scraper'],
    ],
    'growth' => [
        'name'        => 'Growth',
        'price'       => 7900, // $79
        'sites_limit' => 3,
        'posts_limit' => 30,
        'features'    => ['3 sites', '30 posts/month', 'All SEO features', 'Social media posting', 'Newsletter', 'Priority support'],
    ],
    'agency' => [
        'name'        => 'Agency',
        'price'       => 19900, // $199
        'sites_limit' => 10,
        'posts_limit' => 999,   // unlimited
        'features'    => ['10 sites', 'Unlimited posts', 'All features', 'White-label', 'Team management', 'API access'],
    ],
]);

/**
 * Get or create billing record for a user.
 */
function billing_get(PDO $db, int $user_id): array
{
    $stmt = $db->prepare('SELECT * FROM billing WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $billing = $stmt->fetch();

    if (!$billing) {
        // Create free plan with 14-day trial
        $trial_end = date('Y-m-d H:i:s', strtotime('+14 days'));
        $stmt = $db->prepare('INSERT INTO billing (user_id, plan, status, trial_ends_at, sites_limit, posts_limit) VALUES (?, "free", "trialing", ?, 1, 4)');
        $stmt->execute([$user_id, $trial_end]);

        $stmt = $db->prepare('SELECT * FROM billing WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $billing = $stmt->fetch();
    }

    return $billing;
}

/**
 * Check if user is within plan limits.
 */
function billing_check_limit(PDO $db, int $user_id, string $resource): array
{
    $billing = billing_get($db, $user_id);
    $plan = BILLING_PLANS[$billing['plan']] ?? BILLING_PLANS['free'];

    // Check trial expiry
    if ($billing['status'] === 'trialing' && $billing['trial_ends_at'] && strtotime($billing['trial_ends_at']) < time()) {
        return ['allowed' => false, 'reason' => 'Trial expired. Please upgrade your plan.'];
    }

    if ($billing['status'] === 'canceled' || $billing['status'] === 'past_due') {
        return ['allowed' => false, 'reason' => 'Subscription inactive. Please update your billing.'];
    }

    if ($resource === 'site') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM sites WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $count = $stmt->fetchColumn();
        if ($count >= $billing['sites_limit']) {
            return ['allowed' => false, 'reason' => "Site limit reached ({$billing['sites_limit']}). Upgrade your plan."];
        }
    }

    if ($resource === 'post') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM posts p JOIN sites s ON p.site_id = s.id WHERE s.user_id = ? AND p.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $stmt->execute([$user_id]);
        $count = $stmt->fetchColumn();
        if ($count >= $billing['posts_limit']) {
            return ['allowed' => false, 'reason' => "Monthly post limit reached ({$billing['posts_limit']}). Upgrade your plan."];
        }
    }

    return ['allowed' => true];
}

/**
 * Create a Stripe checkout session.
 */
function billing_create_checkout(string $plan, int $user_id, string $email): ?string
{
    $stripe_key = config('stripe_secret_key');
    if (empty($stripe_key)) return null;

    $plan_data = BILLING_PLANS[$plan] ?? null;
    if (!$plan_data || $plan_data['price'] === 0) return null;

    $price_lookup = [
        'starter' => config('stripe_price_starter'),
        'growth'  => config('stripe_price_growth'),
        'agency'  => config('stripe_price_agency'),
    ];

    $price_id = $price_lookup[$plan] ?? null;
    if (!$price_id) return null;

    $data = [
        'mode'                => 'subscription',
        'customer_email'      => $email,
        'line_items[0][price]' => $price_id,
        'line_items[0][quantity]' => 1,
        'success_url'         => config('app_url') . '/dashboard/settings.php?billing=success',
        'cancel_url'          => config('app_url') . '/dashboard/settings.php?billing=cancel',
        'subscription_data[trial_period_days]' => 14,
        'metadata[user_id]'   => $user_id,
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $stripe_key],
    ]);

    $body = curl_exec($ch);
    curl_close($ch);

    $session = json_decode($body, true);
    return $session['url'] ?? null;
}

/**
 * Handle Stripe webhook events.
 */
function billing_handle_webhook(PDO $db, array $event): void
{
    $type = $event['type'] ?? '';
    $data = $event['data']['object'] ?? [];

    switch ($type) {
        case 'checkout.session.completed':
            $user_id = $data['metadata']['user_id'] ?? null;
            $customer_id = $data['customer'] ?? null;
            $subscription_id = $data['subscription'] ?? null;

            if ($user_id && $customer_id) {
                $db->prepare('UPDATE billing SET stripe_customer_id = ?, stripe_subscription_id = ?, status = "active" WHERE user_id = ?')
                    ->execute([$customer_id, $subscription_id, $user_id]);
            }
            break;

        case 'customer.subscription.updated':
            $customer_id = $data['customer'] ?? null;
            $status = $data['status'] ?? '';
            $period_end = isset($data['current_period_end']) ? date('Y-m-d H:i:s', $data['current_period_end']) : null;

            $status_map = [
                'active'   => 'active',
                'past_due' => 'past_due',
                'canceled' => 'canceled',
                'trialing' => 'trialing',
            ];
            $db_status = $status_map[$status] ?? 'active';

            $db->prepare('UPDATE billing SET status = ?, current_period_end = ? WHERE stripe_customer_id = ?')
                ->execute([$db_status, $period_end, $customer_id]);
            break;

        case 'customer.subscription.deleted':
            $customer_id = $data['customer'] ?? null;
            $db->prepare('UPDATE billing SET status = "canceled" WHERE stripe_customer_id = ?')
                ->execute([$customer_id]);
            break;

        case 'invoice.payment_succeeded':
            $customer_id = $data['customer'] ?? null;
            $amount = $data['amount_paid'] ?? 0;

            $stmt = $db->prepare('SELECT user_id FROM billing WHERE stripe_customer_id = ?');
            $stmt->execute([$customer_id]);
            $billing = $stmt->fetch();
            if ($billing) {
                $db->prepare('INSERT INTO billing_events (user_id, event_type, stripe_event_id, amount_cents) VALUES (?, ?, ?, ?)')
                    ->execute([$billing['user_id'], 'payment_succeeded', $data['id'] ?? null, $amount]);
            }
            break;
    }
}

/**
 * Get plan details for display.
 */
function billing_get_plans(): array
{
    return BILLING_PLANS;
}
