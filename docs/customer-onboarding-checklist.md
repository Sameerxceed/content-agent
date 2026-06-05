# ContentAgent Customer Onboarding Checklist

**Purpose**: Replayable script for onboarding any new customer through ContentAgent. Goal — a customer goes from "signed up" to "first published post + redirect map live" in under 30 minutes without needing a Sameer-in-the-loop call.

**Audience**: Sameer onboarding a customer; later, the customer themselves following the in-product flow.

**Time budget**: 25-30 min for a clean run. Anything over 45 min means a friction point we need to fix in-product.

---

## Step 0 — Account creation (pre-onboarding, 2 min)

- [ ] Customer signs up via the public signup form, OR Sameer creates the account in super-admin → Sites.
- [ ] Customer login email follows convention `{name}@contentagent.com` (placeholder until they own login).
- [ ] First site row created with `domain` (no protocol) + `platform` set (shopify / wordpress / nextjs / custom).

**Watch for**: If signup fails for the customer, they will give up. The signup form must clearly say what data we need + what we'll do with it.

---

## Step 1 — Setup (~5 min)

The Setup stepper step in `/dashboard/site.php?id=N`. Drives everything downstream.

- [ ] **Business profile** — Click "Set business focus" on Setup card. ContentAgent crawls 6-8 pages and infers 12 structured fields (size_tier, business_model, industry_category, etc.). User reviews + edits.
  - Time: ~90 seconds for inference, 2-3 min for review.
  - **Friction risk**: If inference produces wrong fields, user must edit each one manually. Watch for "wrong industry" — biggest class of follow-on errors.

- [ ] **Connect channels** — Setup → Channels:
  - **Blog/CMS**: Paste WordPress / Shopify / Custom API key. Smart fix mapping catches common errors.
  - **LinkedIn personal** (optional): OAuth flow, redirects back to ContentAgent.
  - **LinkedIn company page** (optional): Requires Microsoft Vetting approval on the LinkedIn app (1-4 week wait).
  - **Newsletter** (optional): Mailchimp / ConvertKit / Substack via API key.

- [ ] **Verify each connection** — Each channel shows a green ✓ once verified. Red ✗ shows the parse_error from the smart fix mapping.

**Watch for**: Shopify Custom App token creation is the longest sub-step. Customer needs to:
1. Open their Shopify admin → Settings → Apps and sales channels → Develop apps → Create app
2. Add `write_online_store_navigation` + `read_products` + `write_products` + `read_collections` scopes
3. Install the app → reveal access token (starts with `shpat_`)
4. Paste into ContentAgent Setup → Channels → CMS

If this step takes more than 5 min, **fix the in-product documentation, not the customer**.

---

## Step 2 — First crawl + content baseline (~3 min)

- [ ] Click **"Scan your website"** on Setup card. ContentAgent:
  - Fetches sitemap (with sitemap-index recursion fallback)
  - Crawls top URLs to build `current_site_urls` inventory
  - Classifies each URL (home / product / collection / blog / page / other)
- [ ] **Site Health → Redirects → "Crawl live site"** — populates the redirect-target inventory.
- [ ] Crawl summary card shows total URLs found.

**Watch for**: Empty sitemap = customer needs to create one OR set the platform-specific override. Show a clear error: "We could not find a sitemap at /sitemap.xml. {platform-specific guidance}."

---

## Step 3 — Site Health baselines (~4 min)

All five tiles live on `/dashboard/site-health.php?site=N`. Each is a single click + watch.

- [ ] **Redirects** — Only matters if the customer has dead historical URLs from a migration. Skip for greenfield. If they have a Wayback archive, run:
  - Wayback harvest (if not done) → live-check pass → "Build redirect map" → **preflight modal shows cost estimate** → user picks "Run 100 first" for validation, then full run.
  - On Shopify: "Apply N to Shopify" pushes via Admin API.
  - Other platforms: download platform-specific export (Apache / nginx / Next.js / Netlify / Vercel / WordPress / CSV).

- [ ] **Page Speed** — Click "Run baseline". ~30s. Returns Lighthouse + CrUX data for top URLs.

- [ ] **Schema audit** — Click "Audit now". ~30s. Surfaces broken / degraded JSON-LD per URL.

- [ ] **Freshness** — Click "Scan posts". Scores blog content by age + year-in-text + traffic decline. Queues stale posts as refresh plan-items.

- [ ] **Fast indexing (IndexNow)** — 3-step setup walkthrough:
  1. Generate key
  2. Customer uploads the verification file to their root
  3. ContentAgent verifies + starts pushing every publish

