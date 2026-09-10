# Agent Instructions — `mavo-for-you` WordPress Plugin (V0)

## Project context

This plugin belongs to the **Maman Voyage** WordPress site and the wider **mavo improvements** project.

The goal of `mavo-for-you` is to add a lightweight, privacy-conscious, session-only personalization layer to the site. It should recommend relevant unseen content based on the visitor's recent behaviour on Maman Voyage, without breaking full-page caching.

This is deliberately a **V0**. Keep the implementation small, understandable, debuggable, and easy to extend later.

Do **not** implement long-term profiling or hub-awareness yet.

---

# 1. Core product behaviour

Create a plugin named:

```text
mavo-for-you
```

The plugin should provide a personalized section near the end of eligible singular content pages.

Default placement:

```text
article/page content
↓
Mavo For You block
↓
comments, if present
↓
footer
```

The block should contain two parts kept together in V0:

1. **For You** — visually primary, personalized recommendations.
2. **Recently viewed** — visually secondary and less prominent.

The section should only become visible once there is enough meaningful browsing history to justify personalization.

Recommended V0 rule:

- Do not show after only one qualifying content view.
- Show after at least **2 meaningful content views**, provided there is at least one viable recommendation.
- If recommendations are weak or unavailable, do not render an empty shell.

The block content should clearly but unobtrusively indicate that recommendations are based on the visitor's browsing on Maman Voyage.

Suggested French copy:

```text
Pour vous
Suggestions basées sur les articles consultés pendant votre visite sur Maman Voyage.
```

Suggested English equivalent:

```text
For you
Suggestions based on the articles you've viewed during this visit to Maman Voyage.
```

Suggested German equivalent:

```text
Für Euch
Vorschläge auf Basis der Artikel, die Ihr während dieses Besuchs auf Maman Voyage angesehen habt.
```

Recently viewed labels:

```text
FR: Consultés récemment
EN: Recently viewed
DE: Kürzlich angesehen
```

Use Polylang to select labels based on the current page language.
on the admin side: add a toggle to turn the "for you" section on and off by language.

---

# 2. Important architectural constraint: preserve page caching

The main WordPress page HTML must remain cacheable by Swift Performance / Cloudflare.

Do not render personalized recommendations directly into the cached HTML response.

Instead:

1. Render a small stable placeholder in the cached page.
2. Run a frontend JS controller after page load.
3. Read session browsing history from `localStorage`.
4. If history is sufficient, call a WordPress REST endpoint.
5. Server calculates recommendations.
6. Return structured recommendation data or rendered HTML.
7. JS injects the personalized block into the placeholder.

Prefer the WordPress REST API over `admin-ajax.php`.

Suggested endpoint:

```text
/wp-json/mavo/v1/for-you
```

The recommendation response itself should **not** be publicly cached across users unless cache keys are explicitly personalized. For V0, send `no-cache` / private-style headers for this endpoint.

---

# 3. Eligible pages to track and personalize

V0 should track only meaningful singular content:

```text
post
page
```

Do not track:

```text
homepage
search result pages
tag archives
category archives
author archives
pagination
404
contact/privacy/legal utility pages, where practical
wp-admin
feeds
REST requests
```

If the site already has known hub pages or utility pages, do not attempt hub-specific logic in V0.

Keep filtering simple and extensible.

Provide filters such as:

```php
apply_filters( 'mavo_for_you_track_post', $should_track, $post_id )
apply_filters( 'mavo_for_you_show_block', $should_show, $post_id )
```

so later code can exclude hubs or special pages without changing the plugin core.

---

# 4. Session-only history in localStorage

Use browser `localStorage` for V0.

Do not create a long-term user profile.

Store a compact structure under a clearly namespaced key, for example:

```text
mavo_for_you_v0
```

Recommended schema:

```json
{
  "version": 1,
  "views": [
    {
      "post_id": 29581,
      "lang": "fr",
      "first_seen": 1789030000,
      "last_seen": 1789030120,
      "duration_seconds": 84,
      "max_scroll_pct": 76
    }
  ],
  "searches": [
    {
      "query": "londres ado",
      "source": "site",
      "timestamp": 1789030050
    }
  ],
  "referral": {
    "source": "google",
    "query": null,
    "landing_post_id": 29581,
    "timestamp": 1789030000
  }
}
```

Do not store personally identifying data.

Do not store IPs, emails, login identity, cookies from other services, or cross-site identifiers.

For V0, keep only a bounded history.

