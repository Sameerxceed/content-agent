<?php
/**
 * AI cost estimation + logging.
 *
 * Two responsibilities:
 *  1. Rate-card lookup so any heavy AI op can preflight a cost estimate
 *     BEFORE running (so the admin sees the bill, the customer sees the
 *     scope, and neither gets surprised).
 *  2. (Phase 0, follow-up) Log every actual call to ai_calls so estimates
 *     calibrate from real data. For Phase 1 the log writer is a stub —
 *     it short-circuits if the table doesn't exist yet.
 *
 * Pricing is USD per 1M tokens. Keep this table in sync with the model
 * cards in config/config.php and update when providers reprice.
 *
 * Per-call cost formula:
 *   cost_usd = (input_tokens  / 1_000_000) * input_per_mtok
 *            + (output_tokens / 1_000_000) * output_per_mtok
 *
 * Image-gen models are billed per image, not per token — we encode that
 * with `per_image` and treat input_tokens as image count.
 */

// Rates as of 2026-01 model cards.
const AI_PRICES = [
    'claude-haiku-4-5-20251001'      => ['input_per_mtok' => 1.00,  'output_per_mtok' => 5.00],
    'claude-haiku-4-5'               => ['input_per_mtok' => 1.00,  'output_per_mtok' => 5.00],
    'claude-sonnet-4-6'              => ['input_per_mtok' => 3.00,  'output_per_mtok' => 15.00],
    'claude-opus-4-7'                => ['input_per_mtok' => 15.00, 'output_per_mtok' => 75.00],
    'gemini-2.0-flash-exp'           => ['input_per_mtok' => 0.10,  'output_per_mtok' => 0.40],
    'gemini-2.0-flash'               => ['input_per_mtok' => 0.10,  'output_per_mtok' => 0.40],
    'gpt-4o-search-preview'          => ['input_per_mtok' => 2.50,  'output_per_mtok' => 10.00],
    'gpt-4o-mini'                    => ['input_per_mtok' => 0.15,  'output_per_mtok' => 0.60],
    'sonar'                          => ['input_per_mtok' => 1.00,  'output_per_mtok' => 1.00],
    'dall-e-3'                       => ['per_image' => 0.04],
    'gpt-image-1'                    => ['per_image' => 0.04],
    'imagen-4.0-fast'                => ['per_image' => 0.02],
    'imagen-4.0-fast-generate-001'   => ['per_image' => 0.02],
];

/**
 * Compute the dollar cost of a single AI call from its token usage.
 *
 * @param string $model         Model ID matching AI_PRICES keys.
 * @param int    $input_tokens  For image models, pass image count.
 * @param int    $output_tokens Ignored for image models.
 * @return float USD cost. Returns 0.0 if the model is unknown (so unknown
 *               models don't crash the dashboard — they just under-report
 *               until pricing gets added).
 */
function ai_cost_for_call(string $model, int $input_tokens, int $output_tokens = 0): float
{
    $p = AI_PRICES[$model] ?? null;
    if (!$p) return 0.0;
    if (isset($p['per_image'])) {
        return $p['per_image'] * max(1, $input_tokens);
    }
    return ($input_tokens  / 1_000_000) * $p['input_per_mtok']
         + ($output_tokens / 1_000_000) * $p['output_per_mtok'];
}

/**
 * Estimate the cost of a heavy multi-call AI job before running it.
 *
 * Known job_types and the params they expect:
 *
 *   'redirect_build'
 *     { dead_count: int, heuristic_hit_count: int, model?: string }
 *     The heuristic pass runs free in PHP; only (dead - hits) need Claude.
 *
 *   'content_plan_generate'
 *     { item_count: int, model?: string }
 *
 *   'aeo_recall'
 *     { query_count: int, engines: ['claude'|'openai'|'gemini'|'perplexity', ...] }
 *
 *   'image_gen'
 *     { count: int, model?: string }
 *
 * @return array {
 *   job_type, steps (array of {label, calls, est_cost, free?}),
 *   total_calls, est_input_tokens, est_output_tokens,
 *   est_cost_usd, est_runtime_sec
 * }
 */
