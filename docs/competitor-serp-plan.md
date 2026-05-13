# Competitor Discovery, Gap Analysis & SERP Intelligence — Plan

**Goal:** Close the gap with Ahrefs/SEMrush for the two capabilities ContentAgent scored 0/10 on — competitor gap analysis and SERP intelligence — using free APIs (Google CSE + Claude) plus our existing GSC integration.

**Target quality:** 70-80% of paid-tool value at $0 incremental cost.

**Out of scope:** historical volume trends, backlink-based difficulty scoring, real volume estimates (those need DataForSEO or similar paid APIs — separate decision).

---

## Three features, one workflow

```
1. Discover Competitors  →  who you compete with on Google
2. Find Gaps             →  what topics they cover that you don't
3. SERP Brief            →  for any keyword, what content is winning
                            (feeds straight into the AI writer)
```

The three plug into each other: discovered competitors feed gap analysis; gap keywords get SERP briefs; SERP briefs guide the AI writer. Each feature is also independently useful.

---

## Feature 1: Competitor Discovery

### What it does
Looks at your top 30 active keywords, queries Google CSE for the top 10 results of each, aggregates which domains keep appearing, filters out non-competitors (Wikipedia, Reddit, YouTube, your own domain), and presents the most-frequent domains as "likely competitors."

### Data model
```sql
CREATE TABLE competitors (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id         INT UNSIGNED NOT NULL,
  domain          VARCHAR(255) NOT NULL,
  name            VARCHAR(255) DEFAULT NULL,         -- AI-extracted or user-set
  detected_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  source          ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  status          ENUM('active','ignored') NOT NULL DEFAULT 'active',
  overlap_score   TINYINT UNSIGNED DEFAULT 0,        -- 0-100: % of your kw they rank on
  shared_keywords INT UNSIGNED DEFAULT 0,            -- raw count
  last_analysed_at DATETIME DEFAULT NULL,
  notes           TEXT DEFAULT NULL,
  UNIQUE KEY uk_competitor (site_id, domain),
  KEY idx_competitor_status (site_id, status)
);

CREATE TABLE competitor_keyword_rankings (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  competitor_id INT UNSIGNED NOT NULL,
  keyword_id    INT UNSIGNED NOT NULL,
  position      TINYINT UNSIGNED DEFAULT NULL,       -- their rank for this keyword
  url           VARCHAR(2048) DEFAULT NULL,
  last_seen_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_comp_kw (competitor_id, keyword_id)
);
```

### Algorithm
```
1. Pick top 30 active keywords (priority DESC, status='active')
2. For each keyword:
   - Call Google CSE → top 10 results
   - For each result, parse domain (strip www.)
   - Skip if domain in exclusion list OR == user's domain
3. Aggregate: domain → [keyword_ids it ranks for, with positions]
4. For each domain found in ≥2 keywords:
   - Insert/update competitors row
   - overlap_score = (shared_keywords / total_keywords_analysed) × 100
5. For each (competitor, keyword) → upsert competitor_keyword_rankings
6. Mark "auto" source. Status defaults active.
```

### Exclusion list (always skip)
```
wikipedia.org, reddit.com, quora.com, youtube.com, medium.com,
linkedin.com, facebook.com, twitter.com, x.com, instagram.com,
pinterest.com, amazon.com, ebay.com, alibaba.com,
google.com, bing.com, duckduckgo.com,
github.com (unless user is a dev tool company — make configurable)
```

### Cost
- 30 CSE calls per analysis. 100/day free quota → 3 analyses/day fits.
- Recommended cadence: once a week per site.
- For 10 client sites running weekly = 300 CSE/week = well within free.