Recommended maximums:

```text
views: last 10–20 meaningful content views
searches: last 5 search events
```

Recommended expiry:

```text
24 hours or current browsing session equivalent
```

Because this is stored in `localStorage`, implement expiry manually using timestamps.

A good V0 default is **24 hours** from the last interaction. If older, reset the stored profile.

Make expiry duration filterable.

---

# 5. Reading duration tracking

Track meaningful reading duration for each qualifying page.

Do not simply count wall-clock time while the tab is hidden.

Use the Page Visibility API.

Count time only when:

```text
document.visibilityState === 'visible'
```

Recommended behaviour:

- Start timer once page JS initializes.
- Pause when tab becomes hidden.
- Resume when visible again.
- Save periodically and on `pagehide`.
- Cap duration to avoid pathological values from abandoned tabs.

Recommended cap per view:

```text
10 minutes (600 seconds)
```

Make this configurable/filterable.

Repeated visits to the same post during the same 24h profile should update the same history item rather than creating unlimited duplicates.

Suggested behaviour:

```text
last_seen = latest timestamp
duration_seconds = max(existing, current-session-reading-duration)
max_scroll_pct = max(existing, current max scroll)
```

Do not sum endless repeated tab revisits in a way that creates artificially huge weights.

---

# 6. Scroll-depth tracking

Track maximum scroll percentage for each qualifying content page.

Use a lightweight scroll listener, throttled or passive.

Calculate against meaningful document/page height.

Store the maximum observed percentage in buckets or integer percentage.

Recommended buckets:

```text
0–24
25–49
50–74
75–89
90–100
```

Storing the exact integer percentage is also acceptable, but scoring should use buckets rather than overfit to tiny differences.

Do not trigger excessive localStorage writes on every scroll event.

Update in memory and persist at sensible intervals / pagehide.

---

# 7. What counts as a meaningful viewed page

A page should enter the recommendation profile only after some minimal engagement.

Recommended V0 qualification rule:

```text
qualified if either:
- at least 8 visible seconds spent on page
OR
- at least 25% scroll depth reached
```

This avoids counting accidental bounces as strong preference signals.

However, the current page may still be sent to the recommendation endpoint while being read, even before qualification, with a lower weight if desired.

Keep this logic simple and document it clearly.

Make thresholds filterable.

---

# 8. Search-term tracking

## 8.1 On-site search

Capture the user's own searches on Maman Voyage as a strong session-interest signal.

If WordPress search uses standard `?s=...`, capture the search term from search result page URLs.

Store:

```json
{
  "query": "londres ado",
  "source": "site",
  "timestamp": 1789030050
}
```

Normalize whitespace.

Do not store empty or extremely long queries.

Recommended max length:

```text
200 characters
```

Do not attempt semantic NLP client-side in V0.

The server may later map search words to known filter vocabulary.

## 8.2 Google / external search referral context

Capture referrer information when available.

Store at least:

```text
source domain / source type
landing post ID
entry timestamp
```

If an actual search query is available in the referrer URL, store it.

Do **not** assume Google reliably exposes organic search queries. In many cases only `google.*` will be visible and query will be unavailable.

Example:

```json
{
  "source": "google",
  "query": null,
  "landing_post_id": 29581,
  "timestamp": 1789030000
}
```

External referral information should have a lower weight than explicit on-site search unless a real query string is available.

---

# 9. Existing tvf scores are the primary recommendation signal

Maman Voyage already stores many post-level scores for the tvf/travel-finder plugin.

Each filter value is conceptually:

```text
0 = not applicable / no
1 = yes
2 = very much so / strong match
```

The exact storage mechanism may be post meta, custom fields, taxonomies, or plugin-managed data. Before implementing scoring, inspect the existing site code/data and determine how these filter scores are stored and retrieved.

Do not invent a parallel duplicate data model.

Create one internal accessor abstraction, for example:

```php
mavo_for_you_get_filter_scores( $post_id )
```

Return a normalized array:

```php
[
    'city_trip' => 2,
    'hiking'    => 0,
    'teenagers' => 2,
    'food'      => 1,
]
```

All scoring code must depend on this accessor rather than directly on raw post meta names throughout the plugin.

This allows the site-specific integration to change later.

---

# 10. Candidate eligibility in V0

A recommendation candidate must satisfy all of the following:

```text
- post_type is post or page
- post_status is publish
- same Polylang language as current page
- not current page
- not already viewed during the active local profile
- has at least one “où partir” filter scored 2
```