function ai_estimate_job(string $job_type, array $params): array
{
    $out = [
        'job_type'         => $job_type,
        'steps'            => [],
        'total_calls'      => 0,
        'est_input_tokens' => 0,
        'est_output_tokens'=> 0,
        'est_cost_usd'     => 0.0,
        'est_runtime_sec'  => 0,
        'currency'         => 'USD',
        'note'             => 'Estimate from current rate cards. Will calibrate from real usage as data accumulates.',
    ];

    switch ($job_type) {
        case 'redirect_build':
            $dead       = max(0, (int)($params['dead_count'] ?? 0));
            $heuristic  = max(0, (int)($params['heuristic_hit_count'] ?? 0));
            $heuristic  = min($heuristic, $dead);
            $needs_ai   = max(0, $dead - $heuristic);
            $model      = (string)($params['model'] ?? 'claude-haiku-4-5-20251001');

            // Builder batches 10 URLs per Claude call. Per-batch shape:
            //   ~3500 input (system + 10 dead URLs × ~15 candidates each)
            //   ~1500 output (10 JSON verdicts × ~150 tokens)
            $batch_size = 10;
            $batches    = (int)ceil($needs_ai / $batch_size);
            $in_per_batch  = 3500;
            $out_per_batch = 1500;
            $cost_per_batch = ai_cost_for_call($model, $in_per_batch, $out_per_batch);
            $cost_per_url   = $batch_size > 0 ? $cost_per_batch / $batch_size : 0;
            $ai_cost        = $cost_per_batch * $batches;

            // Runtime: ~3s per batch (Haiku at ~12K tokens), bounded by
            // Anthropic concurrent-request limits.
            $runtime = (int)ceil($batches * 3) + 30;

            $out['steps'] = [
                [
                    'label'     => "Heuristic match (free) — instant pattern + slug similarity",
                    'calls'     => $heuristic,
                    'est_cost'  => 0.0,
                    'free'      => true,
                    'detail'    => "{$heuristic} URLs likely matched by exact-path / same-slug / .php→clean rules",
                ],
                [
                    'label'     => "AI fuzzy match — Claude picks the best living target ({$batch_size} URLs per call)",
                    'calls'     => $batches,
                    'est_cost'  => $ai_cost,
                    'detail'    => "{$needs_ai} URLs across {$batches} batches · ~" . number_format($cost_per_url, 5) . "/URL · ~{$in_per_batch} in + ~{$out_per_batch} out per batch",
                    'model'     => $model,
                ],
                [
                    'label'     => "Risk-tier into auto-approve / review / no-target buckets",
                    'calls'     => 0,
                    'est_cost'  => 0.0,
                    'free'      => true,
                    'detail'    => "≥85% confidence auto-approves, 60-84% to review queue, the rest to manual decision",
                ],
            ];
            $out['total_calls']       = $batches;
            $out['est_input_tokens']  = $batches * $in_per_batch;
            $out['est_output_tokens'] = $batches * $out_per_batch;
            $out['est_cost_usd']      = $ai_cost;
            $out['est_runtime_sec']   = $runtime;
            break;

        case 'content_plan_generate':
            // The plan generator runs two big Claude passes per site:
            //   Pass A — pick 8-12 topic clusters from the keyword pool.
            //   Pass B — sequence ~24 plan items across the 12-week horizon.
            // Per-item artifact generation (the bigger spend) happens later
            // when each item is committed via plan-item.php; that's its own
            // job_type, not this one.
            $items = max(1, (int)($params['item_count'] ?? 24));
            $keywords = max(30, (int)($params['keyword_count'] ?? 150));
            $model = (string)($params['model'] ?? 'claude-haiku-4-5-20251001');

            // Pass A: full keyword set goes in + business profile, JSON cluster list out.
            $pa_in  = 4000 + $keywords * 30;
            $pa_out = 1800;
            $pa_cost = ai_cost_for_call($model, $pa_in, $pa_out);

            // Pass B: clusters + winning keywords go in, item list with target_week + bucket out.
            $pb_in  = 5500 + $items * 80;
            $pb_out = 2500 + $items * 80;
            $pb_cost = ai_cost_for_call($model, $pb_in, $pb_out);

            $total = $pa_cost + $pb_cost;
            $out['steps'] = [
                [
                    'label'    => "Pass A — pick topic clusters from your keyword pool",
                    'calls'    => 1,
                    'est_cost' => $pa_cost,
                    'detail'   => "~{$keywords} keywords analysed → 8-12 clusters · ~{$pa_in} in + ~{$pa_out} out tokens",
                    'model'    => $model,
                ],
                [
                    'label'    => "Pass B — sequence {$items} items across 12 weeks",
                    'calls'    => 1,
                    'est_cost' => $pb_cost,
                    'detail'   => "Quick wins first, then pillars + supporting · ~{$pb_in} in + ~{$pb_out} out tokens",
                    'model'    => $model,
                ],
                [
                    'label'    => "Forecast clicks at the {$items}-item horizon",
                    'calls'    => 0,
                    'est_cost' => 0.0,
                    'free'     => true,
                    'detail'   => "Math from your existing keyword volumes — no AI call",
                ],
            ];
            $out['total_calls']       = 2;
            $out['est_input_tokens']  = $pa_in + $pb_in;
            $out['est_output_tokens'] = $pa_out + $pb_out;
            $out['est_cost_usd']      = $total;
            $out['est_runtime_sec']   = 180; // empirically ~3 minutes end-to-end
            break;

        case 'aeo_recall':
            $queries = max(1, (int)($params['query_count'] ?? 1));
            $engines = $params['engines'] ?? ['claude'];
            $engine_costs = [
                'claude'     => ['model' => 'claude-haiku-4-5-20251001', 'in' => 1500, 'out' => 800],
                'openai'     => ['model' => 'gpt-4o-search-preview',     'in' => 1500, 'out' => 800],
                'gemini'     => ['model' => 'gemini-2.0-flash',          'in' => 1500, 'out' => 800],
                'perplexity' => ['model' => 'sonar',                     'in' => 1500, 'out' => 800],
            ];
            $total = 0.0; $calls = 0;
            foreach ($engines as $e) {
                $cfg = $engine_costs[$e] ?? null;
                if (!$cfg) continue;
                $per = ai_cost_for_call($cfg['model'], $cfg['in'], $cfg['out']);
                $cost = $per * $queries;
                $out['steps'][] = [
                    'label'    => ucfirst($e) . " — {$queries} queries",
                    'calls'    => $queries,
                    'est_cost' => $cost,
                    'detail'   => "~" . number_format($per, 4) . "/query",
                    'model'    => $cfg['model'],
                ];
                $total += $cost;
                $calls += $queries;
            }
            $out['total_calls']     = $calls;
            $out['est_cost_usd']    = $total;
            $out['est_runtime_sec'] = (int)ceil($queries * 8); // engines run parallel per query, ~8s/round
            break;

        case 'image_gen':
            $count = max(1, (int)($params['count'] ?? 1));
            $model = (string)($params['model'] ?? 'gpt-image-1');
            $cost  = ai_cost_for_call($model, $count, 0);
            $out['steps'] = [[
                'label'    => "{$count} image(s) via {$model}",
                'calls'    => $count,
                'est_cost' => $cost,
                'detail'   => "~$" . number_format($cost / $count, 3) . "/image",
                'model'    => $model,
            ]];
            $out['total_calls']     = $count;
            $out['est_cost_usd']    = $cost;
            $out['est_runtime_sec'] = $count * 6;
            break;

        default:
            $out['note'] = "Unknown job_type '{$job_type}'. Add an estimator branch in includes/ai_cost.php.";
            break;
    }

    return $out;
}