### Failure modes
- **Site has <10 keywords**: not enough signal. Show banner: "Add more keywords (or sync GSC) for better competitor detection."
- **All top 30 keywords are brand searches**: competitors detected will be irrelevant (e.g. searching "Xceed" returns Xceed's own pages + Wikipedia disambiguation). Filter: skip keywords where user's own domain is #1 result.
- **Generic terms**: someone searching "software development" gets giant aggregators. The 2+ keyword threshold + exclusion list helps but doesn't fully fix this. Manual review (ignore button) closes the gap.

---

## Feature 2: Gap Analysis

### What it does
For each detected competitor:
- Scrape their sitemap.xml (or list of recent blog URLs)
- Claude extracts the keyword/topic each of their pages targets
- Compare against your keyword list
- Output: "Topics they cover that you don't"
- Rank gaps by: how many competitors cover them (consensus signal) + GSC impressions (real demand signal)

### Data model
```sql
CREATE TABLE competitor_pages (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  competitor_id INT UNSIGNED NOT NULL,
  url           VARCHAR(2048) NOT NULL,
  title         VARCHAR(500) DEFAULT NULL,
  topic         VARCHAR(255) DEFAULT NULL,           -- Claude-extracted primary topic
  keywords      JSON DEFAULT NULL,                   -- Claude-extracted secondary keywords
  word_count    INT UNSIGNED DEFAULT NULL,
  scraped_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_comp_url (competitor_id, url(191))
);

CREATE TABLE content_gaps (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id           INT UNSIGNED NOT NULL,
  topic             VARCHAR(255) NOT NULL,
  competitor_count  TINYINT UNSIGNED DEFAULT 0,      -- how many of our competitors cover this
  competitor_ids    JSON DEFAULT NULL,
  estimated_demand  INT UNSIGNED DEFAULT NULL,       -- sum of GSC impressions for related kws, if any
  status            ENUM('open','planned','published','ignored') NOT NULL DEFAULT 'open',
  detected_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_gap (site_id, topic(191))
);
```

### Algorithm
```
For each active competitor of this site:
  1. Fetch sitemap.xml (try common paths: /sitemap.xml, /sitemap_index.xml,
     /robots.txt parse Sitemap: line)
  2. Parse → list of URLs. Limit to last 50 most-recent.
  3. For each URL: fetch HTML, extract title + first 500 chars of body
  4. Batch 10 URLs → Claude prompt:
     "Here are 10 pages from {competitor}. For each, extract:
      - primary topic (3-5 word phrase)
      - secondary keywords (3-5 phrases)
      Output JSON array."
  5. Save competitor_pages rows

Then build gaps:
  6. Collect all distinct topics across competitor_pages for this site
  7. Compare against site's active keywords + already-published post titles
  8. Topics NOT covered = candidate gaps
  9. For each gap: count how many competitors cover it
 10. If GSC data exists, match topic to closest keyword to get impressions estimate
 11. Upsert content_gaps row, ranked by competitor_count DESC, demand DESC
```

### Cost
- Page fetches: free (we're hitting public URLs)
- Claude calls: ~10 pages per prompt = N/10 prompts per competitor.
  - 5 competitors × 50 pages = 25 prompts × ~$0.002 (Haiku) = **$0.05 per full analysis**
- Cadence: monthly per site is enough (competitors don't write daily)

### Failure modes
- **No sitemap.xml**: fall back to scraping homepage + crawling 1 level deep up to 50 URLs. Works for most sites.
- **JS-rendered content**: we get blank pages. Mitigation: just use the URL slug + title (already useful).
- **Competitor blocks scraping**: skip with a "could not analyse" note.

---

## Feature 3: SERP Brief (per keyword)

### What it does
For a chosen keyword:
- Google CSE → top 10 results
- Fetch each result's page → extract title, H2s, word count
- Claude analyses the 10 pages and outputs a "content brief":
  - Dominant content format (how-to, listicle, comparison, product page, etc.)
  - Average word count of top 3
  - Common headings/H2s across top results
  - Search intent (informational / commercial / transactional / navigational)
  - SERP features (featured snippet present? PAA? video pack? — from CSE response metadata)
  - Recommended outline for a winning post

This brief is **stored on the keyword row** and **passed into the AI writer** when generating a post for that keyword.

### Data model
Extend `keywords` table:
```sql
ALTER TABLE keywords
  ADD COLUMN serp_brief    JSON DEFAULT NULL    AFTER cluster,
  ADD COLUMN serp_briefed_at DATETIME DEFAULT NULL AFTER serp_brief;
```

`serp_brief` JSON structure:
```json
{
  "format": "how-to guide",
  "intent": "informational",
  "avg_word_count": 1850,
  "common_h2s": ["What is X", "Why X matters", "How to choose X", "Top X tools"],
  "winning_pattern": "Long-form how-to with comparison table",
  "serp_features": ["paa", "featured_snippet"],
  "top_results": [
    {"url": "...", "title": "...", "word_count": 1900},
    ...
  ],
  "recommended_outline": [...],
  "competitive_difficulty": "medium"
}
```

### Cost per keyword
- 1 CSE call + ~10 page fetches + 1 Claude prompt
- ~3000 input tokens (10 page summaries) + 1000 output = $0.0015 (Haiku)
- Cache for 30 days — re-brief only on demand or monthly

### Integration with AI Writer
In `haiku_write_blog()`, if the target keyword has a `serp_brief`, append to system prompt:
```
TARGET SERP CONTEXT for "{keyword}":
- Winning format: how-to guide
- Average length of top 3: 1850 words
- Common sections to cover: What is X, Why X matters, How to choose X
- Search intent: informational
- Match this pattern.
```

This is **the killer feature**. Without it the writer is guessing; with it, it writes content modelled on what's actually ranking.

---

## UI placement & flow

### Site page (site.php) — new section in the accordion

Insert between **Keywords** and **Content** sections:

```
▼ Competitors (12 detected, 3 manually added)
  ┌────────────────────────────────────────────────────────┐
  │  [🔍 Discover Competitors]    [+ Add Manually]          │
  │  Last analysed: 2 days ago                              │
  ├────────────────────────────────────────────────────────┤
  │  hotjar.com         18/30 kw overlap  → View · Ignore  │
  │  segment.com        14/30             → View · Ignore  │
  │  mixpanel.com       11/30             → View · Ignore  │
  │  amplitude.com       9/30             → View · Ignore  │
  │  ... see all 12 →                                      │
  └────────────────────────────────────────────────────────┘

▼ Content Gaps (8 topics they cover that you don't)
  ┌────────────────────────────────────────────────────────┐
  │  "self-hosted analytics"   covered by 4 competitors    │
  │     ~ 240 imp/mo · [Write a post about this →]         │
  │  "GDPR-compliant tracking" covered by 3                │
  │     ~ 180 imp/mo · [Write a post →]                    │
  │  ... 6 more                                            │
  └────────────────────────────────────────────────────────┘
```

### Keywords page (keywords.php) — new column + link

Each keyword row gets a small "📊 SERP" button. Click → modal or expanded row showing the SERP brief. If no brief yet, button says "📊 Brief" and triggers generation.

Quick Wins tab gets a "✍ Write with SERP brief" button that pre-fills the AI writer with the keyword + brief.

### Dedicated Competitors page (competitors.php) — for deep view

Linked from the site-page section header. Lists all competitors with detail per row:
- Domain
- Shared keywords count + click to drill into which keywords
- "View their content" → competitor_pages list with topic tags
- Manual notes field
- Ignore / Restore buttons

### AI Writer (write.php) — SERP brief injected automatically

When user picks a topic that matches a keyword with a SERP brief, show a banner:
> 💡 SERP analysis available. The AI will model your post on the top-ranking content for this keyword (avg 1850 words, how-to format).

User can toggle off if they want pure-AI generation.

---

## Phased rollout (deploy in pieces, each independently useful)

### Phase 1 — Competitor Discovery (2-3 hours)
- Migration: `competitors`, `competitor_keyword_rankings`
- API: `/api/competitors-discover.php` (runs the algorithm)
- API: `/api/competitors-manage.php` (add/ignore/delete)
- UI: Competitors section in site.php with Discover button
- UI: small competitors list with overlap score

**Ship point:** users can click Discover, see top 10 competitors, ignore irrelevant ones. Value delivered: knowing who you compete with.

### Phase 2 — SERP Brief (3-4 hours)
- Migration: add `serp_brief`, `serp_briefed_at` to keywords
- API: `/api/serp-brief.php` (generate brief for one keyword)
- UI: "📊 SERP" button on each keyword row
- UI: modal/drawer showing the brief
- AI Writer: inject brief into Claude prompt when present

**Ship point:** users can generate a SERP brief for any keyword and the AI writer uses it. Value delivered: AI-written content actually competes with what's ranking.

### Phase 3 — Gap Analysis (3-4 hours)
- Migration: `competitor_pages`, `content_gaps`
- API: `/api/competitor-pages-scrape.php` (scrape sitemap + extract topics)
- API: `/api/gaps-detect.php` (compare & save)
- UI: Content Gaps section under Competitors
- UI: "Write a post about this →" button → opens AI writer pre-filled

**Ship point:** the full loop closes. Value delivered: ContentAgent tells users WHAT to write next based on real competitor data.

### Phase 4 — Polish (1-2 hours)
- Dedicated competitors.php page (deep view)
- Auto-discover scheduled via cron weekly
- SERP brief auto-refresh monthly
- Configurable exclusion list per site (Settings)

---

## Open decisions (need user input before/during build)

1. **Cron or manual?** Should discovery and gap analysis run automatically on a schedule (weekly), or only when user clicks a button? Recommend: button for now, add cron in Phase 4.

2. **How many competitors to track per site?** Recommend cap at 10 (top 5 auto + 5 manual). More dilutes signal.

3. **SERP brief cache duration?** Recommend 30 days. Refresh on demand. Tradeoff: longer = cheaper, shorter = more current.

4. **What if the user has no GSC connected?** Use AI-estimated keywords. Discovery still works (we use top 30 active keywords regardless of source). Gap analysis still works. Quality is lower because priority ordering is heuristic, not real demand.

5. **AEO/AI search competitors?** Should we also detect who's cited in ChatGPT / Perplexity answers for the user's queries? This is a separate feature (would use the AI Visibility module). Recommend: not in this plan — keep it focused on Google SERPs first.

---

## Risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| Google CSE results drift from real Google | Med | Document the caveat; trust GSC for ground-truth ranks |
| CSE quota exhausted on heavy use | Low | Cache results; configurable refresh cadence; warn user at 80% quota |
| Competitor blocks scraping | Med | Skip gracefully, mark page as "unparseable" |
| Claude misidentifies topic of scraped page | Low-Med | Show user the AI-extracted topic; let them correct via UI (Phase 4) |
| Auto-discovery surfaces irrelevant domains | High initially | Strong exclusion list + manual ignore button + 2+ keyword threshold |
| Cost spike if many users | Med (future) | Monitor quotas; add daily cap per user; rate-limit |

---

## Honest expectations

**What this will deliver:**
- An SMB customer feels like they're using a real SEO tool (because they kinda are)
- Content briefs make AI-written posts compete instead of looking generic
- Gap analysis surfaces real content opportunities
- All for $0 incremental cost

**What this won't deliver:**
- Parity with Ahrefs's full database (we don't index 7B keywords)
- Real backlink-based difficulty (would need a separate crawler — out of scope)
- Discovery of competitors who rank for keywords you don't yet track
- Historical trend lines

**Net positioning after this is live:** ContentAgent goes from "decent content automation tool with weak SEO data" to "credible SEO+content tool for SMBs that beats Ubersuggest on workflow and matches it on data, at $0."

---

## Approval checklist before coding

- [ ] User agrees with phased rollout (Phase 1 first, then 2, then 3)
- [ ] User confirms exclusion list defaults
- [ ] User picks: manual-trigger only (Phase 1-3) vs add cron in Phase 4
- [ ] User confirms 10 competitors cap is fine
- [ ] User confirms 30-day SERP brief cache
- [ ] User confirms we're not adding AEO/AI search competitors in this scope