Do not recommend drafts, private posts, future posts, attachments, archives, search pages, etc.

If Polylang is active, same-language matching is mandatory.

If Polylang is unavailable unexpectedly, fail safely rather than mixing languages.

---

# 11. Recommendation scoring — V0

Keep the scoring transparent and deterministic.

Do not use AI, embeddings, external APIs, vector databases, or machine learning in V0.

## 11.1 Build a weighted session-interest profile

For each recent viewed post, retrieve its “où partir” filter scores.

The strongest signal should be filters scored `2`.

Suggested viewed-post recency weights:

```text
current page: 1.00
previous viewed page: 0.80
2nd previous: 0.60
3rd previous: 0.45
older: 0.30
```

Tune later; implement as constants/filters.

## 11.2 Engagement multiplier

Use reading duration and scroll depth to modify the contribution of each viewed post.

Keep it simple and bounded.

Example duration multiplier:

```text
< 8 sec       → 0.25
8–29 sec      → 0.60
30–89 sec     → 1.00
90–179 sec    → 1.15
180+ sec      → 1.25
```

Example scroll multiplier:

```text
< 25%         → 0.50
25–49%        → 0.75
50–74%        → 1.00
75–89%        → 1.15
90%+          → 1.25
```

Combine conservatively, for example:

```text
engagement_multiplier = average(duration_multiplier, scroll_multiplier)
```

Do not multiply both independently if that causes extreme weights.

Cap final engagement multiplier to a sensible range such as:

```text
0.25–1.25
```

## 11.3 Filter overlap score

For each candidate, compare candidate filter scores with weighted session interests.

Suggested base points:

```text
candidate filter=2 AND session signal based on viewed filter=2 → strong score
candidate filter=1 AND session signal based on viewed filter=2 → smaller score
```

Suggested values:

```text
shared strong filter (2 ↔ 2): +8 × viewed-post recency × engagement
candidate=1 where viewed=2: +2 × recency × engagement
```

A filter that is scored 2 in multiple recently viewed posts should naturally accumulate more weight.

Do not overcomplicate the first algorithm.

## 11.4 Search-term influence

On-site search terms should act as a strong additional signal.

V0 implementation options, in preferred order:

1. Map known search tokens/phrases to existing “où partir” filter keys using a small configurable dictionary.
2. Optionally boost candidate title/excerpt/tag matches for literal search terms.

Keep this conservative.

Example mapping:

```text
ado / teenager / teens → teenagers
rando / hike / hiking → hiking
ville / city break → city_trip
plage / beach → beach
```

Provide a filter so the dictionary can be extended later.

Example:

```php
apply_filters( 'mavo_for_you_search_filter_map', $map, $lang )
```

Literal title/tag matching should never dominate the structured filter score.

## 11.5 Referral influence

If an external search query is actually available, treat it similarly to a weaker on-site search query.

If only Google as a source is known but no search query is available, do not invent inferred keywords.

The landing page already contributes its own filter profile.

## 11.6 Candidate ranking

Rank by total score descending.

Return a small number of recommendations.

Recommended V0:

```text
3 items
```

Make this filterable.

If fewer than 2 good candidates remain, showing 1–2 is acceptable.

If there are no meaningful candidates, do not show “For You”.

---

# 12. Minimum relevance threshold

Do not show arbitrary weak recommendations merely to fill slots.

Define a minimum score threshold.

Start with a conservative constant and tune after debug output shows realistic score distributions.

Make it filterable:

```php
apply_filters( 'mavo_for_you_min_score', $min_score, $context )
```

---

# 13. Recommendation diversity

Avoid returning three near-identical results when possible.

V0 can use a light diversity pass after ranking.

Possible rules:

```text
- avoid exact duplicate target IDs
- if several candidates have identical dominant filter profiles, prefer variety among the top 3
```

Do not build complex hub- or taxonomy-based diversity yet.

Keep the initial implementation deterministic and understandable.

---

# 14. Hub awareness — explicit non-goal for V0

Do not attempt to infer or model hub ownership in this version.

Hub relationships are not yet reliably represented in the site.

Future possibilities include:

```text
custom hub taxonomy
hub-* tags
primary/immediate hub post meta
multiple hub membership
```

But V0 recommendations must work **without hubs**.

Do not delay V0 waiting for a hub model.

---

# 15. Long-term interest profile — explicit non-goal for V0

Do not create a long-term visitor-interest profile.

No cross-session recommendation memory beyond the short 24h/session-style local history.

