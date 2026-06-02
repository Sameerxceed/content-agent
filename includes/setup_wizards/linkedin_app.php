<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class LinkedInAppWizard extends SetupWizard
{
    public function id(): string      { return 'linkedin_app'; }
    public function name(): string    { return 'LinkedIn'; }
    public function purpose(): string { return 'Auto-post AI-tailored versions of your blog posts to LinkedIn (per site).'; }
    public function icon(): string    { return '💼'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('linkedin_client_id')) && !empty(config('linkedin_client_secret'));
    }

    public function config_keys(): array { return ['linkedin_client_id', 'linkedin_client_secret']; }

    public function status_line(?array $site = null): string
    {
        if (!$this->is_configured()) return 'App keys not set';
        return '✓ App keys configured · connect per site';
    }

    public function steps(): array
    {
        $redirect = config('app_url') . '/api/oauth/linkedin-callback.php';
        return [
            [
                'title' => 'Create a LinkedIn developer app',
                'why'   => 'LinkedIn requires every API caller to register an app. Free.',
                'external_url' => 'https://www.linkedin.com/developers/apps',
                'link_label' => 'Open LinkedIn Developer Portal ↗',
                'instructions' => [
                    'Click <strong>Create app</strong>.',
                    'App name: <code>ContentAgent</code>',
                    'LinkedIn Page: pick the company page that owns this app (Xceed Imagination, etc). You must be a Page admin.',
                    'Privacy URL: any working URL (your homepage works as a stopgap).',
                    'App logo: upload anything ~100×100.',
                    'Accept terms → Create app.',
                    '<strong>IMPORTANT — Products tab order matters.</strong> LinkedIn locks <strong>Community Management API</strong> if any other product is added first. Request ONLY <strong>Community Management API</strong> (it auto-approves for Page admins and provides company-page posting + page-listing + its own OAuth scopes — no separate Sign In product needed).',
                    'If you accidentally added <em>Share on LinkedIn</em> or <em>Sign In with LinkedIn using OpenID Connect</em> first, remove them via the three-dot menu on each product card. Then request Community Management API.',
                    'Wait until Community Management API shows <strong>Added</strong>.',
                    'Then <strong>Auth</strong> tab → <strong>OAuth 2.0 settings</strong> → <strong>Authorized redirect URLs</strong> → Add: <code>' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '</code> → Update.',
                    'Confirm <strong>OAuth 2.0 scopes</strong> includes <code>w_organization_social</code> and <code>r_organization_admin</code>.',
                    'On the same Auth tab, copy Client ID and Client Secret.',
                ],
                'fields' => [
                    ['key' => 'client_id',     'label' => 'Client ID',     'placeholder' => '...', 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => 'Client Secret', 'placeholder' => '...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    if (empty(trim($input['client_id'] ?? '')))     return ['valid' => false, 'error' => 'Client ID required'];
                    if (empty(trim($input['client_secret'] ?? ''))) return ['valid' => false, 'error' => 'Client Secret required'];
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'linkedin_client_id'     => trim($state['client_id']),
                        'linkedin_client_secret' => trim($state['client_secret']),
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
            'details' => ['note' => 'App keys saved. Per-site Connect happens via the OAuth flow on each site page — that\'s where the real test runs.'],
        ];
    }
}
