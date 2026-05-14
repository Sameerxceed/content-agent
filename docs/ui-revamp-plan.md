# UI Revamp Plan

**Why now:** Features accumulated organically over several sessions. The site.php hub is now ~8 widgets tall, the dashboard sidebar has only "Dashboard + Settings" but we have ~12 dedicated pages, and the visual metaphors are inconsistent (some links are stat cards, some are action buttons, some are accordion sections). Time to consolidate.

**Goal:** A new user lands and immediately knows: *where am I, what should I do next, where do I find X*. Power users get fewer clicks and tighter visual density.

---

## What's wrong today

| Symptom | Problem |
|---|---|
| site.php has Business Focus + GSC + Distribution + Snippet + CMS toggle + 9 stat cards + accordion sections + recent activity | Cognitive overload. No clear primary action. |
| 12 dedicated pages, sidebar lists only 2 of them | No navigation to anything except via guessing or stat cards |
| Some features are stat cards (Keywords, Competitors), some are action buttons (Find Keywords, Content Planner), some are accordion sections (Content, Audit) | User has to learn three different patterns |
| Settings is one long scroll | Hard to find specific integration config |
| No "what next?" signal — user must already know what they're doing | Bad for new users; lower retention |
| Defaults aren't safe-by-default visually (the snippet kill switch is buried in an accordion) | Critical safety stuff should be top-of-page |

---

## Design principles

1. **One primary action per page.** Every screen answers: "what does the user do here?"
2. **Two-level navigation.** Global sidebar (Sites, Settings) + per-site top tabs (Overview / Content / SEO / Channels / Competitors / Alerts).
3. **Stat cards click → drill-down. Action buttons trigger. Accordion sections die.** Pick one metaphor, stick to it.
4. **Overview = dashboard, not control panel.** At-a-glance health, next action, recent activity. Detailed work happens on sub-pages.
5. **Progressive disclosure.** Setup gates (Business Focus, integration connect) only appear when something needs setting up. Once green, they collapse out of the way.
6. **Safety controls visible, not buried.** Snippet kill switch, CMS publish toggle = top-right utility area, not deep in an accordion.

---

## New information architecture

### Global (left sidebar — always visible)

```
🏠 Dashboard        ← cross-site overview (which sites need attention)
📋 Sites            ← list of all sites, click to enter
⚙️ Settings         ← global config (API keys, profile)
```

### Per-site (top tabs — visible when inside a site)

```
Overview  Content  SEO  Channels  Competitors  Alerts
   ↓        ↓       ↓      ↓          ↓           ↓
```

| Tab | What lives here |
|---|---|
| **Overview** | Top-line health: SEO score, posts published, alerts count, next scheduled post. "What's next?" widget. Recent activity feed. |
| **Content** | Sub-tabs: Drafts · Published · Calendar · Write New. Default to most-recent activity. |
| **SEO** | Sub-tabs: Keywords · SERP briefs · Audit · Health Report · GSC dashboard. |
| **Channels** | Connection cards (LinkedIn / Twitter / Reddit / CMS / Email) + per-channel default settings + recently published variants. |
| **Competitors** | Sub-tabs: Competitors · Content Gaps · Brand Mentions. |
| **Alerts** | Sub-tabs: Unread · All · Settings (digest opt-in). |

### Top utility bar (right side of header, always visible per site)

```
[Site name ▾]    Business Focus ✓    Snippet OFF    [Health Report] [Edit] [Logout]
```

- **Business Focus ✓** badge — green if set, yellow if not, click → modal to edit
- **Snippet OFF / ON** badge — red if ON (dangerous), grey if OFF, click → modal with the kill switch + mode toggle
- Health Report + Edit buttons stay where they are

These are the two **safety-critical** controls — always visible.

---

## Page-by-page changes

### `dashboard/index.php` (global Dashboard)
- **Today:** lists sites as cards with basic stats
- **Future:** "Sites needing attention" — sites with unread alerts, dropped rankings, content gaps. Sort by urgency. Each card shows what to do.

### `dashboard/site.php` (per-site Overview)
- **Today:** giant scroll
- **Future:** clean dashboard
  - 4 KPI cards at top: SEO Score, Posts (week), Active Channels, Open Alerts
  - **"What's next?" widget** — single recommended action based on state (e.g. "Connect LinkedIn to enable distribution" or "Run gap analysis — 12 days since last")
  - Recent activity feed (last 10 things — published posts, alerts, GSC sync, etc.)
  - Setup wizard banner ONLY if something critical is missing (Business Focus empty, no GSC, etc.)

### `dashboard/content.php` (NEW — replaces direct posts.php access)
- Sub-tabs: Drafts | Published | Calendar | Write
- Drafts tab: list of unpublished posts with quick "Edit / Generate channels / Publish" actions
- Published tab: list of live posts with channel status per row
- Calendar tab: the existing calendar page embedded
- Write tab: launches the AI Writer

