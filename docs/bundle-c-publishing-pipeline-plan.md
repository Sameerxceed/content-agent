# Bundle C — Publishing Pipeline Plan

**What it is:** turning ContentAgent from "writes content" into "writes AND distributes everywhere." One post → multiple channels (blog, LinkedIn, Twitter, newsletter, Instagram) → scheduled when relevant → tracked for performance.

**Why bundled:** all 7 features write the same kind of data — a post, transformed for a channel, sent at a time, with a record. Build the foundation once, layer features cheaply.

**Standalone vs bundled effort:** ~8 days separately, ~4 days together.

---

## The shared foundation (build once)

Three pieces every feature in Bundle C uses:

### 1. `post_channels` table — which channels does this post target

```sql
CREATE TABLE post_channels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  channel VARCHAR(30) NOT NULL,        -- 'cms', 'reddit', 'linkedin', 'twitter', 'newsletter', 'instagram'
  variant_content LONGTEXT DEFAULT NULL,  -- channel-specific format (e.g. tweet thread, LI caption)
  external_id VARCHAR(255) DEFAULT NULL,  -- ID returned by the channel after publish
  external_url VARCHAR(2048) DEFAULT NULL,
  status ENUM('draft','queued','published','failed') NOT NULL DEFAULT 'draft',
  scheduled_for DATETIME DEFAULT NULL,
  published_at DATETIME DEFAULT NULL,
  error TEXT DEFAULT NULL,
  metrics JSON DEFAULT NULL,           -- clicks, impressions, likes — populated by trackers
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_post_channel (post_id, channel),
  KEY idx_scheduled (status, scheduled_for)
);
```

### 2. Channel adapter pattern — one class per destination

```
includes/channels/
  base.php                ← abstract ChannelAdapter
  cms-opencart.php        ← implements publish() for OpenCart
  cms-wordpress.php
  cms-shopify.php
  reddit.php
  linkedin.php
  twitter.php
  newsletter.php
  instagram.php
```

Each adapter declares:
- `name`, `display_name`, `icon`, `color`
- `is_configured(site)` — checks credentials
- `transform_post(post, site)` → returns channel-specific content (e.g. for Twitter, builds a thread)
- `publish(post_channel_row)` → makes the API call, returns `{external_id, url, error}`
- `fetch_metrics(post_channel_row)` → optional, pulls likes/clicks back

### 3. Scheduler — `agent/cron-publish.php`

Runs every 15 min. Picks up `post_channels` rows where `status='queued' AND scheduled_for <= NOW()`, calls the adapter's `publish()`, updates status. Errors retry with backoff (3 attempts then `failed`).

That's the foundation. Every feature below plugs into it.

---

## The features (in build order)

### Phase 1 — CMS publishing (foundation + first adapter)

**Why first:** validates the channel-adapter pattern with the simplest case. Araha London needs this — without it, AI-written posts can't go to her live OpenCart site.

- Build channel adapter base class
- Build CMS-OpenCart adapter (Araha uses it)
- Wire AI Writer publish step to use post_channels row instead of the old direct push
- UI: small "Channels" widget on the post edit page (defaults to "CMS only" for now)

**Ship point:** writing a post in ContentAgent → choose "Publish to CMS" → post lands on arahalondon.com via OpenCart's blog API. Same as today's UX, but on the new infrastructure ready for everything else to plug in.

**Effort:** ~6 hours (4 for OpenCart adapter, 2 for the foundation).

---

### Phase 2 — Reddit + LinkedIn posting

**Why next:** OAuth flows are already wired (we have client IDs in settings). Just need the publish() implementations + UI to enable per-post.

