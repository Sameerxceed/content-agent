# ContentAgent — Feature List

## MVP (Week 1-4)

### 1. Website Scanner
- [ ] Enter any URL → agent fetches and analyzes the site
- [ ] Detect platform (WordPress, Shopify, OpenCart, custom)
- [ ] Extract brand colors, fonts, logo
- [ ] Extract existing content (pages, products, about)
- [ ] Identify social media links
- [ ] Detect if blog exists
- [ ] Generate brand tone/voice description

### 2. Technical SEO Audit
- [ ] Crawl all internal links → detect broken links (404, 410)
- [ ] Detect 401/403 unauthorized pages (login walls, restricted content)
- [ ] Check sitemap.xml exists and is valid (parseable, no orphan URLs)
- [ ] Check robots.txt exists and isn't blocking important pages
- [ ] Validate structured data / schema markup (JSON-LD, Microdata)
- [ ] Detect missing or duplicate meta titles / descriptions
- [ ] Check canonical tags (missing, self-referencing, conflicting)
- [ ] Detect redirect chains and loops (301/302)
- [ ] Check SSL certificate validity
- [ ] Page speed basics: detect render-blocking resources, large images
- [ ] Mobile-friendliness check (viewport meta, responsive hints)
- [ ] Detect missing alt text on images
- [ ] Check Open Graph / Twitter Card tags
- [ ] Generate technical audit report per site (score + issues list)
- [ ] Store issues in DB, track fixes over time
- [ ] Re-run audit weekly via cron, flag regressions
- [ ] **Auto-remediation:** for each issue, propose specific fix with code/config snippet
- [ ] Auto-fix broken internal links (suggest correct URL from sitemap)
- [ ] Auto-generate missing meta titles/descriptions using Haiku
- [ ] Auto-generate missing alt text for images using Haiku
- [ ] Auto-generate missing schema markup (JSON-LD) per page type
- [ ] Generate sitemap.xml if missing (crawl-based)
- [ ] Generate robots.txt if missing (sensible defaults)
- [ ] Fix report: exportable checklist with issue → fix → status
- [ ] Dashboard: technical health score card per site with trend

### 3. Keyword Research
- [ ] Google Autocomplete scraper (free)
- [ ] Google "People Also Ask" scraper (free)
- [ ] Keyword clustering (group related terms into article topics)
- [ ] Competitor content analysis (what similar sites rank for)
- [ ] Keyword difficulty estimation (basic — by search result count)
- [ ] Store keyword list per site with priority scores

### 4. Blog Writer (Haiku)
- [ ] Generate 800-1200 word SEO articles from keyword clusters
- [ ] Auto-generate: title, meta description, H2 structure, excerpt
- [ ] Internal linking suggestions (to other posts on same site)
- [ ] Match brand tone per site (system prompt per customer)
- [ ] Create as draft → queue for approval
- [ ] 4 posts/week/site schedule

### 5. News Scraper
- [ ] Pull from 10+ RSS feeds (configurable per site/niche)
- [ ] Parse title, description, date, source URL
- [ ] Filter by relevance (keyword matching)
- [ ] Deduplicate (same story from multiple sources)
- [ ] Store in database, publish to blog as "news" type
- [ ] Daily cron job

### 6. Hosted Blog Engine
- [ ] Multi-tenant: one engine serves all customer blogs
- [ ] URL structure: customer.com/blog/post-slug
- [ ] Dynamic theme: uses customer's brand colors/fonts
- [ ] SEO: meta tags, Open Graph, JSON-LD, sitemap.xml
- [ ] Responsive design
- [ ] Blog listing page with pagination
- [ ] Single post page with prose styling
- [ ] RSS feed per site
- [ ] Customer connects via reverse proxy (Apache/Nginx config provided)

### 7. Dashboard
- [ ] Login / auth (session-based)
- [ ] Add new site (enter URL → scanner runs)
- [ ] View all sites
- [ ] Per-site: content queue (drafts, approved, published)
- [ ] Approve / edit / reject posts
- [ ] Agent settings per site (topics, keywords, tone, schedule)
- [ ] Agent activity log (what it did and why)

### 8. Cron / Scheduler
- [ ] Daily: news scraper runs for all active sites
- [ ] Mon + Thu: blog writer generates posts
- [ ] Weekly: keyword re-evaluation
- [ ] Weekly: technical SEO audit re-scan, flag regressions
- [ ] All jobs logged with status (success/fail/skipped)

---

## V2 (Month 2-3)

### 9. Social Media Distribution
- [ ] Repurpose blog post → LinkedIn post (200-word summary)
- [ ] Repurpose blog post → Twitter/X thread (5 tweets)
- [ ] Repurpose blog post → Facebook post
- [ ] LinkedIn API integration
- [ ] Twitter/X API integration
- [ ] Facebook/Meta Graph API integration
- [ ] Scheduling: post at optimal times
- [ ] Per-site social account connections

### 10. Newsletter / Email
- [ ] Weekly digest email (top posts from the week)
- [ ] Subscriber management (signup form embed)
- [ ] Resend API integration (or SendGrid)
- [ ] Unsubscribe handling

