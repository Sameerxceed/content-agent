# ContentAgent — Step-by-Step Workflow for Any New Website

## Phase 1: Onboarding (5 minutes)

### Step 1: Add the Site
- Dashboard → **+ Add Site**
- Enter domain (e.g. `example.com`)
- Enter site name

### Step 2: Configure Access
- Go to **Sites → Edit**
- Fill in:
  - **Topics/Niche** — comma-separated (e.g. "web design, ecommerce, SEO")
  - **Brand Colors** — pick 3 colors or let scanner detect
  - **Brand Fonts** — enter font names
  - **CMS URL + API Key** — if site has a CMS API
  - **Server Access** — FTP/SSH/Git credentials (for direct code changes)
  - **RSS Feeds** — news sources relevant to the niche

### Step 3: Scan the Site
- Click **🔍 Scan Site**
- Agent detects: platform, brand colors/fonts, social links, blog, content structure
- Results saved automatically

---

## Phase 2: Audit & Fix (10-30 minutes)

### Step 4: Run SEO Audit
- Click **📊 SEO Audit**
- Crawls all pages, checks: broken links, meta, canonical, schema, SSL, mobile, OG, alt text
- Shows score out of 100

### Step 5: Auto-Fix All Issues
- Click **🤖 Auto-Fix All Issues**
- Depending on access level:
  - **Full access (Git/FTP):** Pushes code changes directly
  - **CMS API:** Updates meta, deploys schema/llms.txt/robots.txt
  - **No access:** Populates page_seo table → served via JS snippet
- Progress bar shows each fix being applied

### Step 6: Verify Improvement
- Re-run **📊 SEO Audit**
- Compare before/after scores
- Export report via **Export CSV** for client presentation

---

## Phase 3: AI Discoverability (5 minutes)

### Step 7: AI SEO Audit
- Go to **AI SEO** page
- Select site → Run AI Audit
- Checks: llms.txt, AI crawler access, schema, structured data, heading structure

### Step 8: Deploy AI SEO Files
- Click **Fix All & Deploy to Website**
- Auto-generates and deploys: llms.txt, robots.txt (with AI crawler rules), schema markup

---

## Phase 4: Content Strategy (15-30 minutes)

### Step 9: Keyword Research
- Click **🔑 Find Keywords**
- Scrapes Google Autocomplete for keyword suggestions
- Groups into clusters for content planning

### Step 10: Write Content
- Go to **AI Writer** in sidebar
- Select site → AI proposes 4 blog topics based on keywords + trends + news
- Pick a topic (or write your own)
- AI writes the article in the brand's voice
- Review/edit in the editor
- Click **Publish to CMS** → goes live on the website

### Step 11: Scrape News
- Click **📰 Scrape News**
- Pulls relevant articles from RSS feeds
- Filters by topic relevance
- Saves as news posts (feed into content planner)

---

## Phase 5: Ongoing Automation (set and forget)

### Step 12: Set Agent Mode to Auto
- Sites → Edit → Agent Mode → **Auto**
- Agents will run on schedule without approval

### Step 13: Set Up Cron Jobs (on Linode)
```
# Daily: news scraper
0 6 * * * php /path/to/agent/news-scraper.php --all

# Mon + Thu: content planner (writes + publishes)
0 7 * * 1,4 php /path/to/agent/content-planner.php --site=1 --auto-publish

# Weekly Sunday: full cycle
0 8 * * 0 php /path/to/agent/keyword-research.php --site=1
0 9 * * 0 php /path/to/agent/seo-auditor.php --site=1
0 10 * * 0 php /path/to/agent/auto-fixer.php --site=1
0 11 * * 0 php /path/to/agent/evaluator.php --all
0 12 * * 0 php /path/to/agent/newsletter.php --all
```

### Step 14: Monitor Progress
- Dashboard shows weekly progress tracker
- SEO score trend chart (should go up over time)
- Content output tracking
- Export reports for clients

---

## Quick Reference: Agent Buttons

| Button | What it does | Needs API key? |
|---|---|---|
| 🔍 Scan Site | Detects platform, brand, structure | No (AI brand analysis needs key) |
| 📊 SEO Audit | Crawls pages, finds all SEO issues | No |
| 🤖 Auto-Fix All | Fixes issues, deploys to live site | Yes (for AI-generated meta/alt) |
| 🔑 Find Keywords | Google Autocomplete scraping | No (AI clustering needs key) |
| 🧠 AI Content Planner | Proposes + writes + publishes | Yes |
| 📰 Scrape News | RSS feed aggregation | No |
| 📈 Evaluate Strategy | AI reviews performance, recommends | Yes |

---

## Access Levels & What Gets Fixed

| Issue | No Access (Snippet) | CMS API | FTP/SSH | Git |
|---|---|---|---|---|
| Missing canonical | ✅ via JS | ✅ via API | ✅ direct | ✅ code push |
| Missing meta title | ✅ via JS | ✅ blog API | ✅ direct | ✅ code push |
| Missing meta desc | ✅ via JS | ✅ blog API | ✅ direct | ✅ code push |
| Missing OG tags | ✅ via JS | ✅ via API | ✅ direct | ✅ code push |
| Missing schema | ✅ via JS | ✅ deploy API | ✅ file push | ✅ code push |
| Broken links | ✅ redirect via JS | ✅ redirect API | ✅ .htaccess | ✅ code push |
| Missing llms.txt | ❌ | ✅ deploy API | ✅ file push | ✅ code push |
| Missing robots.txt | ❌ | ✅ deploy API | ✅ file push | ✅ code push |
| Missing pages | ❌ | ❌ | ❌ | ✅ code push |
| Blog posts | ❌ | ✅ blog API | ❌ | ❌ |
