<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class OpenAiWizard extends SetupWizard
{
    public function id(): string      { return 'openai'; }
    public function name(): string    { return 'OpenAI (DALL-E 3 for blog hero images)'; }
    public function purpose(): string { return 'Generates a custom hero image for every blog post drafted by the autopilot. DALL-E 3 produces brand-safe, on-topic illustrations. Optional — without it, the autopilot falls back to Unsplash stock photos, or you can upload your own image per post.'; }
    public function icon(): string    { return '🎨'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('openai_api_key'));
    }

    public function config_keys(): array { return ['openai_api_key']; }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured()
            ? '✓ API key saved · autopilot can generate hero images'
            : 'No key set · autopilot will use Unsplash fallback or leave posts without a hero image';
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Create an OpenAI API account',
                'why'   => 'DALL-E 3 lives behind the OpenAI API. ~$0.04 per image (standard quality, 1792x1024). For 24 posts/quarter that\'s under $1 — well worth a custom hero image per post.',
                'external_url' => 'https://platform.openai.com/signup',
                'link_label'   => 'Open OpenAI signup ↗',
                'instructions' => [
                    'Sign up or log into your OpenAI account.',
                    'You\'ll need a payment method on file — OpenAI no longer offers free image-generation credit.',
                    'Set a monthly spend limit under <strong>Billing → Limits</strong> if you want a safety cap.',
                ],
                'fields' => [
                    ['key' => '_acknowledged', 'label' => 'Account ready', 'type' => 'checkbox', 'placeholder' => ''],
                ],
                'verify' => function (array $input): array {
                    return !empty($input['_acknowledged'])
                        ? ['valid' => true]
                        : ['valid' => false, 'error' => 'Tick the box once your OpenAI account is ready.'];
                },
            ],
            [
                'title' => 'Create an API key',
                'why'   => 'API keys authenticate requests. Use a dedicated key for ContentAgent (don\'t reuse a personal-use key) so you can revoke it independently later.',
                'external_url' => 'https://platform.openai.com/api-keys',
                'link_label'   => 'Open API keys page ↗',
                'instructions' => [
                    'Click <strong>+ Create new secret key</strong>.',
                    'Name it something like <em>contentagent-prod</em>.',
                    'Permissions: <strong>Restricted</strong> is fine — only the <em>Model capabilities</em> permission is needed for image generation.',
                    'Copy the key (starts with <code>sk-...</code>). You won\'t see it again after closing the dialog.',
                    'Paste below.',
                ],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'OpenAI API key', 'placeholder' => 'sk-...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['api_key'] ?? '');
                    if (empty($key)) return ['valid' => false, 'error' => 'API key required'];
                    if (!str_starts_with($key, 'sk-')) {
                        return ['valid' => false, 'error' => 'OpenAI keys start with "sk-". Did you paste the right thing?'];
                    }
                    if (strlen($key) < 40) {
                        return ['valid' => false, 'error' => 'That key looks too short. Copy the full key from the OpenAI dashboard.'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write(['openai_api_key' => trim($state['api_key'])]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('openai_api_key');
        if (empty($key)) return ['success' => false, 'error' => 'API key not saved'];

        // Cheapest verification: list models. Free, no token cost.
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['success' => false, 'http_status' => $code, 'error' => 'HTTP ' . $code . ': ' . substr((string)$body, 0, 250)];
        }
        $data = json_decode($body, true);
        $models = $data['data'] ?? [];
        $has_dalle = false;
        foreach ($models as $m) {
            if (str_contains((string)($m['id'] ?? ''), 'dall-e')) { $has_dalle = true; break; }
        }
        return [
            'success' => true,
            'details' => ['note' => 'Authenticated. ' . count($models) . ' models available' . ($has_dalle ? ' (including DALL-E).' : '. DALL-E may not be enabled on this account — check Billing.')],
        ];
    }

    public function parse_error(array $test_result): array
    {
        $code = $test_result['http_status'] ?? 0;
        if ($code === 401) {
            return [
                'title'   => 'OpenAI rejected the key',
                'message' => 'The API key is invalid, revoked, or has restricted permissions that don\'t include model access. Create a new key and try again.',
                'fixes'   => [['label' => 'Open API keys page', 'url' => 'https://platform.openai.com/api-keys']],
            ];
        }
        if ($code === 429) {
            return [
                'title'   => 'Rate limit / quota exceeded',
                'message' => 'OpenAI returned 429. Either you\'ve hit your rate limit or your account has no billing set up. Add a payment method or wait a moment.',
                'fixes'   => [['label' => 'Open Billing', 'url' => 'https://platform.openai.com/account/billing']],
            ];
        }
        return parent::parse_error($test_result);
    }
}