/**
 * Lazy-cached PDO handle for the cost logger. AI wrappers can be called
 * multiple times per request — PHP's `require` cache means the second
 * `require db.php` returns true, not the PDO. So we open our own and
 * cache it in a static.
 */
function _ai_db(): ?PDO
{
    static $db = null;
    if ($db !== null) return $db ?: null;
    try {
        $config = require __DIR__ . '/../config/config.php';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['db_host'], $config['db_port'], $config['db_name'], $config['db_charset']);
        $db = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $db;
    } catch (Throwable $e) {
        error_log('[ai_cost _ai_db] ' . $e->getMessage());
        $db = false; // sentinel so we don't retry every call
        return null;
    }
}

/**
 * Log a single completed AI call to ai_calls. No-op if the migration
 * hasn't been run yet (existence cached per-request).
 *
 * Callers don't need to pass a PDO — we open our own lazily so this works
 * from anywhere (CLI, web, cron).
 *
 * @param string      $provider 'anthropic' | 'openai' | 'gemini' | 'perplexity'
 * @param string      $model
 * @param string      $feature  Stable identifier, e.g. 'redirect_fuzzy_match'
 * @param int|null    $site_id
 * @param array       $usage    Provider's usage block.
 *                              Anthropic: { input_tokens, output_tokens }
 *                              OpenAI:    { prompt_tokens, completion_tokens }
 *                              Gemini:    { promptTokenCount, candidatesTokenCount }
 * @param int         $ms       Wall-clock ms for the call.
 * @param int|null    $post_id
 */
function ai_log_call(string $provider, string $model, string $feature, ?int $site_id, array $usage, int $ms, ?int $post_id = null): void
{
    $db = _ai_db();
    if (!$db) return;

    static $exists = null;
    if ($exists === null) {
        try {
            $r = $db->query("SHOW TABLES LIKE 'ai_calls'");
            $exists = $r && $r->fetch() ? true : false;
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    if (!$exists) return;

    $in  = (int)($usage['input_tokens']
        ?? $usage['prompt_tokens']
        ?? $usage['promptTokenCount']
        ?? 0);
    $out = (int)($usage['output_tokens']
        ?? $usage['completion_tokens']
        ?? $usage['candidatesTokenCount']
        ?? 0);
    $cost = ai_cost_for_call($model, $in, $out);

    try {
        $stmt = $db->prepare("INSERT INTO ai_calls
            (provider, model, feature, site_id, post_id, input_tokens, output_tokens, cost_usd, ms, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$provider, $model, $feature, $site_id, $post_id, $in, $out, $cost, $ms]);
    } catch (Throwable $e) {
        error_log('[ai_log_call] ' . $e->getMessage());
    }
}
