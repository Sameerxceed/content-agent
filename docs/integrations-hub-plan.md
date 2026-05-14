# Self-Service Integrations Hub — Plan

**Why this:** Today's Google CSE setup took ~90 minutes of back-and-forth across at least 6 different Google screens, with errors that didn't tell us how to fix them. Any new customer onboarding ContentAgent would hit the same wall and likely give up. This needs to be a guided experience inside the platform.

**Goal:** A user can connect any required integration in under 5 minutes without external help — and when something goes wrong, the platform tells them exactly what to do next.

---

## What we observed today (the requirements written backwards)

The CSE setup needed:

1. Programmable Search Engine — create engine, copy cx
2. Google Cloud Console — enable Custom Search API
3. Google Cloud Console — link a billing account
4. Google Cloud Console — activate full account (not trial)
5. Google Cloud Console — create an API key with the right restrictions
6. Wait 5-30 min for propagation

Each step had its own gotchas (e.g. "Google Search Console API" vs "Custom Search API" dropdown confusion, trial vs full account, the `cx=...` extracted from the embed snippet rather than a clearly-labeled "ID" field). The error message at the end ("This project does not have the access to Custom Search JSON API") gave zero hint that the real issue might be billing/trial state.

Customers won't troubleshoot this. They'll bounce.

---

## Design principles

1. **One destination.** New page: `/dashboard/integrations` — every external service ContentAgent uses lives here. Settings → API Keys stops being a wall of text fields; it just lists status and links to the hub.

2. **Wizard per integration, never raw fields.** Each integration has a numbered wizard: open this link → do X → paste this value → test. Each step is one action, never a list of things to do.

3. **Validate every step.** Don't make the user enter all values then test at the end. Test after each input so failures surface immediately and we can show step-specific fixes.

4. **Smart errors.** When a test fails, the error message must say *what specifically to do next*. If Google returns "PERMISSION_DENIED" we don't show that — we show "Looks like billing isn't set up on the project — open it here →".

5. **Status visible everywhere.** Every feature page that needs an integration shows a banner if it's not connected: "Brand monitor needs Google Custom Search → Set up (2 min)". Click goes straight to the right wizard.

6. **Resumable.** Each wizard saves state per step. User can leave halfway, come back tomorrow, pick up where they were.

7. **No assumptions.** Don't assume the user knows what an API key is. Each step explains *why* we're doing it in one line.

---

## UX flow per integration (template)

```
┌───────────────────────────────────────────────────────────┐
│  🔍 Google Custom Search                  Status: ⚠ Not set│
│  Used for: competitor discovery, brand monitor, SERP brief│
│                                                            │
│  Why we need this: real Google search results, free tier  │
│  of 100 queries/day. No card required for free tier.      │
│                                                            │
│  [Start setup (4 steps)]                                  │
└───────────────────────────────────────────────────────────┘

When user clicks Start setup:

Step 1 of 4 · Create a Programmable Search Engine
─────────────────────────────────────────────────
We need an "engine ID" that tells Google to search the whole web.
This is free.

[ Open Google Programmable Search ↗ ] (opens new tab)

In the new tab:
 1. Click "Add"
 2. Name: "ContentAgent"
 3. Toggle "Search the entire web" ON
 4. Click Create
 5. After it's created, the engine page shows an embed snippet that looks like:
    <script src="...cse.js?cx=XXXXXXXXXX">
    Copy the value after cx=

Paste it here: [_____________]  [Verify]

If verify succeeds → "✓ Engine ID looks valid" + auto-advance to Step 2
If verify fails  → "Hmm, that doesn't look like an engine ID. It should be a string of letters/numbers, no dots or slashes. Try copying again."

─────────────────────────────────────────────────
Step 2 of 4 · Enable the Custom Search API
... etc
```

Each step ends with a **Test** action that hits a real endpoint and either says ✓ or shows specific guidance.

---

## Technical architecture

### Database

```sql
CREATE TABLE integration_setup_progress (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  integration VARCHAR(50) NOT NULL,    -- 'google_cse', 'reddit_oauth', 'resend', etc
  current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('in_progress', 'tested_ok', 'failed') NOT NULL DEFAULT 'in_progress',
  state_json JSON DEFAULT NULL,        -- per-step values being collected
  last_test_result JSON DEFAULT NULL,  -- what happened on last test
  last_attempted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_user_integration (user_id, integration)
);
```

Final values still go to `config.php` (where they are today). The new table is just for the wizard journey.

### Code structure

```
includes/
  integrations/
    base.php              ← abstract InitWizard class
    google-cse.php        ← extends base, defines steps + test logic
    reddit-oauth.php
    google-search-console.php
    resend.php
    claude.php

public/
  dashboard/
    integrations.php      ← the hub: cards + wizard renderer
  api/
    integration-step.php  ← POST validates+saves one step's input
    integration-test.php  ← POST runs the full test for an integration
```

Each integration class declares:
- name, description, why_needed
- list of steps (title, instructions, external link, input fields, validation)
- a `test()` method that makes a real API call
- a `parse_error(response)` method that turns API errors into actionable user guidance

