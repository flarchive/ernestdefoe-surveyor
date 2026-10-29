# Surveyor — scope

An SEO workspace for Flarum 2, modelled on the XenForo add-on *Eggy SEO Autopilot*, but
built as a **companion** that never duplicates what other Flarum extensions already do.

## Division of labour

| Job | Owner | Surveyor's part |
| --- | --- | --- |
| Meta titles, descriptions, canonical, Open Graph, structured data | **fof/seo** | Reads the rendered pages and reports on them. Never outputs a tag. |
| Redirects and the 404 log | **ernestdefoe/detour** | Creates redirects through Detour's model and `Rules\Validator`; reads `detour_missing`. No redirect table of its own. |
| The sitemap (and `/robots.txt`) | **fof/sitemap** | Reads and validates it; uses it to find pages. (Submitting it to Google is phase 2.) |

All three are hard `require`s, so Flarum's dependency check enforces them.

## Phase 1 — built

1. **Admin workspace** — one admin page; only a small shell is in `admin.js`, the workspace is a
   lazy, namespaced chunk (`ernestdefoe-surveyor:admin/workspace/Workspace`). Nothing on the forum.
   Sections: Dashboard, Site audit (pages + duplicates), Link recovery, IndexNow, Rules,
   Diagnostics, Guide, Settings. No "coming soon" placeholders.
2. **Site audit** — `surveyor:audit` every minute from the scheduler (`withoutOverlapping`), plus
   "Audit now" and an in-tab "Continue" for forums without cron. Phases robots → sitemap → crawl,
   state kept in `surveyor_runs` so a run resumes after any interruption; a DB lock
   (`locked_until`) keeps the scheduler and the admin from crawling one run at once. Guest GETs,
   no cookies, UA `Surveyor/<version> (+forum url)`, on-site only (scheme + host + port + folder),
   redirects followed only while on-site, route deny-list, file extensions skipped, robots.txt
   obeyed, query strings dropped. Polite defaults: 20 pages/min, 500 pages/run, 10 s timeout,
   2 MB per page. Per page: status, final URL, title (+length), description (+length), canonical
   (+self), noindex (meta or header), h1 count, words of guest content, internal links out,
   images without alt, response time, bytes, JSON-LD presence and validity. Weighted score
   (README table, weights sum to 100), per-run score history (pruned to N runs), duplicate
   title/description groups. Large forums rotate: never-checked pages first, then oldest.
3. **Link recovery** — `surveyor_links` edges (source page → target page), targets grouped with
   their sources. Classes: ok, redirect, broken (404/410, or a hidden discussion),
   restricted (401/403, or a 404 for a discussion/tag/member that exists — Flarum answers guests
   404 for those), challenge (Cloudflare and similar), failed. Recheck, ignore/restore,
   "Create redirect" → Detour (mode "only if missing" by default). Detour's top 404s listed;
   Detour does not record user agents, so they are not split by search engine.
4. **IndexNow** — off until the admin turns it on (which makes a `random_bytes` 32-hex key served
   at `/{key}.txt`, a forum route that works in a subfolder). Lazy listeners on discussion and
   post events do one upsert into `surveyor_indexnow_queue`; `surveyor:indexnow` every five
   minutes sends up to 500 per batch. Guest-visible discussions only; at most once per discussion
   per hour; removed/private discussions resend only an address that was public before; back-off
   after 403 (6 h), 429 (1 h), other failures (15 min); log of the last 200 requests; manual
   submit (100 addresses, on-site and eligible only). Diagnostics warns when
   `blenderrus/flarum-indexnow` is also enabled.
5. **Rules** — exact / wildcard (`*`, `?`) / regex, exclude or include-only, for the audit,
   IndexNow or both. Regex: 300-char cap, nested quantifiers refused, compile check, and a
   100 000 backtrack limit on every match.
