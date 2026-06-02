<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class TwitterAppWizard extends SetupWizard
{
    public function id(): string      { return 'twitter_app'; }
    public function name(): string    { return 'Twitter / X'; }
    public function purpose(): string { return 'Auto-post AI-generated tweet threads from your blog posts (per site).'; }
    public function icon(): string    { return '🐦'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('twitter_client_id')) && !empty(config('twitter_client_secret'));
    }

    public function config_keys(): array { return ['twitter_client_id', 'twitter_client_secret']; }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured() ? '✓ App keys configured · connect per site' : 'App keys not set';
    }

    public function steps(): array
    {
        $redirect = config('app_url') . '/api/oauth/twitter-callback.php';
        return [
            [
                'title' => 'Create a Twitter developer project + app',
                'why'   => 'Twitter requires a developer account (free tier exists). Important: the OAuth 2.0 keys are separate from the legacy "API key/secret" — make sure you grab the right ones.',
                'external_url' => 'https://developer.x.com/en/portal/projects-and-apps',
                'link_label' => 'Open Twitter Developer Portal ↗',
                'instructions' => [
                    'Sign in with the Twitter/X account you want to post FROM (or admin account).',
                    'If you don\'t have a developer account, sign up (free tier is fine for low-volume posting).',
                    'Create a <strong>Project</strong> → inside it, create an <strong>App</strong>.',
                    'Inside the app, go to <strong>User authentication settings</strong> → click <strong>Set up</strong>.',
                    'App permissions: <strong>Read and write</strong>.',
                    'Type of App: <strong>Web App, Automated App or Bot</strong>.',
                    'Callback URI: <code>' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '</code>',
                    'Website URL: any working URL.',
                    'Save settings.',
                    'After saving, you\'ll get an OAuth 2.0 Client ID and Client Secret <strong>specifically for this app</strong> (one-time reveal — copy both NOW).',
                ],
                'fields' => [
                    ['key' => 'client_id',     'label' => 'OAuth 2.0 Client ID', 'placeholder' => '...', 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => 'OAuth 2.0 Client Secret', 'placeholder' => '...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    if (empty(trim($input['client_id'] ?? '')))     return ['valid' => false, 'error' => 'Client ID required'];
                    if (empty(trim($input['client_secret'] ?? ''))) return ['valid' => false, 'error' => 'Client Secret required'];
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'twitter_client_id'     => trim($state['client_id']),
                        'twitter_client_secret' => trim($state['client_secret']),
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        if (!$this->is_configured()) return ['success' => false, 'error' => 'Keys not saved.'];
        return [
            'success' => true,
            'details' => ['note' => 'App keys saved. Per-site Connect happens via the OAuth flow on each site page.'],
        ];
    }
}
