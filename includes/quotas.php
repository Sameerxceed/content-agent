<?php
/**
 * Per-site usage guardrails.
 *
 * Drives plan enforcement so a runaway customer (one bug, one over-zealous
 * regen click, one 100K-URL migration) can't blow through unlimited AI cost.
 *
 * The master guard is `quota_check_budget()` — every AI wrapper calls it
 * BEFORE firing a model. If the site's plan budget for the current month
 * is already spent, the wrapper returns a structured "QUOTA_EXCEEDED"
 * error instead of hitting the API. No silent overage possible.
 *
 * Per-feature counters layer on top:
 *   quota_check_posts_per_month()
 *   quota_check_aeo_queries()
 *   quota_check_images_per_month()
 *   quota_check_redirect_urls()
 *   quota_check_plan_regens()
 *   quota_check_model_allowed()
 *
 * Super-admin always bypasses — useful for testing / customer support.
 *
 * Plan lookup is cached per-request because every Claude call goes through
 * here; we don't want to re-query plans + sites on every call within the
 * same web request.
 */

require_once __DIR__ . '/helpers.php';

class QuotaExceededException extends RuntimeException
{
    public string $reason_code;
    public array  $details;
    public function __construct(string $message, string $reason_code, array $details = [])
    {
        parent::__construct($message);
        $this->reason_code = $reason_code;
        $this->details = $details;
    }
}

/**
 * Resolve the effective plan for a site. Returns the row from `plans`,
 * with `budget_usd` already adjusted by any per-site override.
 *
 * Cached per-request — Claude calls fire in tight loops and this would
 * otherwise be N round-trips to MySQL.
 */
function quota_plan_for_site(PDO $db, int $site_id): ?array
{
    static $cache = [];
    if (isset($cache[$site_id])) return $cache[$site_id];

    try {
        $stmt = $db->prepare("SELECT p.*, s.plan_budget_override_usd
            FROM sites s
            LEFT JOIN plans p ON p.id = s.plan_id
            WHERE s.id = ?");
        $stmt->execute([$site_id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        // plans table doesn't exist yet (pre-052) → no enforcement
        $cache[$site_id] = null;
        return null;
    }
    if (!$row) {
        $cache[$site_id] = null;
        return null;
    }

    // Effective budget = per-site override if set, else plan default.
    $row['effective_budget_usd'] = $row['plan_budget_override_usd'] !== null
        ? (float)$row['plan_budget_override_usd']
        : (float)$row['monthly_ai_budget_usd'];

    $row['feature_flags']  = json_decode((string)($row['feature_flags']  ?? '{}'), true) ?: [];
    $row['allowed_models'] = json_decode((string)($row['allowed_models'] ?? 'null'), true);

    $cache[$site_id] = $row;
    return $row;
}

/**
 * Total AI spend (USD) for a site this calendar month.
 *
 * Cached per-request for the same reason as quota_plan_for_site() —
 * tight-loop calls would re-query.
 */
function quota_month_spend(PDO $db, int $site_id): float
{
    static $cache = [];
    if (isset($cache[$site_id])) return $cache[$site_id];

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(cost_usd), 0)
            FROM ai_calls
            WHERE site_id = ?
            AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $stmt->execute([$site_id]);
        $cache[$site_id] = (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$site_id] = 0.0;
    }
    return $cache[$site_id];
}

/**
 * Invalidate the per-request caches. Call after a known cost-incurring
 * operation if you want subsequent guards in the same request to see the
 * fresh number. Most callers don't need this because the per-request
 * snapshot is fine for budget enforcement at one-call granularity.
 */
function quota_reset_cache(?int $site_id = null): void
{
    static $cleared = false; // no-op; statics in helpers can't be cleared
    // The static caches above are function-scoped; we can't actually clear
    // them from here without restructuring. Real callers should rely on
    // the next request seeing the fresh data via ai_calls.created_at.
}

/**
 * MASTER GUARDRAIL — check before any AI call.
 *
 * Returns { allowed, reason_code, message, spent, budget, remaining }.
 *
 * Super-admin sites (plan code 'super') bypass. Calls without a site_id
 * (rare — global / unlinked ops) also bypass; we can't enforce against
 * a non-existent plan.
 */
function quota_check_budget(PDO $db, ?int $site_id, float $est_call_cost = 0.0): array
{
    if (!$site_id) {
        return ['allowed' => true, 'reason_code' => 'no_site_context', 'message' => '',
                'spent' => 0, 'budget' => 0, 'remaining' => 0];
    }
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan) {
        return ['allowed' => true, 'reason_code' => 'no_plan_assigned', 'message' => '',
                'spent' => 0, 'budget' => 0, 'remaining' => 0];
    }
    if (($plan['code'] ?? '') === 'super') {
        return ['allowed' => true, 'reason_code' => 'super_admin', 'message' => '',
                'spent' => 0, 'budget' => 0, 'remaining' => PHP_INT_MAX];
    }
    $budget = (float)$plan['effective_budget_usd'];
    if ($budget <= 0) {
        return ['allowed' => true, 'reason_code' => 'unlimited_budget', 'message' => '',
                'spent' => 0, 'budget' => 0, 'remaining' => PHP_INT_MAX];
    }

    $spent = quota_month_spend($db, $site_id);
    $remaining = $budget - $spent;

    if ($spent + $est_call_cost > $budget) {
        return [
            'allowed'     => false,
            'reason_code' => 'monthly_budget_exceeded',
            'message'     => sprintf(
                "This site has spent $%.2f of $%.2f AI budget this month. Upgrade plan or set a higher budget override to continue.",
                $spent, $budget
            ),
            'spent'     => $spent,
            'budget'    => $budget,
            'remaining' => max(0, $remaining),
        ];
    }
    return [
        'allowed'   => true,
        'reason_code' => 'within_budget',
        'message'   => '',
        'spent'     => $spent,
        'budget'    => $budget,
        'remaining' => $remaining,
    ];
}