### 11. Analytics
- [ ] Google Search Console API integration
- [ ] Track keyword rankings over time
- [ ] Track page views per post (via Search Console)
- [ ] Dashboard charts: traffic trend, top posts, keyword positions
- [ ] Agent uses analytics to adjust strategy (write more of what ranks)

### 12. Agent Intelligence (upgrade from automation to real agent)
- [ ] Observe: check rankings, traffic, engagement weekly
- [ ] Decide: which keywords need more content, what topics are trending
- [ ] Act: generate targeted content to fill gaps
- [ ] Learn: track what worked, adjust tone/topics/frequency
- [ ] Log all decisions with reasoning

---

## V3 (Month 4-6)

### 13. External CMS Connectors
- [ ] WordPress REST API connector
- [ ] Shopify Blog API connector
- [ ] Generic REST API connector (configurable endpoint + auth)
- [ ] Auto-detect and suggest connector during scan

### 14. Multi-user / Agency
- [ ] Team accounts (invite collaborators)
- [ ] Per-user permissions (admin, editor, viewer)
- [ ] Client-facing read-only dashboard
- [ ] White-label option (remove ContentAgent branding)

### 15. Billing
- [ ] Stripe integration
- [ ] Plan management (Starter / Growth / Agency)
- [ ] Usage tracking (posts generated, sites connected)
- [ ] Trial period (14 days free)

### 16. Instagram Carousel Generator
- [ ] Turn blog post into 5-slide carousel images
- [ ] Auto-generate using HTML canvas or PHP GD
- [ ] Post via Instagram API

---

## Database Schema (MVP)

```sql
-- Users / Auth
users (id, email, password_hash, name, plan, created_at)

-- Customer websites  
sites (id, user_id, name, domain, platform, brand_colors, brand_fonts, 
       brand_tone, topics, keywords, agent_mode, blog_path, 
       rss_feeds, is_active, created_at)

-- Blog posts + news
posts (id, site_id, title, slug, body, excerpt, 
       seo_title, seo_description, seo_keywords,
       type, tags, status, created_at, published_at)
       -- type: "blog" | "news"
       -- status: "draft" | "approved" | "published" | "rejected"

-- Social media posts
social_posts (id, post_id, site_id, platform, content, 
              status, scheduled_at, posted_at)

-- Agent activity log
agent_log (id, site_id, action, details, created_at)

-- Keyword tracking
keywords (id, site_id, keyword, search_volume, difficulty,
          current_rank, target_rank, last_checked, created_at)

-- Technical SEO audit
seo_audits (id, site_id, score, total_issues, critical, warnings,
            passed, run_at)

-- Individual audit issues
seo_issues (id, audit_id, site_id, type, severity, url, 
            description, suggested_fix, status, fixed_at, created_at)
            -- type: "broken_link" | "missing_meta" | "missing_schema" | "missing_sitemap" |
            --       "missing_robots" | "redirect_chain" | "missing_alt" | "ssl_error" |
            --       "missing_canonical" | "missing_og" | "auth_error" | "speed_issue"
            -- severity: "critical" | "warning" | "info"
            -- status: "open" | "fix_proposed" | "fix_applied" | "resolved" | "ignored"
```

## File Structure

```
contentagent/
├── public/                     ← Web root (Apache/Nginx points here)
│   ├── index.php               ← Dashboard entry
│   ├── dashboard/
│   │   ├── sites.php
│   │   ├── posts.php
│   │   ├── settings.php
│   │   ├── analytics.php
│   │   └── assets/             ← CSS, JS for dashboard
│   ├── blog/                   ← Hosted blog engine
│   │   ├── index.php           ← Blog listing
│   │   └── post.php            ← Single post
│   ├── api/
│   │   ├── scan.php
│   │   ├── posts.php
│   │   └── sites.php
│   └── auth/
│       ├── login.php
│       └── logout.php
│
├── agent/                      ← CLI scripts (run by cron)
│   ├── scanner.php
│   ├── keyword-research.php
│   ├── blog-writer.php
│   ├── news-scraper.php
│   ├── social-poster.php
│   ├── seo-auditor.php
│   └── evaluator.php
│
├── includes/
│   ├── db.php
│   ├── auth.php
│   ├── haiku.php               ← Claude API wrapper
│   ├── rss.php                 ← RSS feed parser
│   ├── scraper.php             ← Web scraper utilities
│   └── helpers.php
│
├── config/
│   ├── config.example.php
│   └── rss-feeds.php           ← Default RSS feed lists by niche
│
├── database/
│   ├── migrations/
│   │   └── 001_initial.sql
│   └── seeds/
│       └── default_feeds.sql
│
├── templates/
│   ├── dashboard/              ← Dashboard HTML templates
│   ├── blog/                   ← Blog theme templates
│   └── email/                  ← Newsletter templates
│
├── logs/                       ← Agent logs
├── cache/                      ← Static blog page cache
├── CLAUDE.md
├── FEATURES.md
└── README.md
```