- Reddit adapter — posts to a chosen subreddit or user profile. Needs Reddit OAuth (which we couldn't test from Linode without keys earlier — but with OAuth user-side it works).
- LinkedIn adapter — posts to personal or company profile.
- UI: post edit page gets a multi-select "Publish to: [ ] CMS [ ] Reddit [ ] LinkedIn"
- Each channel optionally has a per-post override (e.g. "this Reddit post goes to r/marketing not r/SEO")

**Ship point:** one click on a written post → goes to blog + Reddit + LinkedIn at the same time, with channel-specific text adjustments.

**Effort:** ~6 hours.

---

### Phase 3 — Content Repurposing engine

**Why now:** the channels are ready, now we make them feel different (not just the same blog title pasted everywhere).

- Claude call per channel during publish:
  - **Twitter**: turn the post into a 5-7 tweet thread
  - **LinkedIn**: 200-word professional post with hook + CTA
  - **Newsletter**: tighter intro + "read more" link to blog
  - **Reddit**: discussion-style framing, no salesy language (Reddit kills that)
- Generated variants stored in `post_channels.variant_content`
- Editable in the UI before publishing
- "Regenerate" button per channel if the user doesn't like the variant

**Ship point:** one blog post → 4 channel-tailored pieces of content, each editable, all published in one click.

**Effort:** ~5 hours.

---

### Phase 4 — Content Calendar

**Why here:** now that posts can have scheduled_for dates, the calendar visualizes them.

- New page `/dashboard/calendar.php?site=X` — month grid view
- Each cell shows: which posts publish that day, color-coded by channel
- Drag a post to a different day to reschedule
- "Suggest dates" button — AI picks optimal days based on past performance + day-of-week patterns
- Sidebar shows: unscheduled drafts + content gaps (so user can drag them onto the calendar)

**Ship point:** customer sees their full content pipeline visually. Marketing-team feeling.

**Effort:** ~5 hours.

---

### Phase 5 — A/B title testing

**Why penultimate:** uses the channels but needs scheduler + metrics fetching to be solid.

- When user publishes a post: optionally generate 3 title variants
- All three publish, each to a different cohort (e.g. variant A on Mon, B on Tue, C on Wed — same channel)
- After 7 days, cron fetches metrics, picks the winner, alerts the user
- Saves the winning pattern as a "title style preference" for future posts

**Effort:** ~3 hours.

---

### Phase 6 — Internal linking automation

**Why last:** purely value-add, doesn't depend on the channel system.

- On post publish (CMS specifically), Claude reviews the post body, suggests internal links to 2-3 existing published posts based on topic overlap
- Shown inline in the AI Writer preview step
- One-click "Insert link" per suggestion
- Builds topical authority for SEO

**Effort:** ~2 hours.

---

### Phase 7 — Newsletter engine (LAST + optional)

**Why deferred:** this is the only feature that requires a separate email service (newsletter-grade like Flodesk/Beehiiv — Resend is for transactional, not marketing). Skip unless you want it.

- Subscribe form on customer's blog (embeddable widget)
- Email list stored per site
- Weekly digest of new posts auto-sent to subscribers
- Open/click tracking

**Effort:** ~6 hours + sign up for a marketing-email service.

---

## What's IN scope for Bundle C build

Phases 1-4 (CMS, Reddit/LinkedIn, Repurposing, Calendar). The killer combination — most marketing teams' biggest pain.

Phase 5 (A/B) and Phase 6 (Internal linking) — nice-to-have, defer to evening polish.

Phase 7 (Newsletter) — requires a different email service. Decide later.

---

## What's OUT of scope

- Instagram auto-posting via Meta API — requires Business account verification, weeks of review. We already have the Carousel generator that produces images for manual posting; keep that.
- Facebook auto-posting — same issue as Instagram.
- YouTube — would require a separate video pipeline. Out.
- TikTok — same.
- Discord/Slack — niche, post-MVP.

---

## Phasing recommendation

**Session 1 (now):** Phase 1 — CMS publishing on new foundation. Ship 6 hours.
**Session 2:** Phases 2 + 3 — Reddit/LinkedIn adapters + Content Repurposing engine. Ship one day.
**Session 3:** Phase 4 — Content Calendar. Ship half day.
**Session 4 (optional):** Phases 5 + 6 — A/B testing + internal linking.

Total Bundle C end-to-end: **~3 focused days** over 3-4 sessions.

---

## Open decisions

1. **Phase 1 priority — OpenCart first because Araha needs it. Or WordPress first because it's the most common CMS for SMBs?**
   _Recommend OpenCart since we have a real test site (Araha) and most SMBs we onboard later will be WordPress — easier to validate the pattern on the harder one first._

2. **Channel default per-site.** When a post is written, which channels should be checked by default? All? Just CMS? Configurable per-site?
   _Recommend: per-site default channels (set in Site Settings). New sites default to "CMS only".

3. **Variant editing UX.** Modal? Drawer? Inline? When user clicks "Reply with AI" to generate a Twitter variant, do they see it as part of the post edit page, or separately?
   _Recommend: tabs on the post edit page — "Blog | LinkedIn | Reddit | Twitter | Newsletter". Each tab shows its variant. Active tab = which channel you're previewing/editing._

4. **Reddit + LinkedIn — auth on user's behalf or ours?**
   _Each customer connects their own Reddit/LinkedIn account via OAuth. We never post on our behalf. This means each site has its own connected accounts (per-site OAuth state)._

5. **Schedule UX.** When a user writes a post, do we ask for the schedule upfront, or save as draft and schedule from the calendar?
   _Recommend: save as draft is the default. Calendar is the place to schedule. Single "Publish now" or "Add to calendar" choice at end of writer._

6. **Content Repurposing — manual trigger or automatic?**
   _Recommend: automatic on publish. Generate all variants in background. User can ignore or edit before they go out. Saves a click for the common case._

7. **Internal linking — at publish-time or post-publish?**
   _Recommend: at publish-time, in the AI Writer preview. Once published, harder to insert links cleanly._

---

## Recommended next step

If you agree with the recommendations above, I'll start Phase 1 (CMS publishing on new foundation, OpenCart adapter, post edit page channel widget). About 6 hours of focused work. Ships a working end-to-end test: write a post for Araha → publishes to arahalondon.com via OpenCart's blog API.

If you want changes — say which decisions to flip and we adjust.
