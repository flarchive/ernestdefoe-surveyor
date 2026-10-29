# Surveyor

**An SEO workspace for Flarum 2, built to sit beside FoF SEO, FoF Sitemap and Detour.**

Your forum already has the parts: FoF SEO writes the titles, descriptions, canonical addresses and structured data; FoF Sitemap lists your pages for search engines; Detour keeps your redirects and logs the addresses that 404. What nobody tells you is whether it all *works*: which pages have no description, which links in old posts now lead nowhere, whether robots.txt still points at your sitemap, whether search engines heard about the discussion you started this morning.

Surveyor checks, shows you what to fix first, and fixes it through those extensions. It never writes a meta tag, never keeps a redirect of its own and never builds a sitemap.

![Dashboard](screenshots/dashboard.png)

## What it does

- **Dashboard.** Your average score and its trend over recent audits, how many pages were checked, links to fix, duplicate titles and descriptions, IndexNow, Detour's 404 count, and the issues found on the most pages, so you know where to start.
- **Site audit.** Surveyor reads robots.txt and your sitemap, then visits each page the way a signed-out visitor would, a few a minute. For every page it records the answer, where it ends up after redirects, title and its length, meta description and its length, canonical address (and whether it points at the page itself), noindex (meta tag or `X-Robots-Tag` header), main headings, words of content, links to your own pages, images without alt text, structured data (and whether it can be read), response time and size. Each page gets a score, and its score history is kept.
- **Duplicates.** Pages that share one title or one description, grouped. Pages whose canonical points elsewhere are left out: they already say they are copies.
- **Link recovery.** Every link on your pages to another page of your forum is checked and grouped by where it goes, with the pages that link there. Broken links (404, 410) get a **Create redirect** button that makes a Detour redirect. Links to pages only members can see are shown as *members only*, not broken. Redirects, security challenges (such as Cloudflare's "Just a moment…") and failed requests are flagged too. Recheck one, or ignore it. Underneath: the addresses Detour has logged as 404 most often.
- **IndexNow.** Tells Bing, Yandex, Seznam, Naver and other IndexNow search engines when a discussion is started, replied to, edited, renamed, hidden, restored or deleted. Batched every five minutes, each discussion at most once an hour, only discussions a guest can see. Send addresses by hand too. (Google does not use IndexNow.)
- **Rules.** Leave parts of the forum out of the audit, IndexNow or both, or limit them to some parts. Exact addresses, wildcards (`/t/staff*`) or regular expressions, with a box to try an address.
- **Diagnostics.** Live checks with an explanation for each: FoF SEO, FoF Sitemap and Detour enabled (and their versions); conflicting extensions; https; the server can reach its own forum; the home page's canonical; the sitemap answers, is valid and lists only your own addresses; robots.txt answers, does not block the forum and lists the sitemap; Flarum's scheduler runs; the IndexNow key file opens; Surveyor's tables exist.
- **Search Console.** Connect Google Search Console with a service account and see clicks, impressions, click-through rate and position for every page and the queries that find it, the last 28 days against the 28 before, with daily totals and weekly history kept in Surveyor's own tables. Inspect a page to see whether Google has indexed it, when it last crawled it, which canonical it chose and whether its rich results are valid. Submit FoF Sitemap's sitemap to Google.
- **Opportunities.** Pages seen often but rarely clicked, pages at positions 8–20 close to page one, and pages losing clicks, each marked High, Medium or Low by the clicks it could win, with the queries behind it, the audit's view of the page and what to do.
- **Speed.** PageSpeed Insights for a page on a phone and on a desktop: the Lighthouse score and timings, and Core Web Vitals from real Chrome visitors (LCP, INP, CLS) when Google has them. Measure by hand, or have your busiest pages measured every week. History is kept.
- **Regressions.** One list of what got worse: pages that stopped working, audit scores down 10 points, search clicks down 30%, speed scores down 10 points or pages loading 25% slower, each against its last healthy measurement.
- **Internal links.** Orphan pages nothing links to, pages with a single link in, your hubs, and related discussions worth linking (shared tags, #hashtags and title words). Optionally, a **related discussions** block under each discussion's first post (off by default).
- **AI descriptions (optional, off by default).** An AI suggests a meta description for a discussion; you read it, edit it and save it into FoF SEO, or not. Hand-written descriptions are never replaced, and every change can be undone.
- **Guide.** A short built-in explanation of each section, including how to get a Google key.

![Site audit](screenshots/site-audit.png)

![Search Console](screenshots/search-console.png)

## How the score works

Every page starts at 100 and loses points for each problem:

| Problem | Points |
| --- | --- |
| No title | 15 |
| Title under 10 or over 65 characters | 10 |
| No meta description | 15 |
| Description under 50 or over 160 characters | 5 |
| No canonical address | 10 |
| Canonical points at another address | 5 |
| Asks not to be indexed (noindex) | 10 |
| No main heading (h1), or more than one | 10 |
| Under 50 words of content for a guest | 5 |
| Images without alt text | 5 |
| No structured data, or structured data that cannot be read | 5 |
| Slower than 1.5 seconds | 5 |

Only pages that answer 200 with HTML are scored. Duplicates are shown separately and do not change a page's score.

## Opportunities, in numbers

From the last 28 days in search:

| Kind | When | Potential (extra clicks a month) |
| --- | --- | --- |
| Seen but not clicked | 100+ impressions and a click-through rate under half of what is usual at that position | impressions × (usual rate − actual rate) |
| Almost on page one | average position 8–20 and 50+ impressions | what position 5 would bring, less today's clicks |
| Losing clicks | 10+ clicks before, down 30% or more | the clicks lost |

High from 50, Medium from 10, else Low. The usual rates are a typical organic curve: 28% at position 1, 15% at 2, 10% at 3, 5% at 5, about 2% at 10.

## Getting a Google service account key

Surveyor reads Search Console as a service account: a Google identity for a program, with a key file instead of a password.

1. In the [Google Cloud console](https://console.cloud.google.com/), create a project (or pick one).
2. Under **APIs and services**, enable the **Google Search Console API** (and the **PageSpeed Insights API** if you want speed measurements on your own allowance).
3. Under **IAM and admin → Service accounts**, create a service account. It needs no roles in the project.
4. Open it, **Keys → Add key → Create new key → JSON**. A `.json` file downloads: that is the key. Keep it private.
5. In [Search Console](https://search.google.com/search-console), open your property, **Settings → Users and permissions → Add user**, and add the service account's email (it ends in `.iam.gserviceaccount.com`). **Restricted** is enough to read; choose **Full** if Surveyor should submit your sitemap.
6. In Surveyor's **Search Console** section, paste the whole file and the property name — `sc-domain:example.com` for a domain property, or `https://example.com/` for a URL-prefix property — and press **Save and connect**.

If your Google organisation blocks service account keys (the policy `iam.disableServiceAccountKeyCreation`), an administrator can allow them for this one project.

## Requirements

- Flarum 2.0 or later, PHP 8.3 or later
- [FoF SEO](https://github.com/FriendsOfFlarum/seo) 4.0 (beta)
- [FoF Sitemap](https://github.com/FriendsOfFlarum/sitemap) 3.0
- [Detour](https://github.com/ernestdefoe/detour) 1.0
- Flarum's scheduler, for audits and IndexNow to run in the background ([how to set it up](https://docs.flarum.org/scheduler))

Composer installs the three companions for you, and Flarum will not let you enable Surveyor until they are enabled.

## Install

FoF SEO for Flarum 2 is still a beta, so on most forums it has to be asked for by name:

```
composer require fof/seo:"^4.0@beta" ernestdefoe/surveyor
```

Then enable **FoF Sitemap**, **FoF SEO**, **Detour** and **Surveyor** in the admin panel, open Surveyor, and press **Audit now** on the Site audit page.

## Update

```
composer update ernestdefoe/surveyor
php flarum migrate
php flarum cache:clear
```

## Light on your forum

- **Nothing on your pages.** Surveyor adds no JavaScript, CSS or data to the forum, and not one database query to a page view or an API request. (Its tests measure the discussion list and `/api/discussions`, as a guest and as an admin, with 1 and with 20 discussions, Surveyor on and off: the same queries every time.) The admin page loads its workspace only when you open it.
- **Gentle crawling.** By default 20 pages a minute (one every three seconds), at most 500 pages per audit, a 10 second limit per page, 2 MB read per page at most. A big forum is covered over several audits: each one starts with pages never checked, then those checked longest ago.
- **IndexNow costs one small database write** when a discussion changes, and nothing at all while IndexNow is off.
- **History is pruned.** The last 12 audits are kept; pages that no longer appear anywhere are forgotten after three audits. Search history keeps 26 weeks per page and 16 months of daily totals; speed keeps the newest 20 measurements per page and device.
- **The related-discussions block is off by default.** Off, the forum loads nothing of Surveyor's. On, it loads one small script and stylesheet, and each discussion page costs one extra query at most once every six hours (the list is cached); a cached page costs exactly what it would with the block off. Both are measured in the tests.

![Related discussions under the first post](screenshots/related-discussions.png)

## Every outside call, and its limits

Nothing below happens until you set it up, and every call has a timeout, a cap and a pause after failures (429: an hour or Google's `Retry-After`; 401/403: six hours; errors: 15 minutes).

| Service | When | Caps |
| --- | --- | --- |
| Your own forum (the audit) | Scheduled and "Audit now" | 20 pages a minute, 500 per audit, 10 s each (settings) |
| IndexNow | Every 5 minutes, once on | 500 addresses per request, each discussion once an hour |
| Google Search Console | Daily refresh (or weekly, or by hand) | At most 11 requests a refresh, 30 s each |
| URL Inspection | Only when you press Inspect | 200 a day (setting, Google allows 2,000), 10 a minute |
| Sitemap submission | Only when you press Submit | One request |
| PageSpeed Insights | One a minute from the scheduler, when queued | 25 a day (setting), 70 s each |
| Anthropic (AI descriptions) | Only when you press Suggest | 50 a day, 5 a minute, 500,000 tokens a month (settings), 200 tokens per answer, 6,000 characters of the first post |

With Claude Haiku 5.5 a description costs roughly $0.0001; the monthly budget above is about a dollar at most.

## Safe by design

- **It only visits your own forum.** Same scheme, host and port as your forum's address, inside its folder if it lives in one. Redirects to anywhere else are recorded, never followed. External links in posts are never requested.
- **As a guest, politely.** No cookies, a clear user agent (`Surveyor/<version> (+your forum's address)`), robots.txt obeyed (a group for `Surveyor` if you write one, else `*`), and private pages never visited: settings, notifications, admin, the API, sign in and sign up, password reset, email confirmation, messages.
- **Nothing private reaches IndexNow.** Only discussions a guest can see are sent. A discussion that becomes hidden or private is sent once more only if its address was public before, so search engines drop it; an address never sent is never revealed.
- **Text only.** Titles and descriptions from your pages are shown as text, never as HTML.
- **Admins only.** Every Surveyor endpoint refuses everyone else.
- **Redirects go through Detour's own checks**, so Surveyor can never make one Detour would refuse (another host, the home page, a loop).
- **Regular expressions can't hang the forum.** Up to 300 characters, nested repeats refused, and a hard backtracking limit on every match.
- **Keys are encrypted at rest.** The Google service account, the PageSpeed key and the Anthropic key are stored with AES-256-GCM in Surveyor's own table, not in Flarum's settings (which are all sent to the admin page). The encryption key is a file in Flarum's `storage` folder (`storage/surveyor.key`, readable by its owner only), so a database dump alone reveals nothing. A key is never sent back to the browser: the admin sees only that one is set, and the service account's email. Back up `storage/surveyor.key` with the database, or paste the keys again after a move.
- **A key file can only talk to Google.** A service account file naming any token address other than Google's own is refused, so a crafted file cannot send a signed request elsewhere.
- **AI only sees public discussions.** Only the title and first post of a discussion a guest can see are sent, marked as material to describe and never as instructions, and nothing is saved until you press Save.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Start a new audit | Every week | Daily, weekly, monthly, or only when you press Audit now |
| Pages per audit | 500 | The most pages one audit checks |
| Pages per minute | 20 | How fast an audit goes |
| Wait for a page | 10 seconds | Longer than this, and the page is recorded as failed (2–20) |
| Audits of history to keep | 12 | Older score history is removed |
| Refresh Search Console data | Every day | Every day, every week, or only when you press Refresh |
| URL inspections per day | 200 | Up to 2,000 (Google's limit) |
| Measure speed automatically | Off | Weekly: the pages with the most search clicks (or most links) |
| Pages measured each week | 10 | Each twice, phone and desktop |
| Speed measurements per day | 25 | Keep low without a PageSpeed API key |
| Show related discussions under the first post | Off | Adds the block (and its one cached query) |
| Related discussions shown | 5 | Up to 10 |
| AI descriptions | Off | Nothing is sent to an AI provider while off |
| AI model | `claude-haiku-5-5` | Any Anthropic model id |
| AI descriptions per day | 50 | |
| AI tokens per month | 500,000 | Sent and received, together |

IndexNow is switched on and off in its own section; turning it on makes a random key and serves it at `/<key>.txt`.

## Troubleshooting

**The audit never moves.** Audits run from Flarum's scheduler: `* * * * * cd /path/to/flarum && php flarum schedule:run >> /dev/null 2>&1` in cron. Diagnostics says whether it runs. Until it does, an audit you start keeps going while its admin page is open (**Continue in this tab**), or run `php flarum surveyor:audit --start` yourself.

**"The server cannot fetch the forum's home page".** Surveyor fetches pages from your forum's own address (the `url` in `config.php`). Check that address is right, and that the server can reach itself on it; some hosts and firewalls block this. PHP's built-in server (`php -S`) answers one request at a time, so on a test install started that way the checks and the in-tab audit wait on themselves; use the console command there, or start it with `PHP_CLI_SERVER_WORKERS=4`.

**robots.txt or the IndexNow key file doesn't open.** FoF Sitemap answers `/robots.txt` and Surveyor answers `/<key>.txt`, but only if the request reaches Flarum. Some nginx setups answer every `.txt` address from disk. Let these reach Flarum's `index.php` (or put the files there yourself, with the same content).

**Every link to a private discussion shows as "members only".** That is right: guests can't open them, so search engines can't either. They are not broken. Ignore them, or move the links.

**IndexNow says 403.** The search engine could not open your key file. Fix that first (see above); Surveyor waits six hours after a 403, and an hour after a 429.

**Search Console says 403, or "User does not have sufficient permission".** The service account's email has not been added to the property in Search Console (step 5 above), or the property name does not match exactly: a domain property is `sc-domain:example.com`, a URL-prefix property is the address with `https://` and the trailing slash.

**Submitting the sitemap fails, but reading works.** Submitting needs **Full** permission for the service account; reading needs only Restricted.

**PageSpeed measurements fail with 429.** Without an API key Google's shared allowance is small. Add a free key from the Google Cloud console (enable the PageSpeed Insights API first).

**PageSpeed can't measure my test forum.** Google measures from the internet: a forum on `localhost` or behind a login can't be reached.

**"Surveyor could not read or create its key file".** Flarum's `storage` folder must be writable by the web server, as it already must be for Flarum's cache.

**The related block does not appear after switching it on.** Flarum rebuilds the forum's scripts on the next request; reload the page. It only shows discussions guests can see that share a tag with the one open.

**Another IndexNow extension is enabled.** Diagnostics warns about it: both would send every change. Disable one.

## Links

- [Source](https://github.com/ernestdefoe/surveyor)
- [Report an issue](https://github.com/ernestdefoe/surveyor/issues)