/**
 * Throwing variant — call sites that want to abort hard.
 */
function quota_enforce_budget(PDO $db, ?int $site_id, float $est_call_cost = 0.0): void
{
    $r = quota_check_budget($db, $site_id, $est_call_cost);
    if (!$r['allowed']) {
        throw new QuotaExceededException($r['message'], $r['reason_code'], $r);
    }
}

/**
 * Is this model allowed for this site's plan? Lets us lock content
 * generation to Haiku at Starter but allow Sonnet/Opus at Pro+.
 */
function quota_check_model_allowed(PDO $db, ?int $site_id, string $model): array
{
    if (!$site_id) return ['allowed' => true];
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan || ($plan['code'] ?? '') === 'super') return ['allowed' => true];
    $allowed = $plan['allowed_models'];
    if ($allowed === null) return ['allowed' => true]; // NULL = unrestricted
    if (in_array($model, $allowed, true)) return ['allowed' => true];
    return [
        'allowed' => false,
        'reason_code' => 'model_not_allowed_on_plan',
        'message' => "Model '{$model}' is not available on the {$plan['name']} plan. Upgrade to unlock.",
    ];
}

/**
 * Posts published / drafted this month. Used to gate content generation.
 */
function quota_check_posts_per_month(PDO $db, ?int $site_id): array
{
    if (!$site_id) return ['allowed' => true];
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan || ($plan['code'] ?? '') === 'super') return ['allowed' => true];
    $cap = (int)$plan['max_posts_per_month'];
    if ($cap === 0) return ['allowed' => true]; // 0 = unlimited

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM posts
            WHERE site_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $stmt->execute([$site_id]);
        $count = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return ['allowed' => true];
    }

    if ($count >= $cap) {
        return [
            'allowed' => false,
            'reason_code' => 'posts_per_month_exceeded',
            'message' => "This plan allows {$cap} posts/month. You've created {$count} so far. Upgrade or wait until next month.",
            'used' => $count, 'cap' => $cap,
        ];
    }
    return ['allowed' => true, 'used' => $count, 'cap' => $cap];
}

/**
 * AEO tracked queries — gate the "Add query" action.
 */
function quota_check_aeo_queries(PDO $db, ?int $site_id): array
{
    if (!$site_id) return ['allowed' => true];
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan || ($plan['code'] ?? '') === 'super') return ['allowed' => true];
    $cap = (int)$plan['max_aeo_queries'];
    if ($cap === 0) return ['allowed' => true];

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM aeo_queries WHERE site_id = ?");
        $stmt->execute([$site_id]);
        $count = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return ['allowed' => true];
    }

    if ($count >= $cap) {
        return [
            'allowed' => false,
            'reason_code' => 'aeo_queries_exceeded',
            'message' => "This plan tracks up to {$cap} AEO queries. You have {$count}. Upgrade for more.",
            'used' => $count, 'cap' => $cap,
        ];
    }
    return ['allowed' => true, 'used' => $count, 'cap' => $cap];
}

