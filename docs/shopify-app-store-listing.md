# Shopify App Store Listing — ContentAgent

Paste-ready copy for every field in **Partner Dashboard → ContentAgent → Distribution → Manage submission**.

Each section below is one form field. Numbers in brackets are Shopify's character limits — stay inside them.

---

## App name
**ContentAgent**

(15 chars / max 30)

---

## App handle (URL slug)
**contentagent**

This becomes `apps.shopify.com/contentagent`. Lower-case, no spaces.

---

## Tagline [max 30 chars]
**AI SEO + content for any store**

(29 chars)

Alternative options if Shopify rejects:
- `AI content engine for Shopify` (28)
- `SEO automation that ships` (24)
- `AI-driven SEO and content` (25)

---

## App categories
Pick TWO from Shopify's taxonomy:

1. **Marketing and conversion → SEO**
2. **Store management → Content**

---

## Languages
- **English** (primary)

(Add more later when we add localised UI.)

---

## App icon (1024×1024 PNG)
**BLOCKED — needs design.**

Brief:
- Square, transparent OR plain background
- Recognisable at 48×48 (App Store list view)
- Should hint at "AI + writing + automation"
- Suggested approach: stylised pen-stroke or document outline with a small AI/spark glyph
- Match Xceed brand palette: deep indigo + warm orange accent
- Avoid: photographs, busy gradients, small text (illegible at small sizes)

Deliver to: `e:\Xceed\Code\ContentAgent\docs\app-icon-1024.png`

---

## Feature image (1600×900 PNG, optional but recommended)
Shows at the top of the app's listing page.

