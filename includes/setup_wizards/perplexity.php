<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class PerplexityWizard extends SetupWizard
{
    public function id(): string      { return 'perplexity'; }
    public function name(): string    { return 'Perplexity AI'; }
    public function purpose(): string { return 'Tracks who AI search engines cite for your target queries. Powers AEO (Answer Engine Optimization).'; }
    public function icon(): string    { return '🔭'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('perplexity_api_key'));
    }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured() ? '✓ API key configured · AEO tracking active' : 'API key not set';
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Create a Perplexity API key',
                'why'   => 'Perplexity is an AI search engine. Every answer comes with a source list — we use those citations to track whether your site is being referenced by AI for the queries that matter. Note: ContentAgent\'s AEO Tracker uses Claude by default, so Perplexity is an OPTIONAL second engine.',
                'external_url' => 'https://www.perplexity.ai/account/api/keys',
                'link_label'   => 'Open Perplexity Account → API ↗',
                'instructions' => [
                    'Sign in at perplexity.ai (or create an account). After login you\'ll land on the chat interface — that\'s normal.',
                    'Go to the API console at <code>console.perplexity.ai</code> (or click the button above). Perplexity will ask you to create an "Organization / Group" — name it <strong>ContentAgent</strong>.',
                    'Once inside the group, look at the LEFT sidebar. You\'ll see: Settings · Members · <strong>Billing</strong> · <strong>API Keys</strong>.',
                    '<strong>Billing first:</strong> click <em>Billing</em> → <em>Buy more credits</em>. Perplexity is pre-paid pay-as-you-go: <strong>$50 minimum top-up</strong>, no monthly subscription. You\'ll need a card. Light usage means the $50 lasts months — but it IS required before any API call works.',
                    'After top-up, click <strong>API Keys</strong> in the sidebar → <strong>Create Key</strong>. Give it any label (e.g. "ContentAgent prod").',
                    'Copy the key (starts with <code>pplx-</code>) and paste below. It\'s shown only once.',
                ],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key (pplx-...)', 'placeholder' => 'pplx-...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['api_key'] ?? '');
                    if (empty($key)) return ['valid' => false, 'error' => 'API key required'];
                    if (!str_starts_with($key, 'pplx-')) {
                        return ['valid' => false, 'error' => 'Perplexity keys start with pplx- — double-check you copied the right value.'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write(['perplexity_api_key' => trim($state['api_key'])]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('perplexity_api_key');
        if (empty($key)) return ['success' => false, 'error' => 'API key not saved'];

        // Minimal probe — ask a tiny question
        $payload = [
            'model'    => 'sonar',
            'messages' => [['role' => 'user', 'content' => 'What is 2+2?']],
            'temperature' => 0,
        ];

        $ch = curl_init('https://api.perplexity.ai/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'details' => ['note' => 'Perplexity Sonar responded successfully. AEO tracking is live.']];
        }
        return ['success' => false, 'http_status' => $code, 'error' => substr((string)$body, 0, 250)];
    }

    public function parse_error(array $test_result): array
    {
        $code = $test_result['http_status'] ?? 0;
        if ($code === 401) {
            return [
                'title' => 'Perplexity rejected the API key',
                'message' => 'The key is wrong or has been revoked. Regenerate a fresh key.',
                'fixes' => [
                    ['label' => 'Open Perplexity API settings', 'url' => 'https://www.perplexity.ai/settings/api'],
                ],
            ];
        }
        if ($code === 402 || stripos($test_result['error'] ?? '', 'payment') !== false) {
            return [
                'title' => 'Payment method required',
                'message' => 'Perplexity needs a payment method on file even though small usage is free. Add a card and try again.',
                'fixes' => [
                    ['label' => 'Add payment method', 'url' => 'https://www.perplexity.ai/settings/api'],
                ],
            ];
        }
        return parent::parse_error($test_result);
    }
}