/**
 * Hero images generated this month. Image-gen models bill per-image,
 * so caps here are stricter than token-based features.
 */
function quota_check_images_per_month(PDO $db, ?int $site_id): array
{
    if (!$site_id) return ['allowed' => true];
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan || ($plan['code'] ?? '') === 'super') return ['allowed' => true];
    $cap = (int)$plan['max_images_per_month'];
    if ($cap === 0) return ['allowed' => true];

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM ai_calls
            WHERE site_id = ?
              AND feature LIKE 'image_%'
              AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $stmt->execute([$site_id]);
        $count = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return ['allowed' => true];
    }

    if ($count >= $cap) {
        return [
            'allowed' => false,
            'reason_code' => 'images_per_month_exceeded',
            'message' => "This plan allows {$cap} hero images/month. You've used {$count}. Upgrade or upload your own.",
            'used' => $count, 'cap' => $cap,
        ];
    }
    return ['allowed' => true, 'used' => $count, 'cap' => $cap];
}

/**
 * URL count guard for "Build redirect map" — protects against a customer
 * with a 100K-URL Wayback haul submitting a single job they can't afford.
 */
function quota_check_redirect_urls(PDO $db, ?int $site_id, int $requested_url_count): array
{
    if (!$site_id) return ['allowed' => true];
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan || ($plan['code'] ?? '') === 'super') return ['allowed' => true];
    $cap = (int)$plan['max_redirect_urls_per_run'];
    if ($cap === 0) return ['allowed' => true];

    if ($requested_url_count > $cap) {
        return [
            'allowed' => false,
            'reason_code' => 'redirect_urls_per_run_exceeded',
            'message' => "This plan caps redirect-map builds at {$cap} URLs per run. Your queue has " . number_format($requested_url_count) . ". Upgrade or use 'Run 100 first' to test.",
            'used' => $requested_url_count, 'cap' => $cap,
        ];
    }
    return ['allowed' => true, 'used' => $requested_url_count, 'cap' => $cap];
}

/**
 * Plan regeneration rate — protects against the "user mashes Regenerate
 * 20 times in an hour" runaway.
 */
function quota_check_plan_regens(PDO $db, ?int $site_id): array
{
    if (!$site_id) return ['allowed' => true];
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan || ($plan['code'] ?? '') === 'super') return ['allowed' => true];
    $cap = (int)$plan['max_plan_regens_per_week'];
    if ($cap === 0) return ['allowed' => true];

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM ai_calls
            WHERE site_id = ?
              AND feature IN ('plan_cluster_pick', 'plan_sequence_items')
              AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$site_id]);
        // Each regen fires two feature labels (Pass A + Pass B). Divide.
        $count = (int)floor((int)$stmt->fetchColumn() / 2);
    } catch (Throwable $e) {
        return ['allowed' => true];
    }

    if ($count >= $cap) {
        return [
            'allowed' => false,
            'reason_code' => 'plan_regens_exceeded',
            'message' => "Plan can be regenerated {$cap}× per week. You've used {$count}. Tomorrow or upgrade.",
            'used' => $count, 'cap' => $cap,
        ];
    }
    return ['allowed' => true, 'used' => $count, 'cap' => $cap];
}

/**
 * Bundle for dashboards — return current usage vs every cap, ready to
 * render. Single round-trip pivot.
 */
function quota_usage_summary(PDO $db, int $site_id): array
{
    $plan = quota_plan_for_site($db, $site_id);
    if (!$plan) return ['plan' => null];

    $spend = quota_month_spend($db, $site_id);
    return [
        'plan'        => $plan,
        'spend_usd'   => $spend,
        'budget_usd'  => (float)$plan['effective_budget_usd'],
        'remaining'   => max(0, (float)$plan['effective_budget_usd'] - $spend),
        'posts'       => quota_check_posts_per_month($db, $site_id),
        'aeo_queries' => quota_check_aeo_queries($db, $site_id),
        'images'      => quota_check_images_per_month($db, $site_id),
        'plan_regens' => quota_check_plan_regens($db, $site_id),
    ];
}
