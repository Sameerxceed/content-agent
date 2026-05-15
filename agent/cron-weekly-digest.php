<?php
/**
 * Weekly digest email per site owner.
 *
 * Aggregates the past week's activity:
 *   - SEO score change
 *   - GSC clicks/impressions delta
 *   - New alerts (competitors, brand mentions, rank drops, etc.)
 *   - Top content gaps
 *   - Posts published
 * And sends a single email to the site owner.
 *
 * Run via: cron-runner.php weekly-digest
 */

require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/performance.php';
require_once __DIR__ . '/../includes/aeo.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "Weekly digest for " . count($sites) . " sites\n";

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Respect opt-out
    if (empty($site['email_digest'])) {
        echo "  skip #{$sid}: digest opt-out\n";
        continue;
    }

    // Get owner email — but prefer site.digest_email override when set,
    // so the owner can keep their login email different from the digest recipient.
    $stmt = $db->prepare('SELECT email, name FROM users WHERE id = ?');
    $stmt->execute([$site['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        echo "  skip #{$sid}: no owner user\n";
        continue;
    }
    $recipient = !empty($site['digest_email']) ? $site['digest_email'] : ($user['email'] ?? '');
    if (empty($recipient)) {
        echo "  skip #{$sid}: no recipient (set sites.digest_email or users.email)\n";
        continue;
    }

    echo "  building digest for #{$sid} {$site['domain']} → {$recipient}\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $site, $user, $recipient) {
        // ── SEO score this week vs last week ────────────────────────
        $stmt = $db->prepare('SELECT score, run_at FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 2');
        $stmt->execute([$sid]);
        $audits = $stmt->fetchAll();
        $score_now = $audits[0]['score'] ?? null;
        $score_prev = $audits[1]['score'] ?? null;
        $score_delta = ($score_now !== null && $score_prev !== null) ? ($score_now - $score_prev) : null;

        // ── GSC totals (last 7 days vs the 7 before) ────────────────
        $stmt = $db->prepare('SELECT COALESCE(SUM(clicks), 0) c, COALESCE(SUM(impressions), 0) i FROM keywords WHERE site_id = ?');
        $stmt->execute([$sid]);
        $gsc_now = $stmt->fetch();

        // ── Alerts in the last 7 days ───────────────────────────────
        $stmt = $db->prepare('SELECT * FROM alerts WHERE site_id = ? AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY severity = "critical" DESC, severity = "warning" DESC, detected_at DESC LIMIT 20');
        $stmt->execute([$sid]);
        $alerts = $stmt->fetchAll();

        // ── Posts published this week ───────────────────────────────
        $stmt = $db->prepare('SELECT id, title, slug, published_at FROM posts WHERE site_id = ? AND status = "published" AND published_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY published_at DESC LIMIT 10');
        $stmt->execute([$sid]);
        $posts = $stmt->fetchAll();

        // ── Top open content gaps ───────────────────────────────────
        $stmt = $db->prepare("SELECT topic, competitor_count, estimated_demand FROM content_gaps WHERE site_id = ? AND status = 'open' ORDER BY competitor_count DESC, estimated_demand DESC LIMIT 5");
        $stmt->execute([$sid]);
        $gaps = $stmt->fetchAll();

        // ── Performance buckets (winners / decay) ───────────────────
        $perf_winners = [];
        $perf_decay = [];
        try {
            $buckets = performance_buckets($db, $sid);
            $perf_winners = array_slice($buckets['winners'] ?? [], 0, 3);
            $perf_decay   = array_slice($buckets['decay']   ?? [], 0, 3);
        } catch (Throwable $e) {}

        // ── AEO citation rate ───────────────────────────────────────
        $aeo_summary = null;
        try {
            $aeo_summary = aeo_site_summary($db, $sid);
            if (($aeo_summary['total_queries'] ?? 0) === 0) $aeo_summary = null;
        } catch (Throwable $e) {}

        // ── Skip if nothing happened ────────────────────────────────
        if (empty($alerts) && empty($posts) && empty($gaps) && empty($perf_winners) && empty($perf_decay) && $score_delta === null) {
            echo "    no activity this week — skipping send\n";
            return ['sent' => false, 'reason' => 'no activity'];
        }

        // ── Build the HTML ──────────────────────────────────────────
        $base_url = config('app_url') ?: 'https://contentagent.xceedtech.in';
        $site_url = $base_url . '/dashboard/site.php?id=' . $sid;
        $name = htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8');

        ob_start();
        ?>
        <p>Hi <?= htmlspecialchars($user['name'] ?: 'there', ENT_QUOTES, 'UTF-8') ?>,</p>
        <p>Here's what ContentAgent saw for <strong><?= $name ?></strong> this week:</p>

        <?php if ($score_now !== null): ?>
        <div style="background:#f8fafc;padding:12px 14px;border-radius:6px;margin:14px 0;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">SEO Score</div>
            <div style="font-size:28px;font-weight:700;color:<?= $score_now >= 80 ? '#10b981' : ($score_now >= 50 ? '#f59e0b' : '#ef4444') ?>;">
                <?= (int)$score_now ?>/100
                <?php if ($score_delta !== null && $score_delta !== 0): ?>
                    <span style="font-size:13px;color:<?= $score_delta > 0 ? '#10b981' : '#ef4444' ?>;font-weight:600;">
                        (<?= $score_delta > 0 ? '+' : '' ?><?= $score_delta ?>)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ((int)$gsc_now['i'] > 0): ?>
        <div style="margin:14px 0;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Google Search Console</div>
            <div style="font-size:14px;color:#1f2937;">
                <strong><?= number_format($gsc_now['i']) ?></strong> impressions ·
                <strong><?= number_format($gsc_now['c']) ?></strong> clicks
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($perf_winners)): ?>
        <div style="margin:18px 0 8px;font-weight:600;color:#1f2937;">🔥 What's working — write more like these</div>
        <?php foreach ($perf_winners as $w): $cms = $w['channels']['cms'] ?? []; ?>
        <div style="border-left:3px solid #10b981;padding:6px 10px;margin:6px 0;background:#f0fdf4;">
            <div style="font-size:13px;font-weight:600;color:#065f46;"><?= htmlspecialchars($w['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size:11px;color:#65a30d;margin-top:2px;">
                <?= number_format($cms['clicks'] ?? 0) ?> clicks ·
                <?= number_format($cms['impressions'] ?? 0) ?> impressions ·
                <?= htmlspecialchars($w['reason'] ?? 'trending up', ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($perf_decay)): ?>
        <div style="margin:18px 0 8px;font-weight:600;color:#1f2937;">📉 Slipping — refresh candidates</div>
        <?php foreach ($perf_decay as $d): $cms = $d['channels']['cms'] ?? []; ?>
        <div style="border-left:3px solid #f59e0b;padding:6px 10px;margin:6px 0;background:#fffbeb;">
            <div style="font-size:13px;font-weight:600;color:#92400e;"><?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size:11px;color:#a16207;margin-top:2px;">
                <?= number_format($cms['impressions'] ?? 0) ?> impressions ·
                CTR <?= isset($cms['ctr']) ? $cms['ctr'] . '%' : '—' ?> ·
                <?= htmlspecialchars($d['reason'] ?? 'traffic dropping', ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($aeo_summary): ?>
        <div style="margin:18px 0 8px;font-weight:600;color:#1f2937;">🔭 AI search citations</div>
        <div style="background:#f8fafc;padding:10px 14px;border-radius:6px;font-size:13px;color:#1f2937;">
            Cited on <strong><?= (int)$aeo_summary['cited_now'] ?></strong> of
            <strong><?= (int)$aeo_summary['total_queries'] ?></strong> tracked queries
            (<?= $aeo_summary['citation_rate'] ?>% citation rate).
            <?php if (!empty($aeo_summary['top_competitors'])):
                $top = array_slice($aeo_summary['top_competitors'], 0, 3);
                $names = array_map(fn($c) => $c['domain'], $top);
            ?>
            <div style="font-size:11px;color:#64748b;margin-top:4px;">
                Top competitor citers: <?= htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts)): ?>
        <div style="margin:18px 0 8px;font-weight:600;color:#1f2937;">📣 What's new (<?= count($alerts) ?>)</div>
        <?php foreach (array_slice($alerts, 0, 8) as $a):
            $sev_color = $a['severity'] === 'critical' ? '#ef4444' : ($a['severity'] === 'warning' ? '#f59e0b' : '#3b82f6');
        ?>
        <div style="border-left:3px solid <?= $sev_color ?>;padding:6px 10px;margin:6px 0;background:#fafbfc;">
            <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($a['detail'])): ?>
            <div style="font-size:12px;color:#64748b;white-space:pre-line;margin-top:2px;"><?= htmlspecialchars(mb_substr($a['detail'], 0, 300), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($posts)): ?>
        <div style="margin:18px 0 8px;font-weight:600;color:#1f2937;">✍️ Posts published (<?= count($posts) ?>)</div>
        <ul style="margin:6px 0 14px;padding-left:20px;">
        <?php foreach ($posts as $p): ?>
            <li style="font-size:13px;margin:4px 0;"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($gaps)): ?>
        <div style="margin:18px 0 8px;font-weight:600;color:#1f2937;">💡 Content gaps to consider (<?= count($gaps) ?>)</div>
        <ul style="margin:6px 0 14px;padding-left:20px;">
        <?php foreach ($gaps as $g): ?>
            <li style="font-size:13px;margin:4px 0;">
                <strong><?= htmlspecialchars($g['topic'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span style="color:#64748b;font-size:11px;">— covered by <?= (int)$g['competitor_count'] ?> competitors<?= $g['estimated_demand'] ? ', ~' . number_format($g['estimated_demand']) . ' imp/mo' : '' ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div style="margin-top:24px;padding-top:14px;border-top:1px solid #e2e8f0;">
            <a href="<?= htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block;padding:10px 18px;background:#1e3a5f;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:13px;">Open dashboard →</a>
        </div>
        <?php
        $inner = ob_get_clean();
        $html = mailer_wrap('Weekly digest — ' . $site['name'], $inner);

        $subject = 'ContentAgent · ' . $site['name'] . ' · weekly digest';
        $result = mailer_send($recipient, $subject, $html);

        if ($result['success']) {
            $db->prepare('UPDATE sites SET last_digest_sent = NOW() WHERE id = ?')->execute([$sid]);
            echo "    sent\n";
            return ['sent' => true, 'to' => $recipient, 'alerts' => count($alerts), 'posts' => count($posts), 'gaps' => count($gaps)];
        } else {
            throw new \RuntimeException('Mailer failed: ' . ($result['error'] ?? 'unknown'));
        }
    });
}

echo "Weekly digest complete.\n";
