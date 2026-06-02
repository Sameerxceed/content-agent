<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class GscAppWizard extends SetupWizard
{
    public function id(): string      { return 'gsc_app'; }
    public function name(): string    { return 'Google Search Console'; }
    public function purpose(): string { return 'Real keyword rankings, impressions, clicks, CTR from Google. Replaces AI estimates with real data.'; }
    public function icon(): string    { return '📊'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('google_client_id')) && !empty(config('google_client_secret'));
    }

    public function config_keys(): array { return ['google_client_id', 'google_client_secret']; }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured() ? '✓ OAuth credentials configured · connect per site' : 'OAuth credentials not set';
    }

    public function steps(): array
    {
        $redirect = config('app_url') . '/api/oauth/google-callback.php';
        return [
            [
                'title' => 'Enable the Search Console API',
                'why'   => 'Different from the Custom Search API (that\'s for searching the web). This one reads YOUR site\'s ranking data from Google.',
                'external_url' => 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com',
                'link_label' => 'Open Search Console API ↗',
                'instructions' => [
                    'Make sure your project is selected at the top.',
                    'Click <strong>Enable</strong>.',
                ],
                'fields' => [
                    ['key' => '_acknowledged', 'label' => 'Enabled', 'type' => 'checkbox', 'placeholder' => ''],
                ],
                'verify' => function (array $input): array {
                    return !empty($input['_acknowledged']) ? ['valid' => true] : ['valid' => false, 'error' => 'Click the link, enable the API, then check the box.'];
                },
            ],
            [
                'title' => 'Create OAuth 2.0 Client ID',
                'why'   => 'OAuth lets each user authorize ContentAgent to read their own GSC data (without sharing passwords).',
                'external_url' => 'https://console.cloud.google.com/apis/credentials',
                'link_label' => 'Open Credentials ↗',
                'instructions' => [
                    'First make sure the <strong>OAuth consent screen</strong> is set up: left sidebar → OAuth consent screen → User Type "External" → fill basic info → Save. Then back to Credentials.',
                    'Click <strong>+ Create Credentials → OAuth client ID</strong>.',
                    'Application type: <strong>Web application</strong>.',
                    'Name: <code>ContentAgent</code>',
                    'Authorized redirect URIs → Add: <code>' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '</code>',
                    'Click Create. Copy Client ID and Client Secret from the popup.',
                ],
                'fields' => [
                    ['key' => 'client_id',     'label' => 'OAuth Client ID',     'placeholder' => '...apps.googleusercontent.com', 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => 'OAuth Client Secret', 'placeholder' => 'GOCSPX-...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $id  = trim($input['client_id'] ?? '');
                    $sec = trim($input['client_secret'] ?? '');
                    if (empty($id))  return ['valid' => false, 'error' => 'Client ID required'];
                    if (empty($sec)) return ['valid' => false, 'error' => 'Client Secret required'];
                    if (!str_contains($id, 'apps.googleusercontent.com')) {
                        return ['valid' => false, 'error' => 'Google OAuth client IDs end with .apps.googleusercontent.com — yours doesn\'t. Did you copy the wrong value?'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'google_client_id'     => trim($state['client_id']),
                        'google_client_secret' => trim($state['client_secret']),
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        if (!$this->is_configured()) return ['success' => false, 'error' => 'OAuth credentials not saved.'];
        return [
            'success' => true,
            'details' => ['note' => 'OAuth credentials saved. Connect each site individually via "Connect Google" on the site page.'],
        ];
    }
}
