<?php
/**
 * Team / Multi-user helpers.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Create a new team.
 */
function team_create(PDO $db, string $name, int $owner_id): int
{
    $stmt = $db->prepare('INSERT INTO teams (name, owner_id) VALUES (?, ?)');
    $stmt->execute([$name, $owner_id]);
    $team_id = $db->lastInsertId();

    // Add owner as admin member
    $stmt = $db->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (?, ?, "admin")');
    $stmt->execute([$team_id, $owner_id]);

    return $team_id;
}

/**
 * Invite a user to a team by email.
 */
function team_invite(PDO $db, int $team_id, string $email, string $role, int $invited_by): array
{
    $email = strtolower(trim($email));

    // Check if already a member
    $stmt = $db->prepare('SELECT u.id FROM users u JOIN team_members tm ON u.id = tm.user_id WHERE u.email = ? AND tm.team_id = ?');
    $stmt->execute([$email, $team_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'User is already a team member'];
    }

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

    $stmt = $db->prepare('INSERT INTO invitations (team_id, email, role, token, invited_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$team_id, $email, $role, $token, $invited_by, $expires]);

    return ['success' => true, 'token' => $token, 'expires' => $expires];
}

/**
 * Accept an invitation.
 */
function team_accept_invite(PDO $db, string $token, int $user_id): array
{
    $stmt = $db->prepare('SELECT * FROM invitations WHERE token = ? AND accepted_at IS NULL AND expires_at > NOW()');
    $stmt->execute([$token]);
    $invite = $stmt->fetch();

    if (!$invite) {
        return ['success' => false, 'error' => 'Invalid or expired invitation'];
    }

    // Add to team
    try {
        $stmt = $db->prepare('INSERT INTO team_members (team_id, user_id, role, invited_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$invite['team_id'], $user_id, $invite['role'], $invite['invited_by']]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            return ['success' => false, 'error' => 'Already a member'];
        }
        throw $e;
    }

    // Mark invitation as accepted
    $db->prepare('UPDATE invitations SET accepted_at = NOW() WHERE id = ?')->execute([$invite['id']]);

    return ['success' => true, 'team_id' => $invite['team_id'], 'role' => $invite['role']];
}

/**
 * Get user's teams.
 */
function team_get_user_teams(PDO $db, int $user_id): array
{
    $stmt = $db->prepare('
        SELECT t.*, tm.role, (SELECT COUNT(*) FROM team_members tm2 WHERE tm2.team_id = t.id) as member_count
        FROM teams t
        JOIN team_members tm ON t.id = tm.team_id
        WHERE tm.user_id = ?
        ORDER BY t.name
    ');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Get team members.
 */
function team_get_members(PDO $db, int $team_id): array
{
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.email, tm.role, tm.joined_at
        FROM users u
        JOIN team_members tm ON u.id = tm.user_id
        WHERE tm.team_id = ?
        ORDER BY tm.role, u.name
    ');
    $stmt->execute([$team_id]);
    return $stmt->fetchAll();
}

/**
 * Check if user has at least the given role in a team.
 */
function team_check_permission(PDO $db, int $team_id, int $user_id, string $min_role): bool
{
    $role_hierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3];

    $stmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$team_id, $user_id]);
    $member = $stmt->fetch();

    if (!$member) return false;

    return ($role_hierarchy[$member['role']] ?? 0) >= ($role_hierarchy[$min_role] ?? 0);
}

/**
 * Remove a member from a team.
 */
function team_remove_member(PDO $db, int $team_id, int $user_id): bool
{
    // Don't allow removing the owner
    $stmt = $db->prepare('SELECT owner_id FROM teams WHERE id = ?');
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();
    if ($team && $team['owner_id'] == $user_id) return false;

    $stmt = $db->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$team_id, $user_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Update member role.
 */
function team_update_role(PDO $db, int $team_id, int $user_id, string $role): bool
{
    $stmt = $db->prepare('UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$role, $team_id, $user_id]);
    return $stmt->rowCount() > 0;
}
