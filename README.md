# Mavo For You — V0

A session-only, privacy-conscious personalization layer for Maman Voyage. It adds a
**Pour vous / For you / Für Euch** block after the content of eligible posts and pages,
built from what the visitor has been reading during this visit — and nothing else.

## How it works

The constraint that shapes everything: **the page HTML must stay cacheable**. So the
cached page contains only a placeholder, and the personalized part arrives afterwards.

```
cached HTML          <div id="mavo-for-you" data-current-post-id="29581"></div>
       ↓
assets/js            reads localStorage, tracks reading time + scroll depth
       ↓
POST /wp-json/mavo/v1/for-you   { current_post_id, views[], searches[], referral }
       ↓
MFY_Rest             validates and clamps every field, re-derives the language
       ↓
MFY_Scorer           builds a session interest profile, ranks candidates
       ↓
JSON response        no-store, private — never publicly cached
       ↓
assets/js            injects the block into the placeholder
```

Nothing personal is stored server-side. There is no profile, no cookie, no analytics
table, no third party. The visitor's history lives in their browser under
`mavo_for_you_v0` and expires 24 hours after the last interaction.

## The signal

Recommendations come from the Travel Finder ("où partir") filter scores the site
already maintains — `wp_tvf_post_filter`, read exclusively through
`MFY_Data` / `mavo_for_you_get_filter_scores()`. No parallel data model.

Only three of the six tvf categories carry the signal: **intérêt**, **géographie**
and **âge des enfants**. Saison / durée / budget describe trip logistics, are scored
broadly across the catalogue, and would flatten the ranking rather than sharpen it.
Change the set with the `mavo_for_you_signal_categories` filter.

### Scoring

Each recently viewed post contributes the filters it scores **2** in, weighted by:

| factor | range | source |
| --- | --- | --- |
| recency | 1.00 (current page) → 0.30 (older) | position in the view list |
| engagement | 0.25 → 1.25 | average of the duration and scroll multipliers |

A candidate then scores `+8 × weight` per shared strong filter (2 ↔ 2), `+2 × weight`
where it is merely a 1, plus a small boost for filters mapped from on-site search
terms. Candidates below `mavo_for_you_min_score` (7.5) are dropped, and a light
diversity pass defers a candidate whose strong-filter profile exactly matches one
already picked.

The block appears only when there are **at least 2 meaningful views** (≥8 visible
seconds or ≥25% scroll) *and* at least one candidate clears the threshold. There is
no empty shell.

## Files

```
mavo-for-you.php                     bootstrap + mavo_for_you_link_data_attr()
includes/mavo-for-you-config.php     every threshold, weight and label
includes/class-mavo-for-you-data.php the only code that touches tvf data
includes/class-mavo-for-you-scorer.php   ranking
includes/class-mavo-for-you-rest.php     endpoint + input validation
includes/class-mavo-for-you-render.php   placeholder, assets, eligibility
includes/class-mavo-for-you-admin.php    Settings → Mavo For You
assets/js/mavo-for-you.js            tracking + request + rendering
assets/css/mavo-for-you.css          section styling (cards reuse .mv-tile)
tests/                               plain-PHP suites, no tooling required
```

## Settings

**Settings → Mavo For You** — one setting: the languages the block is active in.
Everything else is a constant or a filter, on purpose. The page also reports whether
the Travel Finder data and Polylang are reachable.

## Debugging

Append `?mavo_for_you_debug=1` to any post URL while logged in as an administrator.
The response then carries a `debug` object, printed to the browser console and shown
as a collapsed block under the recommendations:

```
C — Kew Gardens
  +15.60 City trip (shared strong filter, session weight 1.95)
  +15.60 Angleterre (shared strong filter, session weight 1.95)
  Total: 31.20
```

It lists viewed post IDs, recency weights, duration and scroll multipliers, the strong
filters found, every candidate with its score and reasons, and why candidates were
excluded. Debug output is never sent to non-administrators, and a debug page view is
explicitly marked uncacheable because it carries a REST nonce.

`window.mavoForYouReset()` clears the local history — the hook a future
"reset suggestions" control would use.

## Filters

Thresholds: `mavo_for_you_history_ttl`, `mavo_for_you_max_views`,
`mavo_for_you_max_searches`, `mavo_for_you_max_search_length`,
`mavo_for_you_min_duration`, `mavo_for_you_min_scroll`, `mavo_for_you_max_duration`,
`mavo_for_you_min_meaningful_views`, `mavo_for_you_num_recommendations`,
`mavo_for_you_num_recently_viewed`, `mavo_for_you_min_score`.

Scoring: `mavo_for_you_recency_weights`, `mavo_for_you_duration_multipliers`,
`mavo_for_you_scroll_multipliers`, `mavo_for_you_engagement_bounds`,
`mavo_for_you_points_strong_match`, `mavo_for_you_points_weak_match`,
`mavo_for_you_points_search_strong`, `mavo_for_you_points_search_weak`,
`mavo_for_you_referral_search_factor`, `mavo_for_you_candidate_pool_size`.

Behaviour: `mavo_for_you_track_post`, `mavo_for_you_show_block`,
`mavo_for_you_signal_categories`, `mavo_for_you_trackable_post_types`,
`mavo_for_you_candidate_post_types`, `mavo_for_you_search_filter_map`,
`mavo_for_you_labels`, `mavo_for_you_placeholder_html`,
`mavo_for_you_use_theme_hook`.

## Tests

```
php tests/test-scoring.php   # ranking, exclusions, engagement, search, diversity
php tests/test-rest.php      # validation, clamping, gates, cache headers
```

They stub WordPress and the tvf store, so they run anywhere PHP does — no WordPress,
no database, no Composer.

## Manual QA

- First qualifying view → no block. Second → block may appear.
- A 2-second view must not become a strong signal (it is not a meaningful view).
- Hide the tab: reading time must stop accumulating.
- Revisit a post: its existing history entry updates, no duplicate is appended.
- Age the profile past 24h (edit `updated` in localStorage): it resets.
- FR / EN / DE pages must each recommend only their own language.
- With JS off: page fully usable, no gap, no block.
- Compare the cached HTML of two anonymous visitors: byte-identical, placeholder empty.
- Recommendation and recently-viewed links carry `data-mavo-post-id`.

## Deliberately not in V0

Hub awareness, long-term or cross-device profiles, site-wide "already read" link
rewriting, AI/embeddings/external services, a behavioural analytics table, A/B
testing, affiliate weighting. The architecture leaves room for them; the code does
not anticipate them.