### Smart error mapping (the key bit)

```php
// google-cse.php
function parse_error($http_status, $body) {
    if ($http_status === 403 && str_contains($body, 'does not have the access')) {
        return [
            'title' => 'Billing or activation issue on your Google Cloud project',
            'fixes' => [
                ['label' => 'Link a billing account', 'url' => 'https://console.cloud.google.com/billing/linkedaccount?project=' . $project_id],
                ['label' => 'Activate full account (not trial)', 'url' => 'https://console.cloud.google.com/...'],
            ],
            'wait' => 'After fixing, wait 5 minutes for Google to propagate, then click Test again',
        ];
    }
    if ($http_status === 400 && str_contains($body, 'API key not valid')) {
        return [
            'title' => 'API key value looks wrong',
            'fixes' => [
                ['label' => 'Copy the key again from Google', 'url' => 'https://console.cloud.google.com/apis/credentials?project=' . $project_id],
            ],
        ];
    }
    // ...
}
```

This is where we capture the institutional knowledge we just earned the hard way.

---

## Integrations to cover (priority order)

| # | Integration | Used by | Why first |
|---|---|---|---|
| 1 | **Claude (Haiku) API key** | Everything | Without it nothing AI runs |
| 2 | **Google Custom Search** | Competitor discovery, brand monitor, SERP brief, AI Presence web fallback | Today's pain point |
| 3 | **Resend (or other) Email** | Weekly digest, alert emails | Needed for digest to fire |
| 4 | **Google Search Console OAuth** | Real keyword data | Already partially built |
| 5 | **Reddit OAuth** | AI Presence Reddit results | Reddit blocks server IPs |
| 6 | LinkedIn OAuth | (Future: posting) | Coming with Bundle C |
| 7 | DataForSEO API key | (Future: real search volume) | Optional power-user upgrade |

Phase 1 should ship 1-5. The rest follow as we build the features that need them.

---

## What's IN scope for this build

- New `/dashboard/integrations` hub page
- Wizard infrastructure (base class + per-integration definitions)
- Smart error mapping per integration (the 5-6 most common Google CSE failure modes captured from today's session)
- Status badges on each integration card
- Banners on feature pages ("Brand monitor needs Google Custom Search → Set up")
- Each wizard step persists progress so user can resume

## What's OUT of scope

- **Automated browser-driven setup** (Selenium-style) — too brittle, Google constantly changes their UI
- **OAuth proxy / Google partner program** — out of reach for a small app
- **Pre-filling values for the user** — they have to do the clicks; we just guide them clearly
- **In-app fix for billing/trial issues** — those happen on Google's side; we can only detect + direct

---

## Effort estimate

- **Wizard infrastructure** (base class, step renderer, state persistence): ~3 hours
- **Hub page UI** with cards + status: ~1 hour
- **Per-integration wizard definitions** (5 integrations × ~30 min each): ~2.5 hours
- **Smart error mappers** (the institutional knowledge): ~1 hour
- **Feature-page banners** ("you need X to use Y → set up"): ~1 hour
- **Testing & polish**: ~1.5 hours

**Total: ~10 hours.** Roughly a full focused day, or 2-3 evenings.

---

## Phased rollout

**Phase 1 (must)** — Hub + Google CSE wizard only.
Because that's the pain we just lived through, and validates the design.
Ship in ~4 hours.

**Phase 2 (next)** — Add Reddit OAuth + Resend Email wizards.
Reddit needs it (blocked from Linode IPs without OAuth).
Resend is needed for weekly digest to actually reach customers.
~3 hours.

**Phase 3 (last)** — Claude key + Google Search Console wizards.
Lower priority (Claude is usually set up day 1, GSC OAuth is per-site not global).
~3 hours.

---

## Open decisions before we build

1. **Where does this live in navigation?** Replace Settings → API Keys entirely with a link to /dashboard/integrations? Or keep both (raw keys for power users, wizard for first-timers)?

2. **Do wizards live inside Settings, or top-level?** Settings is currently buried in the sidebar. Integrations might deserve its own sidebar item ("🔌 Integrations") since users will visit it often during setup.

3. **Per-user or per-site state?** Some integrations are global (Claude API key) — others are per-site (GSC OAuth, since each site has its own Search Console property). The wizard state table should handle both.

4. **Detection of partially-set-up state.** If a user already has `google_cse_api_key` and `google_cse_cx` in config from before this feature shipped, we should auto-detect that and mark the integration as "tested needed" rather than restart the wizard.

5. **Test cadence.** Should we periodically re-test integrations (e.g. weekly via cron) and flip status to "broken" if Google revoked something? Or only test on demand?

---

## Recommended next step

Build **Phase 1 first** — just the hub + Google CSE wizard. Ship it, use it ourselves to verify it covers all the edge cases we hit today, then expand to the others. Saves us from over-engineering the wizard framework before we've proven the pattern.

If you agree:
- Today / next session: Phase 1 (~4 hours)
- Phase 2 after that
- Phase 3 last

If you want it differently — ship all integrations in one big push, or build wizard infra without any specific integration first — say so.
