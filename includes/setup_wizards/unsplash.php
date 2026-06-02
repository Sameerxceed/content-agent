<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class UnsplashWizard extends SetupWizard
{
    public function id(): string      { return 'unsplash'; }
    public function name(): string    { return 'Unsplash (stock photos for blog posts)'; }
    public function purpose(): string { return 'Free, brand-safe stock photos for blog hero images. Used as a fallback when DALL-E 3 isn\'t configured, or as the primary choice if you prefer photography over AI illustrations. 50 requests/hour on the free Developer tier — well within ContentAgent\'s usage.'; }
    public function icon(): string    { return '📷'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('unsplash_access_key'));
    }

    public function config_keys(): array { return ['unsplash_access_key']; }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured()
            ? '✓ Access key saved · stock photos available'
            : 'No key set';
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Create a free Unsplash developer account',
                'why'   => 'Unsplash gives every developer a free Demo app with 50 API calls/hour. Plenty for ContentAgent\'s draft cadence (24 posts/quarter = under 1 call/day on average).',
                'external_url' => 'https://unsplash.com/developers',
                'link_label'   => 'Open Unsplash for Developers ↗',
                'instructions' => [
                    'Click <strong>Register as a developer</strong> (top right).',
                    'Sign up with email or GitHub.',
                    'Accept the API terms.',
                ],
                'fields' => [
                    ['key' => '_acknowledged', 'label' => 'Account created', 'type' => 'checkbox', 'placeholder' => ''],
                ],
                'verify' => function (array $input): array {
                    return !empty($input['_acknowledged'])
                        ? ['valid' => true]
                        : ['valid' => false, 'error' => 'Tick the box once the developer account is ready.'];
                },
            ],
            [
                'title' => 'Create an application + grab the Access Key',
                'why'   => 'The Access Key is what ContentAgent sends with each API call. Different from the Secret Key (which we don\'t need for search-only).',
                'external_url' => 'https://unsplash.com/oauth/applications',
                'link_label'   => 'Open Applications page ↗',
                'instructions' => [
                    'Click <strong>New Application</strong>.',
                    'Accept the API guidelines.',
                    'Name it <em>ContentAgent</em>; describe it as <em>"Auto-generated hero images for our blog posts"</em>.',
                    'After creating, scroll down — you\'ll see <strong>Access Key</strong> and <strong>Secret Key</strong>.',
                    'Copy the <strong>Access Key</strong> only (not the Secret) and paste below.',
                ],
                'fields' => [
                    ['key' => 'access_key', 'label' => 'Unsplash Access Key', 'placeholder' => '...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['access_key'] ?? '');
                    if (empty($key)) return ['valid' => false, 'error' => 'Access Key required'];
                    if (strlen($key) < 20) {
                        return ['valid' => false, 'error' => 'That key looks too short. Copy the full Access Key (not the Secret).'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write(['unsplash_access_key' => trim($state['access_key'])]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('unsplash_access_key');
        if (empty($key)) return ['success' => false, 'error' => 'Access key not saved'];

        $ch = curl_init('https://api.unsplash.com/search/photos?per_page=1&query=software');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Client-ID ' . $key],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['success' => false, 'http_status' => $code, 'error' => 'HTTP ' . $code . ': ' . substr((string)$body, 0, 250)];
        }
        $data = json_decode($body, true);
        $total = (int)($data['total'] ?? 0);
        return [
            'success' => true,
            'details' => ['note' => "Authenticated. Test search returned {$total} candidate photos."],
        ];
    }

    public function parse_error(array $test_result): array
    {
        $code = $test_result['http_status'] ?? 0;
        if ($code === 401) {
            return [
                'title'   => 'Unsplash rejected the Access Key',
                'message' => 'The key is invalid or revoked. Make sure you copied the <strong>Access Key</strong> (not the Secret Key) from the application page.',
                'fixes'   => [['label' => 'Open Applications page', 'url' => 'https://unsplash.com/oauth/applications']],
            ];
        }
        if ($code === 403) {
            return [
                'title'   => 'Rate limit hit',
                'message' => 'Free Unsplash Demo apps cap at 50 calls/hour. Wait an hour or apply for Production approval (~500/hour) on the Unsplash dashboard.',
                'fixes'   => [],
            ];
        }
        return parent::parse_error($test_result);
    }
}