No login-based profile.

No cross-device profile.

No persistent server-side user record.

The architecture should not prevent adding this later, but do not implement it now.

---

# 16. REST endpoint

Register a REST route, for example:

```text
POST /wp-json/mavo/v1/for-you
```

Request payload should be compact.

Suggested schema:

```json
{
  "current_post_id": 29581,
  "lang": "fr",
  "views": [
    {
      "post_id": 29581,
      "duration_seconds": 84,
      "max_scroll_pct": 76,
      "last_seen": 1789030120
    },
    {
      "post_id": 30112,
      "duration_seconds": 46,
      "max_scroll_pct": 63,
      "last_seen": 1789029800
    }
  ],
  "searches": [
    {
      "query": "londres ado",
      "source": "site",
      "timestamp": 1789030050
    }
  ],
  "referral": {
    "source": "google",
    "query": null,
    "landing_post_id": 29581,
    "timestamp": 1789030000
  }
}
```

Server must not trust client-provided language, post IDs, durations, or scroll values blindly.

Validate and clamp everything.

Examples:

```text
post IDs → absint
view count → max 20
duration → clamp 0–600
scroll → clamp 0–100
search strings → sanitize_text_field, max length 200
```

Confirm each post exists and is a valid post/page.

Confirm current page's real Polylang language server-side.

Do not allow arbitrary client data to trigger expensive unbounded database queries.

---

# 17. REST response

Prefer returning structured data rather than arbitrary HTML so presentation remains in the frontend layer.

Suggested response:

```json
{
  "show": true,
  "lang": "fr",
  "recommendations": [
    {
      "post_id": 123,
      "title": "...",
      "url": "...",
      "image": "...",
      "excerpt": "...",
      "score": 31.4
    }
  ],
  "recently_viewed": [
    {
      "post_id": 30112,
      "title": "...",
      "url": "...",
      "image": "..."
    }
  ]
}
```

Do not expose raw detailed scoring in production by default.

In debug mode, optionally include:

```json
"debug": {
  "candidate_scores": [...],
  "profile_filters": {...}
}
```

---

# 18. Debugging / explainability

This recommendation engine must be easy to tune.

Add a debug mode available only to administrators.

Possible implementation:

```text
?mavo_for_you_debug=1
```

and only when:

```php
current_user_can( 'manage_options' )
```

In debug mode, show or log:

```text
viewed post IDs
recency weights
duration multiplier
scroll multiplier
strong filters found
candidate IDs
candidate filter overlaps
final candidate score
reason candidate was excluded
```

Example debug explanation:

```text
Candidate 123 — Kew Gardens
+8 city_trip shared strong filter
+8 family shared strong filter
+6.4 England shared via previous view
+2 search-term mapping
Total: 24.4
```

Do not expose debug data publicly.

---

# 19. Recently viewed section

Keep “Recently viewed” in the same overall module as “For You” in V0.

It should be visually less prominent.

Recommended behaviour:

```text
- show 2–4 recent viewed items
- exclude current page
- same language as current page
- order by most recently viewed first
```

If the visitor has only one previous item, show just one if the overall block is already being rendered.

Do not let Recently Viewed visually compete with personalized recommendations.

Possible structure:

```text
POUR VOUS
[recommendation card] [recommendation card] [recommendation card]

Consultés récemment
• article 1
• article 2
• article 3
```

The recently viewed list can use compact thumbnails or a simple text/link list.

---

# 20. Visual design

Reuse Maman Voyage's existing visual identity and existing tile/card classes where practical.

Known palette:

```text
primary blue: #4e74a5
warm brown: #886353
highlight: #a92d87
soft cream / pale blue backgrounds
subtle shadows
rounded cards
```

Do not invent a completely separate recommendation-widget design language.

The block should feel editorial, not like an ad network.

Suggested hierarchy:

```text
For You heading → clear and prominent
transparency subtitle → small/muted
recommendation cards → existing Mavo tile style if reusable
Recently Viewed → smaller heading and compact presentation
```

Do not overuse badges or icons.

---

# 21. Placeholder and insertion point

The server-rendered cached page should output a placeholder such as:

```html
<div id="mavo-for-you" class="mavo-for-you-placeholder" data-current-post-id="29581"></div>
```

The placeholder should be inserted after the main content but before comments.

Use a robust WordPress hook appropriate to the active theme/site structure.

If no reliable hook exists, append after `the_content` only on eligible singular posts/pages, but ensure placement remains before comments.

