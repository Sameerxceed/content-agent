<?php
/**
 * Unified Integration Setup API.
 *
 * Actions (POST JSON):
 *   - get_state    : load wizard progress for current user
 *   - verify_step  : run a step's verify() — does NOT save, just validates input
 *   - save_step    : verify() + save() + advance current_step
 *   - test         : run full integration test() after final step
 *   - reset        : wipe progress, start over
 *
 * Body: { action, integration, step?, input? }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/setup_wizards/registry.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

// Only super-admins can manage shared integrations. Customers shouldn't be
// able to view, edit, or test shared API keys belonging to the platform.
if (!auth_is_super_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: integration management is super-admin only.']);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action      = $input['action'] ?? '';
$integration = trim($input['integration'] ?? '');

if (!$action || !$integration) {
    http_response_code(400);
    echo json_encode(['error' => 'action and integration required']);
    exit;
}

$wizard = setup_wizard($integration);
if (!$wizard) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown integration: ' . $integration]);
    exit;
}

/** Load or create the progress row for this user+integration. */
function progress_load(PDO $db, int $user_id, string $integration): array
{
    $stmt = $db->prepare('SELECT * FROM integration_setup_progress WHERE user_id = ? AND integration = ?');
    $stmt->execute([$user_id, $integration]);
    $row = $stmt->fetch();
    if (!$row) {
        $db->prepare('INSERT INTO integration_setup_progress (user_id, integration, current_step, status, state_json) VALUES (?, ?, 1, "in_progress", "{}")')
           ->execute([$user_id, $integration]);
        $stmt->execute([$user_id, $integration]);
        $row = $stmt->fetch();
    }
    $row['state']            = json_decode($row['state_json'] ?? '{}', true) ?: [];
    $row['last_test_parsed'] = json_decode($row['last_test_result'] ?? 'null', true);
    return $row;
}

function progress_save(PDO $db, int $progress_id, array $fields): void
{
    $set = []; $vals = [];
    foreach ($fields as $k => $v) {
        if ($k === 'state')              { $set[] = 'state_json = ?';        $vals[] = json_encode($v); }
        elseif ($k === 'last_test')      { $set[] = 'last_test_result = ?';  $vals[] = json_encode($v); }
        elseif ($k === 'clear_last_test'){ $set[] = 'last_test_result = NULL'; }
        elseif ($k === 'current_step')   { $set[] = 'current_step = ?';      $vals[] = (int)$v; }
        elseif ($k === 'status')         { $set[] = 'status = ?';            $vals[] = $v; }
        elseif ($k === 'touch')          { $set[] = 'last_attempted_at = NOW()'; }
    }
    if (!$set) return;
    $vals[] = $progress_id;
    $db->prepare('UPDATE integration_setup_progress SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
}

$progress = progress_load($db, $user_id, $integration);
$steps    = $wizard->steps();

try {
    if ($action === 'get_state') {
        echo json_encode([
            'success'      => true,
            'integration'  => $integration,
            'name'         => $wizard->name(),
            'icon'         => $wizard->icon(),
            'purpose'      => $wizard->purpose(),
            'is_configured'=> $wizard->is_configured(),
            'status_line'  => $wizard->status_line(),
            'current_step' => (int)$progress['current_step'],
            'status'       => $progress['status'],
            'state'        => $progress['state'],
            'last_test'    => $progress['last_test_parsed'],
            'steps'        => array_map(function ($s, $i) {
                return [
                    'index'        => $i + 1,
                    'title'        => $s['title'] ?? '',
                    'why'          => $s['why'] ?? '',
                    'external_url' => $s['external_url'] ?? null,
                    'link_label'   => $s['link_label'] ?? null,
                    'instructions' => $s['instructions'] ?? [],
                    'fields'       => $s['fields'] ?? [],
                ];
            }, $steps, array_keys($steps)),
        ]);
        exit;
    }

    if ($action === 'verify_step' || $action === 'save_step') {
        $step_idx = (int)($input['step'] ?? 0);
        if ($step_idx < 1 || $step_idx > count($steps)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid step number']);
            exit;
        }
        $step       = $steps[$step_idx - 1];
        $step_input = $input['input'] ?? [];

        $verify_result = ['valid' => true];
        if (isset($step['verify']) && is_callable($step['verify'])) {
            $verify_result = $step['verify']($step_input);
        }
        if (empty($verify_result['valid'])) {
            echo json_encode(['success' => false, 'error' => $verify_result['error'] ?? 'Invalid input']);
            exit;
        }

        if ($action === 'verify_step') {
            echo json_encode(['success' => true]);
            exit;
        }

        $merged_state = array_merge($progress['state'], $step_input);
        if (isset($step['save']) && is_callable($step['save'])) {
            try {
                $step['save']($merged_state, $db, $user_id);
            } catch (Throwable $e) {
                // Save failed (config write error, DB error, etc). Surface it
                // at THIS step so the user sees the real cause — not 2 screens
                // later as a cryptic "key not saved" test failure.
                echo json_encode([
                    'success' => false,
                    'error'   => 'Could not save: ' . $e->getMessage(),
                ]);
                exit;
            }
        }
        $next = $step_idx + 1;
        $is_final = $next > count($steps);

        // Post-save invariant: on the FINAL step of a global-scope wizard, the
        // wizard must report is_configured() = true. If not, save claimed to
        // succeed but didn't actually persist — refuse to advance and tell the
        // user at this step, where they typed the key.
        if ($is_final && $wizard->scope() === 'global' && !$wizard->is_configured()) {
            echo json_encode([
                'success' => false,
                'error'   => 'The key passed validation but didn\'t persist to config. Check that config/config.php is writable by the web user, then retry.',
            ]);
            exit;
        }

        // Clear any stale last_test_result — credentials just changed, the old
        // result is no longer meaningful. Otherwise the wizard's final screen
        // would briefly show the previous failure before the new test runs.
        progress_save($db, (int)$progress['id'], [
            'state'           => $merged_state,
            'current_step'    => $is_final ? $step_idx : $next,
            'clear_last_test' => true,
            'touch'           => true,
        ]);

        echo json_encode([
            'success'      => true,
            'next_step'    => $is_final ? null : $next,
            'is_final'     => $is_final,
            'state'        => $merged_state,
        ]);
        exit;
    }

    if ($action === 'test') {
        $result = $wizard->test();
        $parsed = null;
        if (empty($result['success'])) {
            $parsed = $wizard->parse_error($result);
        }
        progress_save($db, (int)$progress['id'], [
            'last_test' => array_merge($result, $parsed ? ['parsed' => $parsed] : []),
            'status'    => !empty($result['success']) ? 'tested_ok' : 'failed',
            'touch'     => true,
        ]);
        echo json_encode([
            'success' => !empty($result['success']),
            'result'  => $result,
            'parsed_error' => $parsed,
        ]);
        exit;
    }

    if ($action === 'reset') {
        // Clear the wizard-owned config keys so a fresh attempt isn't fooled by
        // a stale value from a previous run (e.g. wrong key persisted before
        // test failed). Then wipe the progress row.
        $keys = $wizard->config_keys();
        if (!empty($keys)) {
            require_once __DIR__ . '/../../includes/setup_wizards/config_writer.php';
            config_unset($keys);
        }
        $db->prepare('DELETE FROM integration_setup_progress WHERE id = ?')->execute([$progress['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . $action]);
} catch (Throwable $e) {
    error_log('[integration-action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
