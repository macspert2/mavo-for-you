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
MFY_Scorer           profiles interests + geography, ranks and composes
       ↓
JSON response        no-store, private — never publicly cached
       ↓
assets/js            injects the block into the placeholder
```

Nothing personal is stored server-side. There is no profile, no cookie, no analytics
table, no third party. The visitor's history lives in their browser under
`mavo_for_you_v0` and expires 24 hours after the last interaction.

## The signals

Recommendations come from two sources the site already maintains: the Travel Finder
("où partir") filter scores (`wp_tvf_post_filter`, read exclusively through `MFY_Data` /
`mavo_for_you_get_filter_scores()`) and Geo Tagger's place tree
(`wp_geo_tagger_places`, read exclusively through `MFY_Geo`). No parallel data
model, and either source can be missing without breaking the other.

Only three of the six tvf categories carry the signal: **intérêt**, **géographie**
and **âge des enfants**. Saison / durée / budget describe trip logistics, are scored
broadly across the catalogue, and would flatten the ranking rather than sharpen it.
Change the set with the `mavo_for_you_signal_categories` filter.

### Geography

Filter scores say what *kind* of trip an article is about. They do not say *where* —
tvf's `angleterre / france / mediterranee` is country-ish at best. So three London
articles used to recommend Barcelona, which shared `citytrip 2 + ados 2 +
culture_histoire 2` and therefore outscored the next London article.

Geo Tagger fills that in. `{prefix}geo_tagger_places` is a parent tree
(continent → country → region → city) whose rows carry a `post_tag` term per
language, and every level of a post's chain is attached to it as a tag — so one join
resolves the geography of any number of posts at once. `MFY_Geo` is the only code
that touches it, exactly as `MFY_Data` is the only code that touches tvf.

### Editorial hubs

`mavo-hub-manager` records, by hand, that a page *owns* a piece of content:
`_mavo_primary_geo_hub` and `_mavo_primary_theme_hub` on the child, read here only
through its helper API (`MFY_Hubs`, never raw meta — and always validated, since the
stored ID may be stale).

A hub is not another similarity signal, so it is not scored against candidates. It is
**placed first**, ahead of the ranking: someone three articles into London has been
reading the children of a London hub, and the page that gathers everything else is the
single most useful link the block can offer. One slot, `mavo_for_you_max_hub_recommendations`.

Each viewed post gives its weight to its immediate hub and half of it
(`mavo_for_you_hub_ancestor_decay`) to that hub's parent, two hops up at most — three
Paris articles signal Paris loudly and France faintly. Both hierarchies are walked
independently; on a tie the geographic hub wins, being the more concrete offer.

Hub candidates deliberately bypass §10's "at least one filter scored 2": a hub page
often has no tvf scores at all, and being the declared owner of what the visitor is
reading *is* the editorial signal. They may be pages as well as posts. Everything else
still applies — published, correct hub type, same language.

An already-read hub is never recommended back; see *Recently viewed* below.

### Hub children

The hubs a session is reading in are also a source of *candidates*: the other articles
an editor placed in the same hub. Someone on a London guide, or two articles into it,
has not seen its other children — and hand-grouping is a better guarantee of relevance
than any score this plugin can compute.

This is why the hub context and the hub *suggestions* are two different lists. A hub
already read drops out of the suggestions, but stays in the context: the visitor is
standing in it, and its children are exactly what they have not seen. A viewed post
that is itself a hub joins the context at full weight.

Each child earns `mavo_for_you_hub_child_points` × the hub's session weight, counted
once, for the hub that best explains it. At 10, a child of the hub on the current page
clears the minimum score on that relationship alone; filter and geography overlap then
add to it. Children are still ordinary articles, so **§10 eligibility applies to them**
— only the hub pages themselves bypass it. Bounded twice: the strongest
`mavo_for_you_hub_child_hubs` (3) hubs are expanded, each by a capped query
(`mavo_for_you_hub_child_pool_size`, 20).

`mavo_get_hub_children()` defaults to `post_status => 'any'`, so `publish` is passed
explicitly — a draft child must never reach a reader.

### Scoring

Each recently viewed post contributes the filters it scores **2** in *and* its place
at each level, both weighted by:

| factor | range | source |
| --- | --- | --- |
| recency | 1.00 (current page) → 0.30 (older) | position in the view list |
| engagement | 0.25 → 1.25 | average of the duration and scroll multipliers |

A candidate scores `+8 × weight` per shared strong filter (2 ↔ 2), `+2 × weight` where
it is merely a 1, a small boost for filters mapped from on-site search terms, and a
geo affinity bonus — `city 12 / region 6 / country 3`, times that place's session
weight, **deepest matching level only** (a London article is also in England;
awarding both would count one fact twice). Candidates below
`mavo_for_you_min_score` (7.5) are dropped.

### Composition

The bonus alone would leave "do I get more London?" a matter of tuning. The slot
quota makes it a guarantee.

A level is **focused** when one place holds ≥60% of the weight of the views that have
a place at that level (`mavo_for_you_geo_focus_threshold`). Posts with no geography
stay out of that denominator — not knowing where an article is isn't evidence of
wandering. When the session is focused, **2 of 3 slots** are reserved for that place
(`mavo_for_you_geo_reserved_ratio`) and filled by score; the remaining slot goes
deliberately elsewhere, so the block still offers a way out.

Reservation walks the focus chain outwards and settles on the most specific level
that can actually fill the quota:

Hubs are placed before this happens, and geography divides what is left — so with a
hub placed, three slots become: hub + 1 focused place + 1 elsewhere.

| session | reserved | result |
| --- | --- | --- |
| 3 × London, 3+ unread London | city: Londres | 2 London + 1 elsewhere |
| 3 × London, 1 unread London | country: Angleterre | London + Edinburgh + 1 elsewhere |
| 2 × London, 1 × Barcelona | city: Londres (67%) | 2 London + 1 elsewhere |
| London + Barcelona + Rome | none | pure filter ranking, as before |

The candidate pool is the union of three bounded queries — by shared strong filters,
by session geography, and by hub membership — so the next London article can surface
even if it shares no strongly scored filter with anything read so far. Editorial
eligibility (§10: at least one filter scored 2) applies to all three.

A light diversity pass defers a candidate whose strong-filter profile exactly matches
one already picked. It applies to the discovery slot only: reserved picks are
*supposed* to look alike.

The block appears only when there are **at least 2 meaningful views** (≥8 visible
seconds or ≥25% scroll) *and* at least one candidate clears the threshold. There is
no empty shell.

## Files

```
mavo-for-you.php                     bootstrap + mavo_for_you_link_data_attr()
includes/mavo-for-you-config.php     every threshold, weight and label
includes/class-mavo-for-you-data.php the only code that touches tvf data
includes/class-mavo-for-you-geo.php  the only code that touches geo data
includes/class-mavo-for-you-hubs.php the only code that touches hub data
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