6. **Diagnostics** — companions enabled + versions; conflicts (blenderrus-indexnow,
   v17development-seo); https; server reaches itself; home canonical; sitemap reachable, valid,
   on-host; robots.txt reachable, not blocking, lists the sitemap; scheduler last run
   (`flarum:schedule:last_run`) and Surveyor's last tick; queue driver (info); IndexNow key file;
   tables present. 5 s timeout per fetch, and the network checks are skipped when the home page
   is unreachable, so the page always answers inside PHP's 30 s limit.
7. **Guide** — translatable plain-words help per section.

## Phase 2 — Google Search Console — built

- Connect with a **service account** JSON key. Flarum 2 has no application key or encrypter, so
  secrets (Google key, PageSpeed key, Anthropic key) are AES-256-GCM encrypted in a
  `surveyor_secrets` table with a 32-byte key in `storage/surveyor.key` (0600, created on first
  use) — never in `settings`, which Flarum sends to the admin page. Never returned to a browser;
  only "connected as …@….iam.gserviceaccount.com". Key files whose `token_uri` is not
  `https://oauth2.googleapis.com/token` are refused. JWT (RS256) signed server-side with
  `openssl_sign`, exchanged for a token cached until two minutes before expiry, per scope.
  Scopes: `webmasters.readonly` (analytics, URL Inspection); `webmasters` only for sitemap
  submission. The Indexing API is not used (Google limits it to job postings and livestreams).
- All outside HTTP goes through an injectable `Http\ApiClient` (tests use a fake; no test reaches
  the network). Every call is logged (`surveyor_api_log`) and failures pause the service.
- Search analytics per page and per query (clicks, impressions, CTR, position), daily rows
  stored with history; a scheduled pull with a per-day quota budget and cached failures.
- URL Inspection for audited pages (indexed verdict, last crawl, canonical Google chose,
  rich results verdict), rotated within the 2 000/day quota.
- Sitemap submission of fof/sitemap's index (`sitemaps.submit`), with status read back.
- **Opportunities**: high impressions / low CTR, positions 8–20, declining pages (vs the
  previous 28 days); High/Medium/Low potential, each with a recommended action that links to
  the right place (FoF SEO's description field, the page, Detour).

## Phase 3 — performance, regressions, internal links, AI — built

- **PageSpeed Insights / Lighthouse / Core Web Vitals / CrUX** per page with history (Google API
  key; quota-aware queue; mobile + desktop; LCP, INP, CLS; failures cached).
- **Regression guard**: compare each page's audit score, Search Console visibility and
  performance with its last healthy measurement and flag drops (with thresholds), on the
  dashboard and in its own section (no emails).
- **Internal links analysis**: orphan pages (in the sitemap, no inbound links), pages with few
  inbound links, deep pages; an optional "related discussions" block for guests (off by default,
  cached, measured for zero added queries when off).
- **Optional AI descriptions**: fof/seo stores a per-object description in `seo_meta`
  (`object_type` + `object_id`, `description`, `auto_update_data`). `auto_update_data = 0` means
  written by hand in FoF SEO: never replaced. Otherwise a previewed (and editable) suggestion is
  written there with `auto_update_data = 0` (or fof/seo's next reply would overwrite it); the
  previous value is kept for Undo, which restores it only if nobody has changed it since.
  Provider interface; Anthropic Messages API first (`claude-haiku-5-5`, thinking off,
  `max_tokens` 200, 160 characters, first post cut to 6 000 characters), over raw HTTPS through
  the injectable client (no SDK dependency on every forum). Caps per day, per minute, tokens per
  month; refusals and errors are reported, never saved. No fof/seo PR needed.
- **Related discussions block**: `Extend\Conditional::whenSetting(related_enabled)` applies
  `Links\RelatedBlock` (forum JS + CSS + a `surveyorRelated` attribute on the discussion *show*
  endpoint only). Off: nothing loaded. On: one query (guest-visible discussions sharing a tag,
  ranked in PHP by shared tags, #hashtags, title words) per discussion per six hours, cached.
  Saving the setting marks the frontend assets dirty so Flarum rebuilds them.

## Not doing

- No meta tags, canonical, structured data, sitemap or redirect storage of its own.
- No crawling of other sites; no following links off the forum.
- No forum-side JavaScript or CSS.
