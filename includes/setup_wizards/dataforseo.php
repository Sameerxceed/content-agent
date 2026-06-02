<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class DataForSeoWizard extends SetupWizard
{
    public function id(): string      { return 'dataforseo'; }
    public function name(): string    { return 'DataForSEO'; }
    public function purpose(): string { return 'Real keyword search volume, difficulty, SERP positions, and competitor keyword databases. Closes most of the gap with Ahrefs/SEMrush at a fraction of the cost (~$0.001 per lookup).'; }
    public function icon(): string    { return '📊'; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return !empty(config('dataforseo_login')) && !empty(config('dataforseo_password'));
    }

    public function config_keys(): array { return ['dataforseo_login', 'dataforseo_password']; }

    public function status_line(?array $site = null): string
    {
        return $this->is_configured()
            ? '✓ Credentials saved · keyword enrichment ready'
            : 'Credentials not set';
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Create a DataForSEO account',
                'why'   => 'DataForSEO is the wholesale SEO data API used by smaller tools to avoid building their own crawled keyword database. Pay-per-lookup pricing (~$0.001 per keyword) — cheap at our usage scale.',
                'external_url' => 'https://app.dataforseo.com/register',
                'link_label'   => 'Open DataForSEO signup ↗',
                'instructions' => [
                    'Sign up with email + password.',
                    'You\'ll get $1 free credit to test (~1,000 keyword lookups).',
                    'No card required for the free credit. To go past $1, add a payment method later under Billing.',
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
                'title' => 'Get your API credentials',
                'why'   => 'DataForSEO uses HTTP Basic Auth — a login (your email) and a password (a generated API password, NOT your account password). Different from your account login.',
                'external_url' => 'https://app.dataforseo.com/api-access',
                'link_label'   => 'Open API Access ↗',
                'instructions' => [
                    'Open the API Access page (link above).',
                    'Your <strong>API Login</strong> is your account email.',
                    'Click <strong>Show Password</strong> next to the API password — copy the long generated string. <strong>This is different from your account password.</strong>',
                    'Paste both below.',
                ],
                'fields' => [
                    ['key' => 'login',    'label' => 'API Login (your email)', 'placeholder' => 'you@example.com', 'type' => 'text'],
                    ['key' => 'password', 'label' => 'API Password (generated)', 'placeholder' => '...', 'type' => 'password'],
                ],
                'verify' => function (array $input): array {
                    $login = trim($input['login'] ?? '');
                    $pass  = trim($input['password'] ?? '');
                    if (empty($login)) return ['valid' => false, 'error' => 'API Login required'];
                    if (empty($pass))  return ['valid' => false, 'error' => 'API Password required'];
                    if (!filter_var($login, FILTER_VALIDATE_EMAIL)) {
                        return ['valid' => false, 'error' => 'API Login should be your account email.'];
                    }
                    if (strlen($pass) < 8) {
                        return ['valid' => false, 'error' => 'That looks short. Did you paste the generated API password (not your account password)?'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'dataforseo_login'    => trim($state['login']),
                        'dataforseo_password' => trim($state['password']),
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $login = config('dataforseo_login');
        $pass  = config('dataforseo_password');
        if (empty($login) || empty($pass)) {
            return ['success' => false, 'error' => 'Credentials not saved'];
        }

        // Cheapest verification call — fetches account info / current balance. Free.
        $ch = curl_init('https://api.dataforseo.com/v3/appendix/user_data');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $login . ':' . $pass,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return ['success' => false, 'http_status' => $code, 'error' => 'HTTP ' . $code . ': ' . substr((string)$body, 0, 250)];
        }

        $data = json_decode($body, true);
        // DataForSEO wraps responses with status_code at the top level
        if (($data['status_code'] ?? 0) !== 20000) {
            return [
                'success' => false,
                'http_status' => $code,
                'error' => 'DataForSEO returned status_code ' . ($data['status_code'] ?? 'unknown') . ': ' . ($data['status_message'] ?? 'unknown'),
            ];
        }

        $money = $data['tasks'][0]['result'][0]['money']['balance'] ?? null;
        $note  = 'Authenticated successfully.';
        if ($money !== null) {
            $note .= ' Current balance: $' . number_format((float)$money, 2);
        }
        return ['success' => true, 'details' => ['note' => $note]];
    }

    public function parse_error(array $test_result): array
    {
        $code = $test_result['http_status'] ?? 0;
        $err  = $test_result['error'] ?? '';

        if ($code === 401) {
            return [
                'title'   => 'DataForSEO rejected the credentials',
                'message' => 'The login or API password is wrong. Remember: the API password is a generated string on the API Access page, NOT your account password.',
                'fixes'   => [
                    ['label' => 'Open API Access page', 'url' => 'https://app.dataforseo.com/api-access'],
                ],
            ];
        }
        if (stripos($err, 'status_code 40') !== false || stripos($err, 'balance') !== false) {
            return [
                'title'   => 'Out of credit',
                'message' => 'Your DataForSEO balance is zero. Top up to continue making API calls.',
                'fixes'   => [
                    ['label' => 'Open Billing', 'url' => 'https://app.dataforseo.com/billing'],
                ],
            ];
        }
        return parent::parse_error($test_result);
    }
}
