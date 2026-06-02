<?php
/**
 * Setup Wizard base class — every integration with a guided setup extends this.
 *
 * Pattern:
 *   - Each integration defines an ORDERED list of STEPS.
 *   - Each step has: title, why_explanation, external_link, input fields,
 *     a verify() to validate the input, and a save() to persist (config or integrations).
 *   - After the final step, test() runs the real integration end-to-end.
 *   - If test() fails, parse_error() translates the raw failure into actionable guidance
 *     (the institutional knowledge from past pain — billing not linked, key restrictions, etc.)
 */

abstract class SetupWizard
{
    /** @return string short ID, e.g. "google_cse" */
    abstract public function id(): string;

    /** @return string display name for UI */
    abstract public function name(): string;

    /** @return string one-line description of why this integration matters */
    abstract public function purpose(): string;

    /** @return string emoji or short icon */
    public function icon(): string { return '🔌'; }

    /** @return bool is this integration required for ContentAgent to be useful? */
    public function is_required(): bool { return false; }

    /**
     * Per-site or global? Most are global (one config for the whole app).
     * OAuth-based ones are per-site (each site connects its own LinkedIn).
     */
    public function scope(): string { return 'global'; }  // 'global' or 'site'

    /** Detects if the integration is already configured (key check, no API call). */
    abstract public function is_configured(?array $site = null): bool;

    /**
     * Steps definition. Each step is an associative array with:
     *   - title:        Short step heading
     *   - why:          1-2 line explanation of why this step
     *   - external_url: (optional) link to open in a new tab (e.g. Google console)
     *   - link_label:   (optional) label for the external link button
     *   - fields:       array of { key, label, placeholder, type='text|password|textarea' }
     *   - verify:       callable(array $input): array { valid: bool, error?: string }
     *   - save:         callable(array $input, PDO $db, int $user_id): void
     *
     * @return array
     */
    abstract public function steps(): array;

    /**
     * Run the full integration end-to-end against real credentials.
     * Called after all steps complete.
     *
     * @return array { success: bool, error?: string, details?: array }
     */
    abstract public function test(?array $site = null): array;

    /**
     * Translate raw test() failures into specific actionable guidance.
     * Default: pass through the error message. Override per-integration to capture
     * the failure modes we've encountered before.
     *
     * @param array $test_result the array returned by test()
     * @return array {
     *   title: string,
     *   message: string,
     *   fixes: [{ label, url? }],
     *   retry_after: ?string,
     * }
     */
    public function parse_error(array $test_result): array
    {
        return [
            'title'   => 'Integration test failed',
            'message' => $test_result['error'] ?? 'Unknown error',
            'fixes'   => [],
            'retry_after' => null,
        ];
    }

    /**
     * Optional — what to show on the hub card when this integration is connected.
     * E.g. "Connected · last sync 2 days ago"
     */
    public function status_line(?array $site = null): string
    {
        return $this->is_configured($site) ? '✓ Configured' : 'Not set up';
    }

    /**
     * Config keys this wizard writes — used by reset() to wipe them cleanly so
     * a "Reset & start over" actually starts over (otherwise a stale bad key
     * lingers in config.php and the next attempt can be confused by it).
     * Override per-wizard if it persists to config. OAuth wizards that write
     * elsewhere (e.g. site_integrations table) can override reset() instead.
     *
     * @return string[]
     */
    public function config_keys(): array
    {
        return [];
    }
}