Brief:
- Headline overlay: "AI content + SEO automation for any store"
- Sub-line: "Generate, optimise, publish — without leaving Shopify"
- Background: dashboard preview screenshot, lightly dimmed
- Bottom-right: small "Built for Shopify" placeholder (we don't qualify yet but layout for future)

---

## Screenshots (3-5 PNGs, 1280×800)
**BLOCKED — needs capture.**

Capture these screens from the live dashboard (use `Anna Lou of London` as the sample site — real data looks better than dummy):

1. **Site overview** (`/dashboard/site.php?site=8`) — shows the persistent stepper + hero metrics + integration pills. Crops to give a sense of breadth.
2. **301 Redirect Map** (`/dashboard/redirects.php?site=8`) — shows the 1,652 redirects with confidence buckets. Concrete proof of the SEO-recovery feature.
3. **Site Health hub** (`/dashboard/site-health.php?site=8`) — shows the 6-tab maintenance umbrella (Redirects, Page Speed, Schema, Freshness, 404, Fast indexing).
4. **AEO Tracker** (`/dashboard/aeo.php?site=8`) — shows the 3-engine citation tracking. Visible differentiator vs every other SEO app.
5. **Content Plan** (`/dashboard/content-plan.php?site=8`) — shows the 6-month topical cluster plan. The "engine" of the product.

Naming convention: `screenshot-01-overview.png`, `screenshot-02-redirects.png`, etc.

Save to: `e:\Xceed\Code\ContentAgent\docs\screenshots\`

---

## Short description [max 100 chars]
**Generate AI blog content, fix dead URLs, and track answer-engine visibility — without leaving your store.**

(99 chars)

---

## Detailed description (long-form, ~500-1000 words)

> **ContentAgent is the SEO + content team your store doesn't have.**
>
> Built for Shopify merchants who want to grow organic traffic without hiring an agency or wrestling with five different tools, ContentAgent automates the end-to-end content and SEO workflow inside one clean dashboard.
>
> ### What ContentAgent does
>
> **Generates blog content that ranks.**
> Tell ContentAgent your business focus once. The AI engine reads your product catalogue, infers your audience, identifies topical clusters relevant to your industry, and produces a 6-month content plan. Each draft is structured for SEO (proper headings, meta, schema markup, internal linking), reviewed by you, and published straight to your Shopify blog.
>
> **Recovers traffic from dead URLs.**
> Did you migrate to Shopify from another platform? You probably have hundreds (or thousands) of broken inbound links costing you organic traffic. ContentAgent's 301 Redirect Map scans your store's URL history, matches every dead URL to its best living replacement using AI, and bulk-imports the redirects to Shopify Navigation. Customers tell us they recover 60-90% of lost link equity in the first week.
>
> **Tracks visibility in AI answer engines.**
> Google is no longer the only game in town. ChatGPT, Claude, Perplexity, and Gemini are answering the same questions your customers used to type into search. ContentAgent's AEO Tracker checks which queries cite your store across three major AI engines, identifies gaps where competitors are winning, and generates a content brief to claim each opportunity.
>
> **Deploys a branded 404 page.**
> A real 404 page should help visitors find what they came for. ContentAgent generates a branded 404 page that matches your theme, surfaces your top collections, and includes a search prompt. One-click deploy to your active theme.
>
> **Audits the SEO basics no one wants to manage.**
> Image alt text, schema.org markup, page speed (Core Web Vitals), broken outbound links, content freshness, Google Merchant Center feed quality — ContentAgent audits all of it on a weekly cadence, flags issues, and (where safe) auto-applies the fix.
>
> ### Why merchants choose ContentAgent
>
> - **One dashboard, not five.** Content plan, redirects, schema, AEO tracking, and audits in a single workspace.
> - **AI does the draft, you approve.** Nothing publishes without you. Every brief, redirect, and audit fix is reviewable before it ships.
> - **No agency markup.** ContentAgent costs less than one hour of agency time per month and runs 24/7.
> - **Built by a small team that ships.** New features land every week. Email support replies in under 24 hours.
>
> ### What you get on every plan
>
> - Unlimited AI-generated blog drafts
> - 301 redirect map with bulk Shopify import
> - 3-engine AEO citation tracking (ChatGPT + Claude + Gemini)
> - Branded 404 page generator
> - On-page SEO audit (images, schema, links, freshness)
> - Google Search Console + Merchant Center integration
> - Webhook-driven freshness keeps your llms.txt and sitemaps current
>
> ### Get started in 60 seconds
>
> Click **Install**, authorise the read/write scopes ContentAgent needs (products, themes, content, redirects), and the dashboard auto-loads with your store data populated. No credit card required for the free tier.

---

## Pricing

**BLOCKED — decision needed:**

Three sensible options:

### Option A: Free for App Store listing, paid offline
- App listing shows "Free"
- We charge via Stripe / Razorpay for paying tiers (existing flow)
- Pro: zero billing-integration work to submit
- Con: Shopify can't show price tiers in their search, hurts discoverability

### Option B: Shopify Billing API tiers ($49 / $99 / $299)
- Matches existing ContentAgent plans
- Shopify handles billing, takes 15-20% revenue share
- ~1 day of work to wire Billing API
- Pro: native Shopify experience, better conversion
- Con: rev share

### Option C: Hybrid — Free + Pro ($49) on Shopify, higher tiers off-platform
- App Store shows Free + Pro
- Customers wanting Agency tier ($299) get redirected to direct sale
- Pro: best of both
- Con: more complex listing copy

**Recommendation:** Option B (Shopify Billing). Best UX, easier discovery, the 15% rev share is worth the lower friction.

---

## Compliance: GDPR webhooks
✅ Implemented at `/api/shopify-webhook.php`

Endpoints to register in Partner Dashboard:
- `Customer data request` → `https://contentagent.xceedtech.in/api/shopify-webhook.php`
- `Customer redact` → `https://contentagent.xceedtech.in/api/shopify-webhook.php`
- `Shop redact` → `https://contentagent.xceedtech.in/api/shopify-webhook.php`

(All three use the same endpoint — dispatched by `X-Shopify-Topic` header.)

ContentAgent stores no shopper PII; the first two webhooks acknowledge + log only. `shop/redact` triggers a full cascade delete of the site's data.

---

## URLs

| Field | Value |
|---|---|
| App URL | `https://contentagent.xceedtech.in/dashboard/setup.php` |
| Allowed redirection URL | `https://contentagent.xceedtech.in/api/oauth/shopify-callback.php` |
| **Privacy policy URL** | `https://contentagent.xceedtech.in/legal/privacy` ✅ live |
| **Terms of service URL** | `https://contentagent.xceedtech.in/legal/terms` ✅ live |
| **Support URL** | `https://contentagent.xceedtech.in/legal/support` ✅ live |
| Support email | `support@xceedtech.in` ⚠️ confirm mailbox exists |
| Privacy contact email | `privacy@xceedtech.in` ⚠️ create alias if needed |

---

## Scopes requested (read-only on App Store listing — automatic)

| Scope | Why |
|---|---|
| `write_content` | Publish blog articles and pages you've approved |
| `write_online_store_navigation` | Install 301 redirects you've approved |
| `read_products` | Audit product on-page SEO and Google Merchant Center feed |
| `read_themes` | Deploy the branded 404 page to your active theme |

---

## App listing setup checklist (for submission day)

- [ ] App icon designed (1024×1024 PNG)
- [ ] 5 screenshots captured (1280×800 PNG)
- [ ] Feature image designed (1600×900 PNG, optional)
- [ ] `support@xceedtech.in` mailbox confirmed working
- [ ] `privacy@xceedtech.in` alias created
- [ ] Pricing model decided (A / B / C above)
- [ ] If Option B or C: Shopify Billing API integration shipped (~1 day work)
- [ ] All 3 GDPR webhook URLs configured in Partner Dashboard
- [ ] `app/uninstalled` webhook URL configured
- [ ] Test install on `contentagent-test.myshopify.com` end-to-end after webhooks set
- [ ] Submit via Partner Dashboard → Distribution → Manage submission
- [ ] Expect 1-4 weeks for Shopify review
