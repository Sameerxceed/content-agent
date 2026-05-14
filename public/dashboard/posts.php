<?php
/**
 * Dashboard — Posts management (content queue).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();
$action = $_GET['action'] ?? 'list';

// ── Handle POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash_error'] = 'Invalid form submission.';
        redirect('/dashboard/posts.php');
    }

    $post_action = $_POST['action'] ?? '';
    $post_id = (int)($_POST['id'] ?? 0);

    // Verify ownership
    $stmt = $db->prepare('SELECT p.* FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
    $stmt->execute([$post_id, $user_id]);
    $post = $stmt->fetch();

    if (!$post) {
        $_SESSION['flash_error'] = 'Post not found.';
        redirect('/dashboard/posts.php');
    }

    if ($post_action === 'approve') {
        $db->prepare('UPDATE posts SET status = "approved" WHERE id = ?')->execute([$post_id]);
        $_SESSION['flash_success'] = 'Post approved.';
    } elseif ($post_action === 'publish') {
        $db->prepare('UPDATE posts SET status = "published", published_at = NOW() WHERE id = ?')->execute([$post_id]);
        // Regenerate llms.txt with new post
        require_once __DIR__ . '/../../includes/ai-seo.php';
        $stmt_site = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt_site->execute([$post['site_id']]);
        $pub_site = $stmt_site->fetch();
        if ($pub_site) { regenerate_llms_txt($pub_site, $db); }
        $_SESSION['flash_success'] = 'Post published!';
    } elseif ($post_action === 'reject') {
        $db->prepare('UPDATE posts SET status = "rejected" WHERE id = ?')->execute([$post_id]);
        $_SESSION['flash_success'] = 'Post rejected.';
    } elseif ($post_action === 'update') {
        $stmt = $db->prepare('UPDATE posts SET title = ?, slug = ?, body = ?, excerpt = ?, seo_title = ?, seo_description = ?, seo_keywords = ?, tags = ? WHERE id = ?');
        $stmt->execute([
            trim($_POST['title']),
            slugify(trim($_POST['title'])),
            $_POST['body'],
            trim($_POST['excerpt']),
            trim($_POST['seo_title']),
            trim($_POST['seo_description']),
            trim($_POST['seo_keywords']),
            json_encode(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))),
            $post_id,
        ]);
        $_SESSION['flash_success'] = 'Post updated.';
    } elseif ($post_action === 'delete') {
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$post_id]);
        $_SESSION['flash_success'] = 'Post deleted.';
        redirect('/dashboard/posts.php');
    }

    redirect('/dashboard/posts.php?action=edit&id=' . $post_id);
}

// ── Page content ────────────────────────────────────────
$page_title = 'Posts';

ob_start();

if ($action === 'edit' && isset($_GET['id'])):
    $stmt = $db->prepare('SELECT p.*, s.domain FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
    $stmt->execute([(int)$_GET['id'], $user_id]);
    $post = $stmt->fetch();

    if (!$post):
        echo '<div class="alert alert-error">Post not found.</div>';
    else:
        $tags = implode(', ', json_decode($post['tags'] ?? '[]', true) ?: []);
?>
    <div class="flex justify-between items-center mb-4">
        <div class="flex items-center gap-2">
            <a href="<?= url('/dashboard/posts.php') ?>" class="btn btn-outline btn-sm">&laquo; Back</a>
            <span class="badge badge-<?= $post['status'] ?>"><?= $post['status'] ?></span>
            <span class="badge badge-<?= $post['type'] === 'news' ? 'info' : 'draft' ?>"><?= $post['type'] ?></span>
            <span class="text-muted text-sm"><?= e($post['domain']) ?></span>
            <?php if ($post['status'] === 'published'): ?>
                <a href="https://<?= e($post['domain']) ?>/blog/<?= e($post['slug']) ?>" target="_blank" class="btn btn-outline btn-sm" style="color:var(--success);">View on site &rarr;</a>
            <?php endif; ?>
        </div>
        <div class="flex gap-2">
            <?php if ($post['status'] === 'draft'): ?>
                <form method="POST" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= $post['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                </form>
            <?php endif; ?>
            <?php if (in_array($post['status'], ['draft', 'approved'])): ?>
                <form method="POST" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="id" value="<?= $post['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Publish (local)</button>
                </form>
                <button onclick="publishToCMS(<?= $post['id'] ?>, this)" class="btn btn-accent btn-sm">Publish to CMS</button>
            <?php endif; ?>
            <?php if ($post['status'] === 'published'): ?>
                <a href="<?= url('/dashboard/social.php?site=' . $post['site_id'] . '&post=' . $post['id']) ?>" class="btn btn-outline btn-sm" style="border-color:#0A66C2;color:#0A66C2;text-decoration:none;">Share to Social</a>
            <?php endif; ?>
            <?php if ($post['type'] === 'blog'): ?>
                <a href="<?= url('/dashboard/social.php?site=' . $post['site_id'] . '&post=' . $post['id'] . '&action=carousel') ?>" class="btn btn-outline btn-sm" style="border-color:#E1306C;color:#E1306C;text-decoration:none;">Instagram Carousel</a>
            <?php endif; ?>
            <?php if ($post['status'] === 'draft'): ?>
                <form method="POST" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" value="<?= $post['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Reject</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $post['id'] ?>">

        <div class="grid-2">
            <div>
                <div class="card">
                    <div class="card-header">Content</div>
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" class="form-control" value="<?= e($post['title']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="excerpt">Excerpt</label>
                        <textarea id="excerpt" name="excerpt" class="form-control" rows="2"><?= e($post['excerpt'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="body">Body (HTML)</label>
                        <textarea id="body" name="body" class="form-control" rows="16" style="font-family: monospace; font-size: 12px;"><?= e($post['body']) ?></textarea>
                    </div>
                </div>
            </div>
            <div>
                <div class="card">
                    <div class="card-header">SEO</div>
                    <div class="form-group">
                        <label for="seo_title">SEO Title <span class="text-muted">(<?= mb_strlen($post['seo_title'] ?? '') ?>/60)</span></label>
                        <input type="text" id="seo_title" name="seo_title" class="form-control" value="<?= e($post['seo_title'] ?? '') ?>" maxlength="70">
                    </div>
                    <div class="form-group">
                        <label for="seo_description">Meta Description <span class="text-muted">(<?= mb_strlen($post['seo_description'] ?? '') ?>/160)</span></label>
                        <textarea id="seo_description" name="seo_description" class="form-control" rows="3" maxlength="170"><?= e($post['seo_description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="seo_keywords">Keywords</label>
                        <input type="text" id="seo_keywords" name="seo_keywords" class="form-control" value="<?= e($post['seo_keywords'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="tags">Tags (comma-separated)</label>
                        <input type="text" id="tags" name="tags" class="form-control" value="<?= e($tags) ?>">
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Info</div>
                    <table>
                        <tr><td class="text-muted">Created</td><td><?= format_date($post['created_at']) ?></td></tr>
                        <tr><td class="text-muted">Published</td><td><?= $post['published_at'] ? format_date($post['published_at']) : '—' ?></td></tr>
                        <tr><td class="text-muted">Words</td><td><?= str_word_count(strip_tags($post['body'])) ?></td></tr>
                        <?php if ($post['source_url']): ?>
                        <tr><td class="text-muted">Source</td><td><a href="<?= e($post['source_url']) ?>" target="_blank" class="text-sm">Original</a></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Publishing Channels -->
                <?php
                require_once __DIR__ . '/../../includes/channels/registry.php';
                $registry = channels_registry();
                $stmt = $db->prepare('SELECT * FROM post_channels WHERE post_id = ? ORDER BY channel');
                $stmt->execute([$post['id']]);
                $channel_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $by_channel = [];
                foreach ($channel_rows as $r) $by_channel[$r['channel']] = $r;

                // Site row (for is_configured checks — the post array doesn't have site fields)
                $site_stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
                $site_stmt->execute([$post['site_id']]);
                $site_row = $site_stmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="card" style="margin-top:14px;">
                    <div class="card-header">📡 Publishing Channels</div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                        Each channel works independently. Generate a variant → edit it → publish now or schedule for later.
                    </div>

                    <?php foreach ($registry->all() as $adapter):
                        $cid = $adapter->id();
                        $row = $by_channel[$cid] ?? null;
                        $configured = $adapter->is_configured($site_row);
                        $status = $row['status'] ?? null;
                        $status_styles = [
                            'published'  => ['#d1fae5','#065f46','✓ Published'],
                            'queued'     => [$row['scheduled_for'] ?? null ? '#dbeafe' : '#fef9c3', $row['scheduled_for'] ?? null ? '#1e40af' : '#854d0e', $row['scheduled_for'] ?? null ? '📅 Scheduled' : '⏳ Queued'],
                            'publishing' => ['#fef3c7','#92400e','… Publishing'],
                            'failed'     => ['#fecaca','#991b1b','✗ Failed'],
                            'draft'      => ['#f1f5f9','#64748b','📝 Draft'],
                        ];
                        [$bg, $fg, $label] = $status_styles[$status] ?? ['#f8fafc','#94a3b8','— Not yet'];
                        $variant = $row['variant_content'] ?? '';
                        $is_cms = $cid === 'cms';
                    ?>
                    <div style="border:1px solid var(--border);border-radius:6px;padding:10px 12px;margin-bottom:8px;background:#fff;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;">
                            <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                                <span style="display:inline-block;width:24px;height:24px;border-radius:4px;background:<?= $adapter->color() ?>;color:#fff;text-align:center;line-height:24px;font-size:12px;font-weight:600;flex-shrink:0;"><?= $adapter->icon() ?></span>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;font-size:13px;color:var(--primary);"><?= e($adapter->display_name()) ?></div>
                                    <?php if (!$configured): ?>
                                        <div style="font-size:11px;color:#f59e0b;margin-top:1px;"><?= e($adapter->setup_hint($site_row) ?: 'Not configured.') ?></div>
                                    <?php elseif ($row && $row['scheduled_for']): ?>
                                        <div style="font-size:11px;color:#1e40af;margin-top:1px;">Scheduled for <?= format_date($row['scheduled_for']) ?></div>
                                    <?php elseif ($row && !empty($row['external_url'])): ?>
                                        <a href="<?= e($row['external_url']) ?>" target="_blank" style="font-size:11px;color:#3b82f6;text-decoration:none;">View on <?= e(parse_url($row['external_url'], PHP_URL_HOST)) ?> ↗</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:<?= $bg ?>;color:<?= $fg ?>;flex-shrink:0;"><?= $label ?></span>
                        </div>

                        <?php if ($configured): ?>
                            <?php if ($is_cms): ?>
                                <!-- CMS: no variant editing, just publish/schedule -->
                                <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;">CMS publishes the full post body. No variant needed.</div>
                            <?php else: ?>
                                <!-- Variant editor -->
                                <div style="margin-top:6px;">
                                    <?php if (empty($variant) && $status !== 'published'): ?>
                                        <button type="button" onclick="channelAction(<?= $post['id'] ?>, '<?= $cid ?>', 'generate', null, this)" class="btn btn-outline btn-sm" style="font-size:11px;">✨ Generate <?= e($adapter->display_name()) ?> version</button>
                                    <?php else: ?>
                                        <textarea id="variant-<?= $cid ?>" rows="<?= $cid === 'twitter' ? 8 : 5 ?>" style="width:100%;border:1px solid var(--border);border-radius:4px;padding:8px;font-size:12px;font-family:inherit;line-height:1.5;" <?= $status === 'published' ? 'readonly' : '' ?>><?= e($variant) ?></textarea>
                                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= str_word_count($variant) ?> words · <?= mb_strlen($variant) ?> chars</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($status === 'failed' && !empty($row['error'])): ?>
                                <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:4px;padding:6px 10px;margin-top:6px;font-size:11px;color:#991b1b;"><?= e(mb_substr($row['error'], 0, 200)) ?></div>
                            <?php endif; ?>

                            <!-- Action buttons -->
                            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                                <?php if ($status === 'published'): ?>
                                    <button type="button" onclick="if(confirm('Delete this channel record? You can re-generate it.')) channelAction(<?= $post['id'] ?>, '<?= $cid ?>', 'delete', null, this)" class="btn btn-outline btn-sm" style="font-size:11px;color:#dc2626;">Clear record</button>
                                <?php elseif ($status === 'queued' && $row['scheduled_for']): ?>
                                    <button type="button" onclick="channelAction(<?= $post['id'] ?>, '<?= $cid ?>', 'cancel', null, this)" class="btn btn-outline btn-sm" style="font-size:11px;">Cancel schedule</button>
                                <?php else: ?>
                                    <?php if (!$is_cms && !empty($variant)): ?>
                                        <button type="button" onclick="channelAction(<?= $post['id'] ?>, '<?= $cid ?>', 'generate', null, this)" class="btn btn-outline btn-sm" style="font-size:11px;">🔄 Regenerate</button>
                                        <button type="button" onclick="channelAction(<?= $post['id'] ?>, '<?= $cid ?>', 'save', document.getElementById('variant-<?= $cid ?>').value, this)" class="btn btn-outline btn-sm" style="font-size:11px;">💾 Save edits</button>
                                    <?php endif; ?>
                                    <?php if ($is_cms || !empty($variant)): ?>
                                        <button type="button" onclick="publishChannelInline(<?= $post['id'] ?>, '<?= $cid ?>', '<?= $is_cms ? '' : 'variant-' . $cid ?>', this)" class="btn btn-accent btn-sm" style="font-size:11px;">▶ Publish now</button>
                                        <button type="button" onclick="scheduleChannel(<?= $post['id'] ?>, '<?= $cid ?>', '<?= $is_cms ? '' : 'variant-' . $cid ?>', this)" class="btn btn-outline btn-sm" style="font-size:11px;">📅 Schedule…</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="<?= url('/dashboard/posts.php') ?>" class="btn btn-outline">Back</a>
                </div>

                <div class="mt-4">
                    <form method="POST" onsubmit="return confirm('Delete this post?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $post['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete Post</button>
                    </form>
                </div>
            </div>
        </div>
    </form>

    <!-- Carousel Preview Modal -->
    <div id="carousel-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:1000;overflow:auto;">
        <div style="max-width:900px;margin:30px auto;background:#fff;border-radius:8px;padding:20px;position:relative;">
            <button onclick="document.getElementById('carousel-modal').style.display='none'" style="position:absolute;top:10px;right:14px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>
            <h3 style="margin-bottom:4px;color:var(--primary);">Instagram Carousel Preview</h3>
            <p class="text-sm text-muted mb-4">5 slides generated from your blog post. Screenshot each slide or use a tool to convert HTML to images.</p>
            <div id="carousel-slides" style="display:flex;gap:10px;overflow-x:auto;padding:10px 0;"></div>
            <div class="mt-4 flex gap-2">
                <a id="carousel-link" href="#" target="_blank" class="btn btn-primary btn-sm">Open Full Preview</a>
                <button onclick="document.getElementById('carousel-modal').style.display='none'" class="btn btn-outline btn-sm">Close</button>
            </div>
        </div>
    </div>

    <script>
    async function shareToSocial(postId) {
        if (!confirm('Post to all connected social media accounts?')) return;
        try {
            const res = await fetch('<?= url('/api/social-post.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({post_id: postId, platforms: ['linkedin', 'twitter', 'facebook']})
            });
            const data = await res.json();
            if (data.success) {
                let msg = 'Social posting results:\n';
                for (const [p, r] of Object.entries(data.results || {})) {
                    msg += p + ': ' + (r.success ? '✓ Posted' : '✗ ' + (r.error || 'Failed')) + '\n';
                }
                alert(msg);
            } else {
                alert('Error: ' + (data.error || 'Unknown'));
            }
        } catch(e) { alert('Request failed'); }
    }

    async function generateCarousel(postId, btn) {
        const orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Generating...';
        try {
            const res = await fetch('<?= url('/api/run-agent.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({agent: 'carousel-generator', site_id: <?= $post['site_id'] ?>, params: {post_id: postId}})
            });
            const data = await res.json();

            // Wait a few seconds for generation, then show preview
            btn.textContent = 'Loading preview...';
            setTimeout(() => {
                // Get the slug to find the carousel folder
                const slug = '<?= e($post['slug']) ?>';
                const basePath = '/contentagent/cache/carousels/' + slug;
                const previewUrl = basePath + '/preview.html';
                const slidesDiv = document.getElementById('carousel-slides');
                slidesDiv.innerHTML = '';

                for (let i = 1; i <= 6; i++) {
                    const slideUrl = basePath + '/slide-' + i + '.html';
                    const iframe = document.createElement('iframe');
                    iframe.src = slideUrl;
                    iframe.style.cssText = 'width:270px;height:270px;border:1px solid #ddd;border-radius:6px;flex-shrink:0;';
                    slidesDiv.appendChild(iframe);
                }

                document.getElementById('carousel-link').href = basePath + '/preview.html';
                document.getElementById('carousel-modal').style.display = 'block';
                btn.textContent = orig;
                btn.disabled = false;
            }, 5000);
        } catch(e) {
            alert('Failed to generate carousel');
            btn.textContent = orig;
            btn.disabled = false;
        }
    }

    async function publishToCMS(postId, btn) {
        if (!confirm('Publish this post to the live website?')) return;
        const orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Publishing...';
        try {
            const res = await fetch('<?= url('/api/publish.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({post_id: postId})
            });
            const data = await res.json();
            if (data.success) {
                alert('Published! View at: ' + data.url);
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown'));
                btn.textContent = orig;
                btn.disabled = false;
            }
        } catch(e) {
            alert('Request failed');
            btn.textContent = orig;
            btn.disabled = false;
        }
    }

    const CHANNEL_API = '<?= url('/api/channel-action.php') ?>';

    // Generic dispatcher
    async function channelAction(postId, channel, action, content, btn) {
        const orig = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = '…'; }
        try {
            const body = {action, post_id: postId, channel};
            if (content !== null && content !== undefined) body.content = content;
            const res = await fetch(CHANNEL_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });
            const data = await res.json();
            if (data.success) {
                if (action === 'save') {
                    if (btn) { btn.textContent = '✓ Saved'; setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1200); }
                } else {
                    location.reload();
                }
                return data;
            }
            alert('Failed: ' + (data.error || 'unknown'));
            if (btn) { btn.textContent = orig; btn.disabled = false; }
        } catch(e) {
            alert('Error: ' + e.message);
            if (btn) { btn.textContent = orig; btn.disabled = false; }
        }
    }

    // Publish-now: if a variant textarea exists, save its current content first
    async function publishChannelInline(postId, channel, variantElId, btn) {
        if (!confirm('Publish to ' + channel + ' now?')) return;
        if (variantElId) {
            const ta = document.getElementById(variantElId);
            if (ta) {
                await channelAction(postId, channel, 'save', ta.value, null);
            }
        }
        const body = {action: 'publish', post_id: postId, channel};
        const orig = btn.textContent;
        btn.disabled = true; btn.textContent = 'Publishing…';
        try {
            const res = await fetch(CHANNEL_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('Publish failed: ' + (data.error || 'unknown'));
                btn.textContent = orig; btn.disabled = false;
            }
        } catch(e) {
            alert('Error: ' + e.message); btn.textContent = orig; btn.disabled = false;
        }
    }

    async function scheduleChannel(postId, channel, variantElId, btn) {
        const now = new Date(); now.setMinutes(now.getMinutes() + 60);
        const defaultDt = now.toISOString().slice(0,16).replace('T',' ');
        const when = prompt('Schedule publish for (YYYY-MM-DD HH:MM, server time):', defaultDt);
        if (!when) return;
        if (variantElId) {
            const ta = document.getElementById(variantElId);
            if (ta) await channelAction(postId, channel, 'save', ta.value, null);
        }
        const res = await fetch(CHANNEL_API, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action: 'schedule', post_id: postId, channel, scheduled_for: when})
        });
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Schedule failed: ' + (data.error || 'unknown'));
    }
    </script>
    <?php endif; ?>

<?php else:
    // List posts
    $filter_site = $_GET['site'] ?? '';
    $filter_status = $_GET['status'] ?? '';
    $filter_type = $_GET['type'] ?? '';

    $where = ['s.user_id = ?'];
    $params = [$user_id];

    if ($filter_site) { $where[] = 'p.site_id = ?'; $params[] = (int)$filter_site; }
    if ($filter_status) { $where[] = 'p.status = ?'; $params[] = $filter_status; }
    if ($filter_type) { $where[] = 'p.type = ?'; $params[] = $filter_type; }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT p.*, s.domain FROM posts p JOIN sites s ON p.site_id = s.id WHERE {$where_sql} ORDER BY p.created_at DESC LIMIT 50");
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    // Get sites for filter dropdown
    $stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
    $stmt->execute([$user_id]);
    $sites = $stmt->fetchAll();

    // Get site name if filtered
    $site_name = '';
    if ($filter_site) {
        foreach ($sites as $s) {
            if ($s['id'] == $filter_site) { $site_name = $s['name']; break; }
        }
    }
?>
    <?php if ($filter_site && $site_name): ?>
    <div style="margin-bottom:10px;">
        <a href="<?= url('/dashboard/site.php?id=' . (int)$filter_site) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site_name) ?></a>
    </div>
    <?php else: ?>
    <div style="margin-bottom:10px;">
        <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card" style="padding: 10px 16px;">
        <form method="GET" class="flex gap-4 items-center" style="flex-wrap: wrap;">
            <?php if ($filter_site): ?>
                <input type="hidden" name="site" value="<?= (int)$filter_site ?>">
            <?php else: ?>
            <select name="site" class="form-control" style="width: auto; min-width: 150px;">
                <option value="">All Sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="status" class="form-control" style="width: auto;">
                <option value="">All Status</option>
                <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <select name="type" class="form-control" style="width: auto;">
                <option value="">All Types</option>
                <option value="blog" <?= $filter_type === 'blog' ? 'selected' : '' ?>>Blog</option>
                <option value="news" <?= $filter_type === 'news' ? 'selected' : '' ?>>News</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($filter_site || $filter_status || $filter_type): ?>
                <a href="<?= url('/dashboard/posts.php') ?>" class="text-sm text-muted">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <?php if (empty($posts)): ?>
            <p class="text-muted text-sm" style="padding: 20px; text-align: center;">No posts found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Site</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Words</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $p): ?>
                    <tr>
                        <td>
                            <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $p['id']) ?>" style="color: var(--primary); text-decoration: none;">
                                <?= e(truncate($p['title'], 50)) ?>
                            </a>
                        </td>
                        <td class="text-sm"><?= e($p['domain']) ?></td>
                        <td><span class="badge badge-<?= $p['type'] === 'news' ? 'info' : 'draft' ?>"><?= $p['type'] ?></span></td>
                        <td><span class="badge badge-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
                        <td class="text-sm"><?= str_word_count(strip_tags($p['body'])) ?></td>
                        <td class="text-sm text-muted"><?= format_date($p['created_at'], 'd M') ?></td>
                        <td>
                            <?php if ($p['status'] === 'draft'): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