`window.mavoForYouReset()` clears the local history from the console; the block's
own reset control (below) does the same thing.

## Recently viewed, and hubs in it

Strict recency, most recent first, current page excluded — with one deliberate
exception. A hub read during this visit is guaranteed the last slot even when newer
articles would have pushed it off the list. It is never re-recommended (it isn't news),
but it is the page a reader most often wants to get back to, and losing it off the
bottom of a three-item list makes that harder than it needs to be.

Hub entries are marked with the same label the cards use.

## Naming, and why there is no badge

The reader never sees the word "hub" — that is the internal name for the relationship.
The label is **Guide** (fr/en) and **Übersicht** (de), per hub type, via
`mavo_for_you_hub_labels`. French editorial usage would call a place page a *guide* and
a topic page a *dossier*; German travel writing has adopted "Guide" as a loanword, but
*Übersicht* is the plainer word. Both types default to the same label so the block
doesn't teach a vocabulary — split geo and theme in the filter if that becomes useful.

It is rendered as `.mv-tile__eyebrow`, the theme's existing small label above a title,
**not as a badge**. The site already has a badge vocabulary (`mv-badges`: France,
Plage, Ados…) and those badges describe *content*. A hub marker is editorial context,
not another content facet, and a second badge language on the same tiles would read as
an ad unit — which §20 explicitly warns against. The eyebrow says it in the theme's own
voice, and the card gets one hairline of warm-brown emphasis, nothing more.

## Clearing the history

The block ends with a quiet text button — *Effacer mon historique / Clear my history /
Verlauf löschen* — right-aligned, small, muted. No confirmation dialog: there is
nothing to lose but a few post IDs in this browser, and a modal would block the page.
The block is replaced by a one-line acknowledgement.

Resetting has to do more than drop the storage key. The tracker holds the whole
profile in memory, and its save triggers — the periodic save, hiding the tab, leaving
the page — would each write it straight back. So a reset stops the tracker first, and
tracking does not resume for the rest of that page view: a reset the visitor has to
ask for twice is not a reset. The next page load starts a fresh, empty profile.
`tests/test-frontend.js` covers all three resurrection paths.

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

Hubs: `mavo_for_you_max_hub_recommendations`, `mavo_for_you_hub_max_depth`,
`mavo_for_you_hub_ancestor_decay`, `mavo_for_you_hub_points`,
`mavo_for_you_hub_child_points`, `mavo_for_you_hub_child_hubs`,
`mavo_for_you_hub_child_pool_size`, `mavo_for_you_hub_labels`.

Geography: `mavo_for_you_geo_levels`, `mavo_for_you_geo_points`,
`mavo_for_you_geo_focus_threshold`, `mavo_for_you_geo_reserved_ratio`,
`mavo_for_you_geo_reserved_slots`, `mavo_for_you_geo_pool_size`.

Behaviour: `mavo_for_you_track_post`, `mavo_for_you_show_block`,
`mavo_for_you_signal_categories`, `mavo_for_you_trackable_post_types`,
`mavo_for_you_candidate_post_types`, `mavo_for_you_search_filter_map`,
`mavo_for_you_labels`, `mavo_for_you_placeholder_html`,
`mavo_for_you_use_theme_hook`.

## Tests

```
php tests/test-scoring.php   # ranking, exclusions, engagement, search, diversity
php tests/test-rest.php      # validation, clamping, gates, cache headers
php tests/test-geo.php       # focus detection, slot quota, cascade, fallback
php tests/test-hubs.php      # hub placement, ancestor walk, validation, recent list
php tests/test-hub-children.php  # sibling candidates, eligibility, bounded pools
php tests/test-no-hubs.php       # every hub feature off when Hub Manager is absent
node tests/test-frontend.js  # tracking, qualification gate, storage, reset
```

The PHP suites stub WordPress, the tvf store and the place table; the frontend suite
stubs just enough of a browser (DOM, storage, timers, clock) to drive the controller.
Everything runs anywhere PHP and Node do — no WordPress, no database, no Composer, no
npm.

## Manual QA

- First qualifying view → no block. Second → block may appear.
- A 2-second view must not become a strong signal (it is not a meaningful view).
- Hide the tab: reading time must stop accumulating.
- Revisit a post: its existing history entry updates, no duplicate is appended.
- Age the profile past 24h (edit `updated` in localStorage): it resets.
- FR / EN / DE pages must each recommend only their own language.
- Read three articles about one city: two suggestions should be that city, one not.
  `?mavo_for_you_debug=1` prints the focus decision and the share behind it.
- Read three articles in three different countries: no reservation, filter ranking.
- Read three children of a hub: the hub should be the first card, labelled "Guide".
- Read the hub itself, then two of its children: the hub should appear in
  "Consultés récemment" (marked), never as a suggestion — and its other children
  should be suggested.
- With JS off: page fully usable, no gap, no block.
- Compare the cached HTML of two anonymous visitors: byte-identical, placeholder empty.
- Recommendation and recently-viewed links carry `data-mavo-post-id`.
- Click "Clear my history", then reload: the block should be gone until two new
  meaningful views accumulate.

## Deliberately not in V0

Long-term or cross-device profiles, site-wide "already read" link
rewriting, AI/embeddings/external services, a behavioural analytics table, A/B
testing, affiliate weighting. The architecture leaves room for them; the code does
not anticipate them.
