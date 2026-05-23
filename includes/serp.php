<?php
/**
 * SERP abstraction — try providers in priority order, fall back on failure.
 *
 * Why this exists: Google CSE deprecated whole-web search for free engines.
 * DataForSEO works but costs money. Brave Search has a generous free tier.
 * Rather than lock the app to one provider, this wrapper lets each consumer
 * just call serp_search() and not care which provider actually served the
 * request.
 *
 * Provider order is configured by `serp_providers` in config — defaults to
 * ['brave', 'dataforseo']. Brave first because it's free up to 2k queries/month;
 * DataForSEO is the paid safety net.
 *
 * Each provider returns an array of:
 *   [['position' => int, 'url' => string, 'title' => string, 'snippet' => string], ...]
 * Empty array = provider reached but no results. Exception = provider couldn't
 * be reached (auth failure, balance empty, rate limit, etc.) — we fall through.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/serp_providers/brave.php';
require_once __DIR__ . '/dataforseo.php'; // for dataforseo_serp_results()

const SERP_DEFAULT_ORDER = ['brave', 'dataforseo'];

/**
 * Try each configured provider until one returns results (or empty without
 * throwing). Returns ['provider' => 'brave'|'dataforseo'|null, 'results' => array,
 * 'errors' => ['brave' => 'msg', ...]] so callers can log who served + why others failed.
 */
function serp_search(string $keyword, int $depth = 30): array
{
    $order  = config('serp_providers');
    if (!is_array($order) || empty($order)) $order = SERP_DEFAULT_ORDER;

    $errors = [];
    foreach ($order as $provider) {
        try {
            $results = serp_call_provider($provider, $keyword, $depth);
            // A provider that responds with empty results still counts as success
            // — the keyword genuinely has no SERP, no point falling through.
            return ['provider' => $provider, 'results' => $results, 'errors' => $errors];
        } catch (Throwable $e) {
            $errors[$provider] = $e->getMessage();
            // fall through to next provider
        }
    }

    return ['provider' => null, 'results' => [], 'errors' => $errors];
}

/**
 * Dispatch to the named provider's wrapper function. Throws if the provider
 * is unknown or the call fails.
 */
function serp_call_provider(string $provider, string $keyword, int $depth): array
{
    switch ($provider) {
        case 'brave':
            return brave_serp_results($keyword, $depth);
        case 'dataforseo':
            return dataforseo_serp_results($keyword, $depth);
        default:
            throw new RuntimeException("Unknown SERP provider: {$provider}");
    }
}

/**
 * Which provider would be tried first given current config? Used by UIs that
 * want to show "Using Brave (free)" or "Using DataForSEO ($X balance)" upfront.
 */
function serp_active_provider(): ?string
{
    $order = config('serp_providers');
    if (!is_array($order) || empty($order)) $order = SERP_DEFAULT_ORDER;
    foreach ($order as $p) {
        if (serp_provider_configured($p)) return $p;
    }
    return null;
}

function serp_provider_configured(string $provider): bool
{
    switch ($provider) {
        case 'brave':
            return !empty(config('brave_search_api_key'));
        case 'dataforseo':
            return !empty(config('dataforseo_login')) && !empty(config('dataforseo_password'));
        default:
            return false;
    }
}