### `dashboard/seo.php` (NEW — consolidates keywords/audit/health)
- Sub-tabs: Keywords | SERP Briefs | Audit | Health Report | GSC Dashboard
- Existing pages migrate into tabs here

### `dashboard/channels.php` (NEW — replaces the inline distribution card)
- One section per channel: connection state, last published, default behaviour
- Reorder priority of channels via drag
- Per-channel "default subreddit" / "default LinkedIn profile" settings

### `dashboard/competitors-hub.php` (NEW — merges competitors.php + content-gaps.php)
- Sub-tabs: Competitors | Content Gaps | Brand Mentions

### `dashboard/alerts.php` — keep as-is (already good)

---

## Component patterns (use these everywhere)

### KPI card
```
┌──────────────────────┐
│ Label (small caps)   │
│ Big Number           │
│ Trend ↑ +12          │  ← change vs prev period
└──────────────────────┘
```

### What's-next widget
```
┌─────────────────────────────────────────────────────────┐
│ 💡 Next: Connect LinkedIn to distribute posts          │
│ Posts you've written aren't being shared anywhere yet. │
│ Connecting LinkedIn takes 30 seconds.                  │
│ [Connect LinkedIn →]            [Dismiss]              │
└─────────────────────────────────────────────────────────┘
```

### Section header
```
SEO Performance                                          [View all →]
─────────────────────────────────────────────────────────────────
```

### Empty state
```
┌─────────────────────────────────────┐
│            ✏️                       │
│   No posts yet                      │
│   Write your first post to get      │
│   started.                          │
│   [Write a post →]                  │
└─────────────────────────────────────┘
```

---

## Migration / phasing

**Phase 1 — Navigation skeleton + Overview redesign (3 hours)**
- New tabbed nav in `templates/dashboard/layout.php`
- Rebuild `dashboard/site.php` as a clean Overview (KPIs + What's next + recent activity)
- Move site-specific actions (Business Focus, Snippet) into a header utility bar
- All existing pages keep working (URLs unchanged)
- **Ship point:** when you open a site, it feels like a real dashboard. Existing deep pages still accessible.

**Phase 2 — Consolidate content (2 hours)**
- New `dashboard/content.php` with Drafts/Published/Calendar/Write tabs
- Redirect old posts.php URLs to content.php?tab=...
- AI Writer becomes a tab, not a separate page

**Phase 3 — Consolidate SEO (2 hours)**
- New `dashboard/seo.php` with Keywords/SERP/Audit/Health/GSC tabs
- Migrate existing pages as included tab content
- Redirect old URLs

**Phase 4 — Consolidate competitors/channels (2 hours)**
- New `dashboard/competitors-hub.php` and `dashboard/channels.php`
- Redirect old URLs

**Phase 5 — Component library + polish (2 hours)**
- Extract KPI card, section header, empty state into reusable PHP partials
- Apply consistently across all pages

**Total: ~11 hours / 2-3 focused sessions.**

Phase 1 alone (3 hours) gives ~70% of the visual improvement. Phases 2-4 are about consolidating sprawl. Phase 5 is polish.

---

## What's NOT changing

- Database schema (no migrations needed for UI work)
- API endpoints (existing JSON APIs untouched)
- Cron jobs / background workers
- Channel adapters (the per-channel logic stays)
- Authentication / permissions
- Old URLs (they'll redirect to new locations — bookmarks won't break)

---

## Open decisions

1. **Top tabs vs left sub-nav per site?** Top tabs cleaner for 6 items. Left would scale further but feels heavier.
   _Recommend: top tabs._

2. **Keep stat cards on Overview, or replace with smaller KPI tiles?** Current stat-card row is 9 wide — too much.
   _Recommend: 4 large KPI tiles at top (Score, Posts/week, Alerts, Channels active) + everything else moves into the sub-page._

3. **"Setup wizard" for new sites?** First-time site creation could walk through Business Focus → Connect GSC → Pick channels.
   _Recommend: not now — defer until billing/onboarding is real._

4. **Mobile?** All work is desktop right now. Should we design responsive?
   _Recommend: not yet — assume desktop, fix mobile when a real customer mentions it._

5. **Color / design system?** Keep current palette (navy primary, orange accent) or refresh?
   _Recommend: keep — palette is fine, the issue is layout density and IA._

---

## My recommendation

Build **Phase 1** first (Navigation skeleton + Overview redesign — 3 hours). That gives the biggest visible improvement. Then test the feel for 24 hours before deciding if Phases 2-5 are worth the full sweep or if we just leave the old URLs as-is.

If you agree, I start with Phase 1.