Avoid duplicate insertion if content is processed multiple times.

Make output filterable.

---

# 22. Site-wide future “already read” foundation

Do **not** implement the full “already read” UI site-wide in V0.

However, prepare the codebase for it.

The preferred technical direction is to pass post IDs directly in generated internal links/cards when the site controls their markup.

Example:

```html
<a href="https://www.mamanvoyage.com/..." data-mavo-post-id="29581">...</a>
```

For `mavo-for-you` generated recommendation and recent-view links, include:

```text
data-mavo-post-id
```

If easy and non-invasive, expose a helper function that other Mavo plugins/templates can use later:

```php
mavo_for_you_link_data_attr( $post_id )
```

Do not scan and rewrite every historic `<a>` tag in post content in V0.

Future “already read” UI may use the localStorage history to add a subtle class or badge to links/cards with `data-mavo-post-id`.

---

# 23. Internal links and SEO

Recommendation links must be normal crawlable links:

```html
<a href="...">...</a>
```

Do not use:

```text
nofollow
sponsored
target="_blank"
JavaScript-only navigation
```

Personalized recommendations themselves are loaded after page load, so they are not intended to be a primary SEO internal-linking mechanism.

The site's static hubs and editorial internal links remain the SEO backbone.

Treat this feature as primarily UX / discovery / engagement.

---

# 24. Privacy design

V0 should be privacy-conscious by construction.

Principles:

```text
- no login required
- no cross-device identity
- no external recommendation provider
- no user profile stored server-side
- browsing history lives in browser localStorage
- recommendation request contains only recent post IDs + engagement metadata + recent local search/referral context
```

Do not add tracking pixels or third-party analytics calls.

Do not transmit localStorage profile anywhere except the Maman Voyage REST endpoint.

Provide a JS/helper method to reset the local personalization history, even if no public reset UI is exposed in V0.

Example:

```js
window.mavoForYouReset()
```

This can later support a visible “Reset suggestions” control.

---

# 25. Security

REST endpoint must:

```text
- accept only expected fields
- sanitize everything
- clamp numeric values
- limit arrays
- validate post IDs
- enforce same-language candidate selection server-side
- avoid raw SQL built from user input
- use WP_Query / $wpdb->prepare as appropriate
```

Because the endpoint serves anonymous visitors, do not require login nonce for basic read-only recommendation calls.

Instead focus on strict input validation, bounded work, and rate friendliness.

Do not expose private/draft content.

---

# 26. Performance

The plugin must remain lightweight.

Frontend:

```text
- small JS bundle
- no framework dependency
- passive/throttled scroll handling
- minimal localStorage writes
- only one recommendation REST request per page view
```

Server:

```text
- bound candidate query size
- cache reusable post filter-score metadata where reasonable
- avoid scanning all posts naively per request if the site has many posts
```

If the existing “où partir” data allows efficient meta queries, use them.

If not, consider building an in-request candidate pool from posts with known score=2 fields, but do not create a new indexing table in V0 unless performance proves necessary.

Document any potentially expensive query.

---

# 27. Polylang handling

The site is multilingual FR / EN / DE via Polylang.

V0 must never mix recommendation languages.

Server should determine current post language with Polylang, for example:

```php
pll_get_post_language( $current_post_id, 'slug' )
```

Candidate query must restrict to that language.

Recently viewed items shown in the block should also be from the current language.

Do not automatically translate a viewed post ID into another language in V0.

If a visitor switches language, that language can build its own recommendation context from viewed items in the same short-term local history.

---

# 28. Suggested plugin structure

Keep code organized but not overengineered.

Suggested structure:

```text
wp-content/plugins/mavo-for-you/
├── mavo-for-you.php
├── includes/
│   ├── class-mavo-for-you-rest.php
│   ├── class-mavo-for-you-scorer.php
│   ├── class-mavo-for-you-data.php
│   └── class-mavo-for-you-render.php
└── assets/
    ├── css/
    │   └── mavo-for-you.css
    └── js/
        └── mavo-for-you.js
```

A simpler functional structure is acceptable if code remains clean and testable.

Do not introduce Composer or build tooling unless already standard in the project.

---

# 29. Admin settings — keep minimal in V0

Minimal only with activation toggles per language.

Otherwise V0 can use constants and WordPress filters for thresholds.

Optional small Tools/Admin page is acceptable only if useful for debugging, for example:

```text
Mavo For You Debug
- enabled/disabled
- show current scoring diagnostics
```

