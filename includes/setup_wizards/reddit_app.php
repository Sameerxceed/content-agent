<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

/**
 * Reddit App credentials wizard.
 * Per-site OAuth happens via the Connect button on the site page (uses these creds).
 */
class RedditAppWizard extends SetupWizard
{
    public function id(): string      { return 'reddit_app'; }
    public function name(): string    { return 'Reddit'; }
    public function purpose(): string { return 'Search Reddit (for AI Presence) + post to Reddit per site. Reddit blocks unauthenticated server-side calls.'; }
    public function icon(): string    { return '🔴'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('reddit_client_id')) && !empty(config('reddit_client_secret'));
    }

    public function status_line(?array $site = null): string
    {
        if (!$this->is_configured()) return 'App keys not set';
        return '✓ App keys configured · connect per site for posting';
    }

    public function steps(): array
    {
        $redirect = config('app_url') . '/api/oauth/reddit-callback.php';
        return [
            [
                'title' => 'Create a Reddit app',
                'why'   => 'Reddit requires every API caller to register a free "app" with their account. Takes 60 seconds.',
                'external_url' => 'https://www.reddit.com/prefs/apps',
                'link_label' => 'Open Reddit app preferences ↗',
                'instructions' => [
                    'Sign in to Reddit (use the account you want to post from later).',
                    'Scroll down → click <strong>create another app...</strong> (or "create an app" if it\'s your first).',
                    'Name: <code>ContentAgent</code>',
                    'Type: <strong>web app</strong>',
                    'Description: optional',
                    'About URL: optional',
                    'Redirect URI: <code>' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '</code> ← exact, no trailing slash',
                    'Click <strong>create app</strong>.',
                    'You\'ll see: a short ID (under the app name, ~14 chars) and a "secret" field. Copy both.',
                ],
                'fields' => [
                    [
                        'key'         => 'client_id',
                        'label'       => 'Client ID (the ~14 char string under the app name)',
                        'placeholder' => 'aB3cD4eF5g6H7i',
                        'type'        => 'text',
                    ],
                    [
                        'key'         => 'client_secret',
                        'label'       => 'Client Secret',
                        'placeholder' => '...',
                        'type'        => 'password',
                    ],
                ],
                'verify' => function (array $input): array {
                    $id = trim($input['client_id'] ?? '');
                    $sec = trim($input['client_secret'] ?? '');
                    if (empty($id))  return ['valid' => false, 'error' => 'Client ID required'];
                    if (empty($sec)) return ['valid' => false, 'error' => 'Client Secret required'];
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'reddit_client_id'     => trim($state['client_id']),
                        'reddit_client_secret' => trim($state['client_secret']),
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $id  = config('reddit_client_id');
        $sec = config('reddit_client_secret');
        if (empty($id) || empty($sec)) return ['success' => false, 'error' => 'Keys not saved.'];

        // Probe the OAuth token endpoint with client_credentials. Reddit will reject
        // this grant (it's not allowed for installed apps), but a 401/Unauthorized
        // response tells us the credentials are syntactically valid and reaching Reddit.
        $ch = curl_init('https://www.reddit.com/api/v1/access_token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => $id . ':' . $sec,
            CURLOPT_USERAGENT      => 'web:contentagent:v1.0',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 200 = valid (client_credentials grant accepted, unlikely for web apps).
        // 401 with body mentioning unsupported_grant_type or invalid_grant = creds valid but grant blocked. OK.
        // 401 with "401" body = bad credentials.
        if ($code === 200) return ['success' => true, 'details' => ['note' => 'Credentials accepted.']];
        if ($code === 401 && (str_contains($body, 'unsupported_grant_type') || str_contains($body, 'grant_type'))) {
            return ['success' => true, 'details' => ['note' => 'Credentials valid. Connect a Reddit account per-site to enable posting.']];
        }
        return ['success' => false, 'http_status' => $code, 'error' => $body];
    }

    public function parse_error(array $test_result): array
    {
        if (($test_result['http_status'] ?? 0) === 401) {
            return [
                'title' => 'Reddit rejected the credentials',
                'message' => 'Your client ID or secret is wrong. Double-check by copying again from the Reddit apps page. The ID is the short string under the app name (not the description or anything else).',
                'fixes' => [
                    ['label' => 'Open Reddit apps', 'url' => 'https://www.reddit.com/prefs/apps'],
                ],
            ];
        }
        return parent::parse_error($test_result);
    }
}
