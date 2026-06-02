<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

/**
 * Google Custom Search Engine setup wizard.
 *
 * Captures the institutional pain from our own onboarding:
 *   - Separate concept of Search Engine (cx) vs API key
 *   - The cx is buried in an embed snippet (`cx=...`)
 *   - Trial account vs full pay-as-you-go account
 *   - Billing must be linked to the project
 *   - API restriction must be Custom Search API (not GSC API)
 *   - 5+ minute propagation after billing change
 */
class GoogleCseWizard extends SetupWizard
{
    public function id(): string      { return 'google_cse'; }
    public function name(): string    { return 'Google Custom Search'; }
    public function purpose(): string { return 'Real Google search results — powers Competitor Discovery, SERP Briefs, Brand Monitor, AI Presence web fallback. Free 100/day.'; }
    public function icon(): string    { return '🔍'; }
    public function is_required(): bool { return true; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('google_cse_api_key')) && !empty(config('google_cse_cx'));
    }

    public function config_keys(): array { return ['google_cse_api_key', 'google_cse_cx']; }

    public function status_line(?array $site = null): string
    {
        if (!$this->is_configured()) return 'Not set up';
        $key = config('google_cse_api_key');
        return '✓ Connected · key ' . substr($key, 0, 8) . '…';
    }

    public function steps(): array
    {
        return [
            // ── Step 1: create the Search Engine ──────────────────────
            [
                'title' => 'Create a Programmable Search Engine',
                'why'   => 'Tells Google which sites to search. We want it to search the whole web. This is free and takes 1 minute.',
                'external_url' => 'https://programmablesearchengine.google.com/controlpanel/all',
                'link_label'   => 'Open Programmable Search Engine ↗',
                'instructions' => [
                    'Click <strong>Add</strong> (top right).',
                    'Name: <code>ContentAgent</code>',
                    'Pick <strong>"Search the entire web"</strong> (toggle).',
                    'Solve the reCAPTCHA and click <strong>Create</strong>.',
                    'After it\'s created, the engine page shows an embed snippet like <code>&lt;script ... cx=<strong>XXXXX</strong>&gt;</code>.',
                    'Copy the value after <code>cx=</code> (string of letters and numbers, no slashes/dots).',
                ],
                'fields' => [
                    [
                        'key'         => 'cx',
                        'label'       => 'Search engine ID (cx)',
                        'placeholder' => 'a1b2c3d4e5f6g7h8i...',
                        'type'        => 'text',
                    ],
                ],
                'verify' => function (array $input): array {
                    $cx = trim($input['cx'] ?? '');
                    if (empty($cx)) return ['valid' => false, 'error' => 'cx required'];
                    if (preg_match('#[/?:]#', $cx)) {
                        return ['valid' => false, 'error' => "That looks like a URL. We just need the value AFTER cx= in the embed snippet (letters/numbers only)."];
                    }
                    if (strlen($cx) < 10 || strlen($cx) > 30) {
                        return ['valid' => false, 'error' => "Doesn't look like an engine ID. Try copying again — it should be ~17 characters."];
                    }
                    return ['valid' => true];
                },
            ],

            // ── Step 2: enable the API ────────────────────────────────
            [
                'title' => 'Enable the Custom Search API',
                'why'   => 'Tells your Google Cloud project that you want to use this API. (Different from the Search Engine you just made.)',
                'external_url' => 'https://console.cloud.google.com/apis/library/customsearch.googleapis.com',
                'link_label'   => 'Open API library ↗',
                'instructions' => [
                    'Make sure your project is selected at the top (or create one called "ContentAgent").',
                    'Click the blue <strong>Enable</strong> button.',
                    'Wait until it says "API Enabled".',
                ],
                'fields' => [
                    [
                        'key'         => 'project_id',
                        'label'       => 'Google Cloud Project ID',
                        'placeholder' => 'contentagent-123456',
                        'type'        => 'text',
                        'hint'        => 'Find it at the top of the Cloud Console (after "Project:"). Used for direct links to the right place if something goes wrong.',
                    ],
                ],
                'verify' => function (array $input): array {
                    $pid = trim($input['project_id'] ?? '');
                    if (empty($pid)) return ['valid' => false, 'error' => 'Project ID required'];
                    if (!preg_match('/^[a-z][a-z0-9-]{4,29}$/i', $pid)) {
                        return ['valid' => false, 'error' => 'Project IDs are lowercase letters, numbers, hyphens.'];
                    }
                    return ['valid' => true];
                },
            ],

            // ── Step 3: link billing + activate ───────────────────────
            [
                'title' => 'Link a billing account (and convert from trial)',
                'why'   => 'Google requires a card on file even for free-tier usage. The Custom Search free tier (100 queries/day) is plenty for us — you won\'t be charged. <strong>If you start on the free trial, you also have to convert to a full pay-as-you-go account before the API will actually work.</strong>',
                'external_url' => 'https://console.cloud.google.com/billing/linkedaccount',
                'link_label'   => 'Open Billing → Linked Account ↗',
                'instructions' => [
                    'If "This project has no billing account" → click <strong>Link a billing account</strong> → create or pick one (needs a card).',
                    '<strong>Then look for a yellow banner at the top that says "Free trial status … Activate"</strong>. Click <strong>Activate</strong>. This converts the account from trial to full pay-as-you-go. You keep all trial credit. You won\'t be charged until you exceed free tiers.',
                    'Skipping this is the #1 reason API keys return 403. We learned the hard way.',
                ],
                'fields' => [
                    [
                        'key'         => 'billing_confirmed',
                        'label'       => 'I\'ve linked billing AND activated full account',
                        'type'        => 'checkbox',
                        'placeholder' => '',
                    ],
                ],
                'verify' => function (array $input): array {
                    if (empty($input['billing_confirmed'])) {
                        return ['valid' => false, 'error' => 'Please confirm both steps. The API will not work without them.'];
                    }
                    return ['valid' => true];
                },
            ],

            // ── Step 4: create + paste the API key ────────────────────
            [
                'title' => 'Create the API key',
                'why'   => 'Authenticates ContentAgent\'s requests to Google. Restricted to Custom Search API only so it can\'t be abused if leaked.',
                'external_url' => 'https://console.cloud.google.com/apis/credentials',
                'link_label'   => 'Open Credentials ↗',
                'instructions' => [
                    'Click <strong>+ Create credentials → API key</strong>.',
                    'In the popup, click <strong>Edit API key</strong> (or click <strong>Restrict key</strong>).',
                    '<strong>Application restrictions:</strong> select <strong>None</strong> (we run server-side, no referrer to whitelist).',
                    '<strong>API restrictions:</strong> open the dropdown → uncheck anything pre-selected (like Google Search Console API) → check <strong>Custom Search API</strong> ONLY. <em>This is critical — wrong restriction = 403.</em>',
                    'Click <strong>Save</strong>.',
                    'Back on the Credentials page, click the key name to reveal the value. Copy the <code>AIzaSy…</code> string.',
                ],
                'fields' => [
                    [
                        'key'         => 'api_key',
                        'label'       => 'API key',
                        'placeholder' => 'AIzaSy...',
                        'type'        => 'password',
                    ],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['api_key'] ?? '');
                    if (empty($key)) return ['valid' => false, 'error' => 'API key required'];
                    if (!str_starts_with($key, 'AIza')) {
                        return ['valid' => false, 'error' => 'Google API keys start with "AIza…". Yours doesn\'t — double-check you copied the right value.'];
                    }
                    if (strlen($key) < 35 || strlen($key) > 50) {
                        return ['valid' => false, 'error' => 'Length looks off. Google keys are ~39 chars.'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'google_cse_api_key' => trim($state['api_key']),
                        'google_cse_cx'      => trim($state['cx']),
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('google_cse_api_key');
        $cx  = config('google_cse_cx');
        if (empty($key) || empty($cx)) {
            return ['success' => false, 'error' => 'Keys not saved yet.', 'http_status' => 0];
        }

        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'key' => $key, 'cx' => $cx, 'q' => 'test', 'num' => 1,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $data = json_decode($body, true);
            return [
                'success' => true,
                'details' => [
                    'http_status' => 200,
                    'sample_query' => 'test',
                    'results_returned' => count($data['items'] ?? []),
                ],
            ];
        }

        return [
            'success'     => false,
            'http_status' => $code,
            'error'       => $body,
        ];
    }

    public function parse_error(array $test_result): array
    {
        $body = $test_result['error'] ?? '';
        $http = $test_result['http_status'] ?? 0;
        $data = json_decode($body, true);
        $msg  = $data['error']['message'] ?? $body;

        // The classic "trial / billing / API not enabled" 403
        if ($http === 403 && (str_contains($msg, 'does not have the access') || str_contains($msg, 'PERMISSION_DENIED'))) {
            return [
                'title' => 'Google rejected the request — usually billing or trial activation',
                'message' => 'The API returned <code>PERMISSION_DENIED</code>. From our own setup pain, this is almost always one of these three:',
                'fixes' => [
                    [
                        'label' => '1. Convert from trial to full pay-as-you-go account',
                        'detail' => 'In Cloud Console, look for the yellow banner at the top with an "Activate" button. The Custom Search API will NOT work on a trial account, even with billing linked.',
                        'url'   => 'https://console.cloud.google.com/billing',
                    ],
                    [
                        'label' => '2. Verify a billing account is linked to the project',
                        'detail' => 'Even though the free tier (100/day) won\'t charge you, Google requires a card on file.',
                        'url'   => 'https://console.cloud.google.com/billing/linkedaccount',
                    ],
                    [
                        'label' => '3. Confirm API key restrictions',
                        'detail' => 'On the Credentials page, click your key. Under "API restrictions" make sure ONLY Custom Search API is checked. If "Google Search Console API" is selected instead, you\'ll get this exact error.',
                        'url'   => 'https://console.cloud.google.com/apis/credentials',
                    ],
                ],
                'retry_after' => 'After fixing, wait 1-2 minutes for Google to propagate the change, then click Test again.',
            ];
        }

        if ($http === 400 && str_contains($body, 'API key not valid')) {
            return [
                'title' => 'API key value is invalid',
                'message' => 'The key string isn\'t recognised by Google. Common cause: copied an extra space, or grabbed the wrong key.',
                'fixes' => [
                    [
                        'label' => 'Re-copy the key from Credentials',
                        'detail' => 'Make sure you click "Show key" on the right key, copy the full AIzaSy… value, no leading/trailing spaces.',
                        'url' => 'https://console.cloud.google.com/apis/credentials',
                    ],
                ],
            ];
        }

        if ($http === 400 && str_contains($body, 'Invalid Value')) {
            return [
                'title' => 'Search engine ID (cx) is invalid',
                'message' => 'Google says the cx value isn\'t recognised. Make sure you copied the value right after <code>cx=</code> in the embed snippet — not the script tag or any URL parameters.',
                'fixes' => [
                    [
                        'label' => 'Re-copy the cx from your Programmable Search Engine',
                        'url' => 'https://programmablesearchengine.google.com/controlpanel/all',
                    ],
                ],
            ];
        }

        if ($http === 429) {
            return [
                'title' => 'Daily quota exceeded',
                'message' => 'You\'ve hit the 100 queries/day free tier. Either wait until midnight Pacific time, or enable billing to go beyond.',
                'fixes' => [],
            ];
        }

        return parent::parse_error($test_result);
    }
}
