<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class BraveSearchWizard extends SetupWizard
{
    public function id(): string      { return 'brave_search'; }
    public function name(): string    { return 'Brave Search'; }
    public function purpose(): string { return 'Free Google-quality SERP results (2,000 queries/month, no card required). Primary SERP source for competitor discovery and SERP rank tracking. Falls back to DataForSEO if the free quota runs out.'; }
    public function icon(): string    { return '🦁'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('brave_search_api_key'));
    }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured()
            ? '✓ API key saved · SERP queries free up to 2k/month'
            : 'Not configured';
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Create a Brave Search API account',
                'why'   => 'Brave Search has its own independent web index (not Bing-rebadged). Free tier is 2,000 queries/month with no credit card — enough for competitor discovery + weekly SERP tracking on a handful of sites.',
                'external_url' => 'https://api.search.brave.com/register',
                'link_label'   => 'Open Brave Search API signup ↗',
                'instructions' => [
                    'Sign up with email + password (or Google login).',
                    'Choose the <strong>"Data for Search → Free"</strong> plan when prompted (2,000 queries/month).',
                    'No payment method required for the free tier.',
                ],
                'fields' => [
                    ['key' => '_acknowledged', 'label' => 'Account created', 'type' => 'checkbox', 'placeholder' => ''],
                ],
                'verify' => function (array $input): array {
                    return !empty($input['_acknowledged'])
                        ? ['valid' => true]
                        : ['valid' => false, 'error' => 'Tick the box once you\'ve signed up.'];
                },
            ],
            [
                'title' => 'Generate an API key',
                'why'   => 'The API key authenticates every search request. Brave calls it a "Subscription Token". Get one fresh from the API dashboard.',
                'external_url' => 'https://api.search.brave.com/app/keys',
                'link_label'   => 'Open API Keys ↗',
                'instructions' => [
                    'Open the API Keys page (link above).',
                    'Click <strong>+ Add API Key</strong>, name it "ContentAgent".',
                    'Copy the generated key — it\'s long, starts with letters/numbers, NOT a JWT.',
                    'Paste below.',
                ],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'Brave Search API Key', 'placeholder' => 'BSAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['api_key'] ?? '');
                    if (empty($key))         return ['valid' => false, 'error' => 'API key required'];
                    if (strlen($key) < 20)   return ['valid' => false, 'error' => 'That looks too short. Did you copy the full key?'];
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'brave_search_api_key' => trim($state['api_key']),
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('brave_search_api_key');
        if (empty($key)) {
            return ['success' => false, 'error' => 'API key not saved'];
        }

        // Cheapest possible test — a 1-result web search for a generic term.
        // Counts as one of your 2,000 monthly free queries.
        $ch = curl_init('https://api.search.brave.com/res/v1/web/search?q=test&count=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Subscription-Token: ' . $key,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            $err = json_decode((string)$body, true);
            $msg = $err['message'] ?? $err['error'] ?? ('HTTP ' . $code);
            return ['success' => false, 'http_status' => $code, 'error' => $msg];
        }

        $data    = json_decode($body, true);
        $hits    = $data['web']['results'] ?? [];
        $note    = 'Authenticated. Brave returned ' . count($hits) . ' result' . (count($hits) === 1 ? '' : 's') . ' for the test query.';
        return ['success' => true, 'details' => ['note' => $note]];
    }

    public function parse_error(array $test_result): array
    {
        $code = $test_result['http_status'] ?? 0;
        $err  = strtolower($test_result['error'] ?? '');

        if ($code === 401 || stripos($err, 'unauth') !== false || stripos($err, 'invalid') !== false) {
            return [
                'title'   => 'Brave rejected the API key',
                'message' => 'The key is wrong or has been revoked. Generate a fresh one in the Brave Search API dashboard.',
                'fixes'   => [
                    ['label' => 'Open API Keys', 'url' => 'https://api.search.brave.com/app/keys'],
                ],
            ];
        }
        if ($code === 429 || stripos($err, 'rate') !== false || stripos($err, 'quota') !== false) {
            return [
                'title'   => 'Brave rate limit hit',
                'message' => 'You\'ve hit the free-tier limit (2,000 queries/month or 1 query/second). Wait a moment, or set up DataForSEO as a paid fallback in the next wizard.',
                'fixes'   => [
                    ['label' => 'Open Brave dashboard', 'url' => 'https://api.search.brave.com/app/dashboard'],
                ],
            ];
        }
        return parent::parse_error($test_result);
    }
}
