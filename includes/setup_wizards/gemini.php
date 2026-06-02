<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class GeminiWizard extends SetupWizard
{
    public function id(): string      { return 'gemini'; }
    public function name(): string    { return 'Google Gemini (Imagen for blog hero images)'; }
    public function purpose(): string { return 'Generates a custom hero image for every blog post drafted by the autopilot using Google\'s Imagen 4 model. Cheaper than DALL-E and you may already have a Google AI Studio account. Optional — without it the autopilot falls back to DALL-E (if configured), then Unsplash stock photos, then leaves the post for manual upload.'; }
    public function icon(): string    { return '✨'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('gemini_api_key'));
    }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured()
            ? '✓ API key saved · autopilot can generate hero images via Imagen 4'
            : 'No key set';
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Open Google AI Studio',
                'why'   => 'Imagen 4 is exposed through the Gemini API in Google AI Studio. If you have a Google account, you can get an API key in under a minute — no billing card required for the free tier (which is plenty for 2 posts/week).',
                'external_url' => 'https://aistudio.google.com/apikey',
                'link_label'   => 'Open Google AI Studio · API keys ↗',
                'instructions' => [
                    'Sign in with your Google account.',
                    'You\'ll land on the <strong>API keys</strong> page.',
                    'If this is your first key, you may need to accept the AI Studio terms.',
                ],
                'fields' => [
                    ['key' => '_acknowledged', 'label' => 'Signed in to AI Studio', 'type' => 'checkbox', 'placeholder' => ''],
                ],
                'verify' => function (array $input): array {
                    return !empty($input['_acknowledged'])
                        ? ['valid' => true]
                        : ['valid' => false, 'error' => 'Tick the box once you\'re on the API keys page.'];
                },
            ],
            [
                'title' => 'Create an API key',
                'why'   => 'API keys authenticate requests to Gemini. Use a dedicated key for ContentAgent so you can revoke it independently. Free tier limits are well within autopilot usage for a single-site plan (24 images/quarter).',
                'external_url' => 'https://aistudio.google.com/apikey',
                'link_label'   => 'Open API keys page ↗',
                'instructions' => [
                    'Click <strong>+ Create API key</strong>.',
                    'Pick (or create) a Google Cloud project to attach the key to.',
                    'Copy the generated key (starts with <code>AIza...</code>).',
                    'Paste below.',
                ],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'Gemini API key', 'placeholder' => 'AIza... or AQ....', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['api_key'] ?? '');
                    if (empty($key)) return ['valid' => false, 'error' => 'API key required'];
                    // Google AI Studio emits two formats:
                    //   - legacy: "AIza..." (~39 chars)
                    //   - new:    "AQ.Ab..." (~52 chars)
                    // Accept anything that looks plausibly key-shaped; the live test
                    // call against /v1beta/models will catch a wrong paste.
                    if (strlen($key) < 30) {
                        return ['valid' => false, 'error' => 'That key looks too short. Copy the full key from AI Studio.'];
                    }
                    if (preg_match('/\s/', $key)) {
                        return ['valid' => false, 'error' => 'Key contains whitespace — copy only the key value, no quotes or spaces.'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write(['gemini_api_key' => trim($state['api_key'])]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('gemini_api_key');
        if (empty($key)) return ['success' => false, 'error' => 'API key not saved'];

        // Cheapest verification: list models. Free, no token cost.
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['success' => false, 'http_status' => $code, 'error' => 'HTTP ' . $code . ': ' . substr((string)$body, 0, 250)];
        }
        $data = json_decode($body, true);
        $models = $data['models'] ?? [];
        $has_imagen = false;
        foreach ($models as $m) {
            if (str_contains((string)($m['name'] ?? ''), 'imagen')) { $has_imagen = true; break; }
        }
        return [
            'success' => true,
            'details' => ['note' => 'Authenticated. ' . count($models) . ' models available' . ($has_imagen ? ' (including Imagen).' : '. Imagen may not be available on this account.')],
        ];
    }

    public function parse_error(array $test_result): array
    {
        $code = $test_result['http_status'] ?? 0;
        if ($code === 400) {
            return [
                'title'   => 'Gemini rejected the request',
                'message' => 'Usually means the API key is malformed. Re-copy from AI Studio.',
                'fixes'   => [['label' => 'Open API keys page', 'url' => 'https://aistudio.google.com/apikey']],
            ];
        }
        if ($code === 403) {
            return [
                'title'   => 'Gemini rejected the key',
                'message' => 'The API key is invalid, revoked, or the Generative Language API isn\'t enabled on the linked Google Cloud project.',
                'fixes'   => [['label' => 'Open API keys page', 'url' => 'https://aistudio.google.com/apikey']],
            ];
        }
        if ($code === 429) {
            return [
                'title'   => 'Rate limit / quota exceeded',
                'message' => 'Gemini returned 429. You\'ve hit the free-tier rate limit. Wait a moment, or attach billing to lift the cap.',
                'fixes'   => [['label' => 'Open billing', 'url' => 'https://aistudio.google.com/apikey']],
            ];
        }
        return parent::parse_error($test_result);
    }
}
