<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/config_writer.php';

class ResendWizard extends SetupWizard
{
    public function id(): string      { return 'resend'; }
    public function name(): string    { return 'Resend Email'; }
    public function purpose(): string { return 'Sends weekly digests and alert emails. Free 100/day, paid $20/mo for 50K. Easy to swap to AWS SES later if needed.'; }
    public function icon(): string    { return '📧'; }
    public function is_required(): bool { return false; }
    public function scope(): string   { return 'global'; }

    public function is_configured(?array $site = null): bool
    {
        return config('mail_driver') === 'resend' && !empty(config('resend_api_key'));
    }

    public function status_line(?array $site = null): string
    {
        if (!$this->is_configured()) return 'Not set up';
        $from = config('mail_from') ?: 'onboarding@resend.dev';
        return '✓ Sender: ' . $from;
    }

    public function steps(): array
    {
        return [
            [
                'title' => 'Create a Resend account + API key',
                'why'   => 'Resend is a transactional email service. Sign in with Google, generate a key.',
                'external_url' => 'https://resend.com/signup',
                'link_label' => 'Open Resend ↗',
                'instructions' => [
                    'Sign up (Google login recommended).',
                    'Dashboard → <strong>API Keys</strong> → click <strong>Create API Key</strong>.',
                    'Name: ContentAgent · Permission: Full access · Click Create.',
                    'Copy the <code>re_…</code> value (only shown once).',
                ],
                'fields' => [
                    [
                        'key'         => 'api_key',
                        'label'       => 'Resend API key',
                        'placeholder' => 're_...',
                        'type'        => 'password',
                    ],
                ],
                'verify' => function (array $input): array {
                    $key = trim($input['api_key'] ?? '');
                    if (empty($key)) return ['valid' => false, 'error' => 'API key required'];
                    if (!str_starts_with($key, 're_')) {
                        return ['valid' => false, 'error' => 'Resend keys start with "re_". Yours doesn\'t — double-check.'];
                    }
                    return ['valid' => true];
                },
            ],
            [
                'title' => 'Verify your sending domain (recommended)',
                'why'   => 'Without a verified domain, you can only send to YOUR OWN email. To send to clients, verify a domain you own. Skip this step to use <code>onboarding@resend.dev</code> (test sender, recipient-restricted).',
                'external_url' => 'https://resend.com/domains',
                'link_label' => 'Open Domains ↗',
                'instructions' => [
                    'Click <strong>Add Domain</strong> → enter a domain you own (e.g. <code>contentagent.xceedtech.in</code>).',
                    'If your DNS is on GoDaddy, click the "Domain Connect" button — it auto-adds the records.',
                    'Otherwise add the 3 DNS records they show (SPF + DKIM + DMARC) manually.',
                    'Wait 10-30 min for "Verified" status (green).',
                    'Paste the sender address below (e.g. <code>digest@yourdomain.com</code>).',
                ],
                'fields' => [
                    [
                        'key'         => 'from',
                        'label'       => 'Sender email address',
                        'placeholder' => 'digest@yourdomain.com  (or leave empty to use onboarding@resend.dev)',
                        'type'        => 'text',
                    ],
                    [
                        'key'         => 'from_name',
                        'label'       => 'Sender display name',
                        'placeholder' => 'ContentAgent',
                        'type'        => 'text',
                    ],
                ],
                'verify' => function (array $input): array {
                    $from = trim($input['from'] ?? '');
                    if (!empty($from) && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                        return ['valid' => false, 'error' => 'That doesn\'t look like a valid email address.'];
                    }
                    return ['valid' => true];
                },
                'save' => function (array $state, PDO $db, int $user_id): void {
                    config_write([
                        'mail_driver'    => 'resend',
                        'resend_api_key' => trim($state['api_key']),
                        'mail_from'      => trim($state['from'] ?? '') ?: 'onboarding@resend.dev',
                        'mail_from_name' => trim($state['from_name'] ?? '') ?: 'ContentAgent',
                    ]);
                },
            ],
        ];
    }

    public function test(?array $site = null): array
    {
        $key = config('resend_api_key');
        if (empty($key)) return ['success' => false, 'error' => 'API key not saved.'];

        // Send a test email to the logged-in user
        require_once __DIR__ . '/../auth.php';
        $user = auth_user();
        $to = $user['email'] ?? null;
        if (!$to) return ['success' => false, 'error' => 'No logged-in user email to send the test to.'];

        require_once __DIR__ . '/../mailer.php';
        $result = mailer_send(
            $to,
            'ContentAgent · Resend test',
            mailer_wrap('Resend test', '<p>Hi,</p><p>If you see this, Resend is wired up correctly. Weekly digests will now send from <code>' . htmlspecialchars(config('mail_from'), ENT_QUOTES, 'UTF-8') . '</code>.</p>')
        );

        return $result;
    }

    public function parse_error(array $test_result): array
    {
        $err = $test_result['error'] ?? '';

        if (str_contains($err, '422') || str_contains(strtolower($err), 'recipient')) {
            return [
                'title' => 'Recipient not allowed (likely no verified domain yet)',
                'message' => 'On Resend\'s free tier with the <code>onboarding@resend.dev</code> sender, you can only send to the email address you signed up with. To send to other recipients, verify your own domain in step 2.',
                'fixes' => [
                    [
                        'label' => 'Verify a domain you own',
                        'url' => 'https://resend.com/domains',
                    ],
                ],
            ];
        }

        if (str_contains($err, '401') || str_contains(strtolower($err), 'unauthorized')) {
            return [
                'title' => 'API key rejected',
                'message' => 'The key value didn\'t work. Try regenerating one.',
                'fixes' => [
                    ['label' => 'Open API keys', 'url' => 'https://resend.com/api-keys'],
                ],
            ];
        }

        return parent::parse_error($test_result);
    }
}