- [ ] **Branded 404 page** — Generate Next.js or Shopify Liquid file, customer deploys.

**Watch for**: The Apply N to Shopify step currently runs synchronously in the browser request for N up to ~1000. For larger N, the request times out. **Backlog**: convert to background job. For now, if redirect map has >1000 approved, advise customer to split into batches.

---

## Step 4 — Find keywords + content plan (~8 min)

- [ ] **Find Keywords** (Setup → Keywords): Click. Runs in background ~3-5 min:
  - Claude generates 80-120 buyer-shaped keywords from the business profile
  - DataForSEO enriches each with volume / difficulty / intent
  - Auto-filters irrelevant ones via relevance pass
- [ ] **Generate Content Plan** — Once keywords settle, click. **Preflight modal shows cost estimate**. ~3 min:
  - Pass A: cluster keywords into 8-12 topic groups
  - Pass B: sequence ~24 plan items across 12 weeks (quick wins → pillars → supporting)
- [ ] Customer reviews clusters + pipeline on `/dashboard/plan.php`.

**Watch for**: If active-keyword count is below 30, Generate Content Plan button is disabled. Show the prerequisite clearly: "Need 30+ active keywords — currently have N."

---

## Step 5 — First published post (~5 min)

- [ ] Customer opens the first plan item (`/dashboard/plan-item.php?id=N`). They see:
  - Pre-generated blog HTML
  - SEO title + description
  - JSON-LD schema (auto-built from the title + FAQ section)
  - Hero image (Gemini or DALL-E)
  - Per-channel scheduled dates (blog Day 0, LinkedIn +1, Newsletter +2)
- [ ] Edit any field inline — title change cascades to slug, schema, H1, plan item.
- [ ] Click **"Approve & schedule"**. Post enters the publish queue.
- [ ] Within 15 min, autopilot pushes to blog → IndexNow ping → LinkedIn next day → Newsletter day after.

**Watch for**: First publish must succeed. If CMS push fails, the error must be actionable (not "HTTP 500"). The customer's confidence in the platform hinges on this single post landing successfully on their domain.

---

## Step 6 — Set + forget (~ongoing)

After the first post lands, the customer should not need to log in daily. Confirm:

- [ ] **Auto-publish** is on (Setup → Publishing → autopilot toggle).
- [ ] **Daily cron jobs** are running (visible to super-admin in Cron Jobs):
  - 02:00 — plan-autopilot (drafts the next post)
  - Every 15 min — publish (push approved posts to channels)
  - 22:30 — performance-fetch (pulls actual GSC clicks)
- [ ] **Weekly hygiene crons** are scheduled:
  - Sun 04:00 — website-hygiene
  - Mon 05:30 — psi-baseline
  - Tue 04:30 — schema-audit
  - Wed 04:30 — content-freshness
- [ ] **Monthly review** (~30 days after first publish): user sees "Monthly review ready" banner on Plan page; clicks to approve proposed swaps / additions / removals.

**Watch for**: Customers churn most often in week 2-3 when they expect to see traffic and don't. Be explicit upfront: "Most posts take 3-6 weeks to start ranking. The monthly review optimises based on what's actually working."

---

## Friction log template (for each onboarding pass)

Track every confusion, every error message that wasn't actionable, every "Sameer had to do this" moment.

| When | What happened | Friction class | Fix |
|---|---|---|---|
| Step N | Customer didn't understand X / hit error Y | doc / UI / bug | What we changed |

After every customer onboarding, file the friction log here + land the fixes in-product before the next customer.

---

## Success criteria (a customer is "onboarded")

- [ ] Setup card shows 100% complete
- [ ] At least 1 post published end-to-end through autopilot
- [ ] At least 1 redirect approved + applied (if applicable to their site)
- [ ] At least 1 Site Health tile shows recent data (not "never run")
- [ ] Customer hasn't asked Sameer for anything in 7 days
- [ ] **Cost per customer** (from /dashboard/ai-costs.php) is below their plan tier limit

If all 6 are true, they are onboarded.

---

## When to escalate to Sameer

These are the only flows that legitimately need a human:
- LinkedIn Community Management API vetting (Microsoft, 1-4wk async)
- Shopify Plus / enterprise CMS that needs custom field mapping
- DNS / SSL / hosting issue outside ContentAgent scope
- Custom branding for the 404 page (default uses brand colors from profile)

Everything else should be solvable from the in-product docs. If a customer is asking, that is a doc + UI bug worth fixing.