But a settings page is not required for first delivery.

Prioritize core functionality.

---

# 30. Recommended constants / filters

Centralize defaults for:

```text
history expiry
max history views
max searches
minimum meaningful duration
minimum meaningful scroll
max duration cap
number of recommendations
number of recently viewed items
minimum recommendation score
recency weights
engagement multiplier thresholds
```

Expose WordPress filters where practical.

Avoid magic numbers spread across multiple classes/functions.

---

# 31. Graceful degradation

If JavaScript is disabled:

```text
- page remains fully usable
- no personalized section appears
- no layout gap should remain
```

If REST request fails:

```text
- silently leave placeholder empty
- optionally log to console only in debug mode
```

If Polylang is missing:

```text
- fail safely
- do not mix languages
```

If filter-score integration cannot be resolved:

```text
- do not return generic/random recommendations
- log useful debug information for administrators
```

---

# 32. Logging

Do not log individual visitor histories server-side in production.

For debug mode, temporary structured logs are acceptable, but avoid storing sensitive data.

Do not create a permanent behavioural analytics table in V0.

The plugin is not an analytics system.

---

# 33. QA / testing scenarios

Test at minimum:

## Tracking

```text
first qualifying post view → no block
second meaningful view → recommendation request can occur
accidental 2-second view → should not become a strong profile signal
hidden tab → reading duration should pause
scroll to 80% → max_scroll_pct recorded
revisit same post → update existing view record, do not duplicate endlessly
history older than expiry → reset
```

## Language

```text
FR page → only FR recommendations
EN page → only EN recommendations
DE page → only DE recommendations
```

## Candidate filtering

```text
current post never recommended
already viewed post never recommended
unpublished post never recommended
candidate without any score=2 never recommended
```

## Scoring

Create controlled test posts so that:

```text
post A and B share score=2 filter X
candidate C shares X=2
candidate D only has X=1
candidate C should outrank D
```

Then vary:

```text
duration
scroll depth
recency
```

and verify predictable score changes.

## Search

```text
site search term stored
known term maps to structured filter
candidate with relevant structured filter receives boost
```

## Referral

```text
Google referrer with no query → source stored, no invented keyword
referrer with actual q= parameter → query sanitized and usable
```

## UI

```text
For You visually primary
Recently Viewed visibly subordinate
block before comments
no block if no recommendations
mobile layout works
recommendation links have data-mavo-post-id
```

## Caching

Verify:

```text
cached HTML is identical for different anonymous visitors
placeholder exists in cached page
personal content is injected only after REST response
Cloudflare/Swift page cache remains unaffected
```

---

# 34. Acceptance criteria

V0 is complete when all of the following are true:

```text
- plugin installs and activates cleanly
- frontend history is session/24h localStorage only
- qualifying page views store post ID, language, reading duration, scroll percentage, timestamps
- on-site search terms are stored
- referral source/context is stored when available
- block appears only after sufficient history and viable recommendations
- recommendations are same-language only
- recommendation candidates must have at least one “où partir” filter scored 2
- ranking primarily uses shared “où partir” score=2 filters
- ranking is modified by recency, reading duration, and scroll depth
- current and already-viewed posts are excluded
- personalized content is REST-loaded after page load
- full-page caching remains intact
- For You and Recently Viewed appear together
- Recently Viewed is visually less prominent
- recommendation/recent links include data-mavo-post-id
- no long-term profile
- no hub logic
- no AI/embedding/external service
- graceful degradation works
- administrator debug mode can explain recommendation scores
```

---

# 35. Explicit non-goals for V0

Do not implement:

```text
long-term interest profiles
cross-device personalization
logged-in user profiles
hub detection or hub scoring
automatic primary-hub relationships
full site-wide “already read” link rewriting
AI recommendations
embeddings
external recommendation APIs
behavioural analytics database
complex admin settings UI
A/B testing framework
revenue/affiliate weighting
```

These may be considered later.

---

# 36. Implementation philosophy

The central principle is:

```text
Use existing structured editorial data + lightweight session behaviour to improve content discovery.
```

Do not turn this into a generic recommendation engine.

Maman Voyage already contains rich editorial structure through the “où partir” filter scores. V0 should exploit that first.

Keep the scoring explainable and tunable.

Keep visitor data local and short-lived.

Keep the cached page static.

Build the smallest architecture that can later support:

```text
hub awareness
long-term preferences
already-read badges
personalized hub sections
more sophisticated scoring
```

without implementing those features now.

