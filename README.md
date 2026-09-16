# Mavo For You — V0

A session-only, privacy-conscious personalization layer for Maman Voyage. It adds a
**Pour vous / For you / Für Euch** block after the content of eligible posts and pages,
built from what the visitor has been reading during this visit — and nothing else.

Once a visit is substantial enough, that block also links on to **/pour-vous/**, a
standalone page where the same session is laid out as a row per reason rather than
merged into three cards. See [The /pour-vous/ page](#the-pour-vous-page).

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

Cached server-side: the impersonal block, and the shared candidate pools behind it — all
keyed by a generation counter that any post save or hub change bumps. Personalised responses are
cached nowhere. Nothing personal is stored server-side. There is no profile, no cookie, no analytics
table, no third party. The visitor's history lives in their browser under
`mavo_for_you_v0` and expires 24 hours after the last interaction.

## Two blocks, one block

The plugin renders one recommendation block, which exists in two states.

**Impersonal** — `[geo_related]`, rendered server-side into the cached page.
"What should someone reading *this page* read next?", with no visitor in the question.
This is the block that used to live in `mavo-geotag-plus`; it moved here because its
scoring is this plugin's scoring, and because that plugin had already grown a
dependency on these hub labels to keep the two blocks' wording in step.

**Personalised** — "Pour vous", injected by the frontend controller once the visitor
has read enough. On a page carrying the shortcode it replaces the impersonal block in
place; on any other eligible page it appears after the content, before comments.

They are the same code. An impersonal ranking is `MFY_Scorer::rank_for_post()`, which
is `rank()` with a session of exactly one view — the current post, at neutral
engagement, weighing exactly 1. Hub placement, the geography quota, sibling scoring and
the diversity pass all follow from that, so the two states cannot drift apart.

```
cached page   [geo_related] → placeholder + impersonal block   (Swift/Cloudflare cache this)
      ↓
   JS reads the placeholder's data-limit / data-level
      ↓
   POST /wp-json/mavo/v1/for-you  → enough history?
      ↓                                    ↓
     yes: replace in place              no: the impersonal block simply stays
```

The shortcode's `limit` and `level` are carried into the personalised request, so the
section does not resize or re-aim under the reader. `post_id` and `style` are accepted and
ignored, and `geo_tagger_related_posts()` survives as a shim.

A post carrying the shortcode never also gets the after-content block — the shortcode's
position wins, and the hook stands down. If the impersonal ranking finds nothing, the
placeholder is still emitted (empty, and hidden by CSS), so a visitor who later earns a
personalised block still has somewhere to put it.

## The /pour-vous/ page

A page of its own, created by hand in WordPress with one shortcode in it:

```
[mavo_for_you_page]
```

French only for now (`mavo_for_you_page_langs`), at the slug `pour-vous`
(`mavo_for_you_page_slugs`). The plugin finds the page by that slug, so nothing has to
be registered anywhere; `mavo_for_you_page_id` overrides the lookup if a site needs it.

The block answers a hard question — of everything on the site, which *three* articles
does this reader most want next? — by merging every signal into one number. That merge
is what makes three cards possible, and it is also what throws information away. The
page takes the other option: each signal becomes a heading of its own, filled with what
that signal alone would pick.

```
POUR VOUS                                                    (block, 3 cards)
      ↓  "Voir toutes vos suggestions"
/pour-vous/
   Parce que vous avez cherché « londres ado »   ◀ ▶   ■ ■ ■ ■ ■ ■
   Dans « Londres en famille »                   ◀ ▶   ■ ■ ■ ■ ■
   Destination Londres                           ◀ ▶   ■ ■ ■ ■ ■ ■ ■
   Plus d'articles « Séjours en ville »          ◀ ▶   ■ ■ ■ ■ ■ ■ ■ ■
   Consultés récemment                                 ■ ■
```

Row kinds, in the order they appear (`MFY_Rows::KIND_ORDER`):

| kind | heading | filled with |
|---|---|---|
| `search` | *Parce que vous avez cherché « … »* | what the query maps to in the filter dictionary |
| `hub` | *Dans « … »* | the other children of a hub the session is reading in |
| `geo` | *Destination …* | articles tagged with a place the session keeps returning to |
| `filter` | *Plus d'articles « … »* | articles scoring 2 on a filter the session scores 2 on |
| `recent` | *Consultés récemment* | the visitor's own history, always last |

Search leads because a query is the visitor stating in their own words what they came
for, and nothing the plugin infers outranks that. Hubs come next — an editor's statement
about what belongs together — then geography, then themes, which is the most abstract of
the four.

Three rules shape what a reader actually sees:

- **Four tiles or no row.** A strip of three does not rotate, and a heading over three
  articles promises a category the site cannot fill. `mavo_for_you_page_row_min`.
  "Consultés récemment" is the one exception: it promises no category, so two is fine.
- **Repetition between rows is the point.** An article can be both *in the London guide*
  and *a city break with teenagers*; hiding one of those to avoid repeating a thumbnail
  would make the headings lie. Within a row, of course, once. A row whose every tile
  already appeared in one earlier row is dropped — two headings over one set of articles
  reads as a bug however true both are.
- **Already-read articles stay.** The block is about what is next; the page is about the
  territory. They carry the same `data-mavo-post-id` as every other tile, so the
  existing read-marking pass dims them with no extra code.

**The link.** The block offers it only when the session can actually fill
`mavo_for_you_page_link_min_rows` rows (3) of four tiles or more — measured, not
guessed: `MFY_Rows::count_available()` assembles the rows for real and stops before
hydrating them into post objects, which is why that check costs pool queries and not a
hundred `get_post()` calls.

**A cold visit** — a shared link, a crawler, a first-time reader — gets rows built from
the catalogue instead: the most-read articles under a few broad filters. The page says
so in its own copy rather than claiming to be personalized, and because there is no
visitor in it, it is the one part of this feature that is cached.

**Caching.** Exactly the block's arrangement, for exactly the block's reason. The
shortcode emits a placeholder and one line of loading copy — no post ID beyond the
page's own, nothing that could differ between two visitors — so Swift and Cloudflare
cache the page like any article. Rows arrive afterwards from
`POST /wp-json/mavo/v1/for-you-page`, `no-store` and `private`. With JS off, a
`<noscript>` rule hides the loading line and shows the explanatory one instead.

The strip is a plain `overflow-x` container with scroll snapping: touch, trackpad and
keyboard all drive it natively, and the arrows only call `scrollTo()` on it. They wrap
rather than stop — next at the end returns to the start — which is endless in the sense
that matters, without a cloned DOM whose copies would have to be kept out of the tab
order and out of the read-marking pass. A strip that fits hides its arrows entirely.

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
includes/class-mavo-for-you-rows.php     /pour-vous/ rows: one per signal
includes/class-mavo-for-you-rest.php     endpoints + input validation
includes/class-mavo-for-you-render.php   placeholder, assets, eligibility
includes/class-mavo-for-you-shortcode.php  [geo_related], the impersonal block
includes/class-mavo-for-you-page.php     [mavo_for_you_page], /pour-vous/
includes/class-mavo-for-you-cache.php      generation-busted transients
includes/class-mavo-for-you-admin.php    Settings → Mavo For You
includes/class-mavo-for-you-tuner.php    Tools → Mavo For You scoring
assets/js/mavo-for-you.js            tracking + request + rendering
assets/js/mavo-for-you-page.js       /pour-vous/: rows and their strips
assets/css/mavo-for-you.css          section styling (cards reuse .mv-tile)
assets/css/mavo-for-you-page.css     row + strip styling (tiles reuse .mv-tile)
tests/                               plain-PHP suites, no tooling required
```

## What is not tracked

Beyond the obvious (home, search, archives, 404, feeds, admin, unpublished), contact,
privacy and legal **pages** are excluded — §3's "utility pages, where practical".
WordPress knows its own privacy page (and its Polylang translations); everything else
is a slug list, `mavo_for_you_excluded_page_slugs`, matched against pages only and
tolerant of `-en` / `-2` suffixes. An "about" page is editorial and stays tracked.

They contribute nothing to a profile anyway — no filter scores, no geography, no hub —
but left in they would still count toward the two-meaningful-views threshold and could
surface as *"Consultés récemment : Contact"*.

The rule lives in `MFY_Data::should_track_post()` and is applied in both places that
can decide it: the frontend, which then doesn't track, and the endpoint, which re-drops
any such view a client sends anyway — a profile recorded before this rule existed must
not smuggle a contact page back in. `mavo_for_you_track_post` has the last word in both.

## Settings

**Settings → Mavo For You** — one setting: the languages the block is active in.
Everything else is a constant or a filter, on purpose. The page also reports whether
the Travel Finder data and Polylang are reachable.

## The shared candidate pool

Every article view fires one uncached REST request, and the expensive part of it — the
candidate pools — is **identical for any two visitors with the same interests**. The only
personal element is the exclusion list: what that particular reader has already seen.

So the exclusions come out of the SQL and are applied in PHP afterwards. What remains is
shareable, and is cached:

| pool | key | what it holds |
|---|---|---|
| filter | lang + sorted slugs + limit | post IDs, match counts, view counts |
| geography | lang + sorted place IDs + limit | post IDs |
| hub children | hub ID + type + limit | post IDs |

Nothing personal is stored: the same rows are served to everyone, and the cached values
are post IDs and public counters. Personalised *responses* are still cached nowhere.

Because the trimming happens in PHP, each pool over-fetches by `mavo_for_you_pool_overfetch`
(the history cap plus five) so it survives a full history being removed from it.

Correctness does not rest on the TTL: any post save bumps the cache generation and orphans
every key at once (`MFY_Cache`). Autosaves and revisions are excluded from that — they fire
`save_post` once a minute while an editor has a post open, and busting on them would keep
the cache permanently cold for no benefit.

## Tuning the threshold

**Tools → Mavo For You scoring.** `min_score` has never been set from data — it was a
conservative guess made before geography, hubs and the impersonal block existed, and all
three changed what the numbers mean. A hub child clears it on one relationship alone, and
`[geo_related]` scores off a single synthetic view, so its totals run at roughly a third
of a three-article session's against the same bar.

`MFY_Scorer::rank()` is a pure function of its inputs, so the page replays synthetic
sessions against real posts with no browsing at all. For each sampled post it scores four
shapes — impersonal, focused (two more in the same place), thematic (two more sharing its
strong filters), scattered (two at random) — and reports:

- **the threshold sweep** — for each candidate `min_score`, the share of sessions that
  would still fill a block, show a thin one, or show nothing. That is the question a
  threshold actually poses: not "is 7.5 right" but "what does 7.5 cost me".
- **where the scores sit** — p10 / median / p90 of the top and third score, per shape. If
  the impersonal row sits far below the others, one absolute threshold is doing two jobs.
- **third ÷ top** — the alternative. A *relative* gate is scale-free and survives future
  scoring changes, where an absolute one needs re-tuning after each.
- **raw CSV** — for the part no distribution can answer: judging candidates by hand.

Hub picks are excluded from the sweep because they bypass the threshold entirely; a
session counted under "nothing" may still show its hub.

It reads only, runs on demand, and stops at a 45-second budget. `tests/test-tuner.php`
covers the arithmetic, which is the part that would quietly mislead a decision.

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

## Already read

Links to pages opened during this visit are marked, in two ways, because one treatment
cannot serve both contexts:

| Context | Treatment | Layout cost |
|---|---|---|
| Tiles (`.mv-tile`) | image dimmed, title muted, a check in the corner | none — the tile is positioned, the check is absolute |
| Prose (`.entry-content`) | colour change only | none |
| Nav, footer, "Consultés récemment" | nothing | — |

Prose links get **no glyph and no pseudo-element with content**, deliberately. The marks
are applied after load, because that is when localStorage can first be read, so anything
occupying space would rewrap the paragraph around it. There is no version of an inline
badge that avoids this — the restyling is the whole of what is safe.

Matching runs twice over: `data-mavo-post-id` first (exact, and on everything this plugin
generates — the §22 foundation), then the URL path. The path is what makes links written
into articles years ago matchable at all.

**Paths, not slugs.** Permalinks are `/YYYY/MM/slug/`, so the last segment *is* the slug —
which is exactly the problem: `/tag/tower-of-london/` and the post share a last segment,
and marking the archive as read would be wrong. The full path is unambiguous, and
normalising it (lowercase, no query, no fragment, no trailing slash) costs nothing.

The path is already in hand — `location.pathname` on each tracked view — so storing it
adds about **1 KB** to a 5 MB quota and is a new optional field on the existing schema, so
no stored profile is invalidated. It **never leaves the browser**: the REST payload is
unchanged, and a test asserts it.

Marks reflect **this visit only** — 20 views, 24 hours. A reader returning next week sees
nothing marked, unlike the browser's own visited-link colour, which the theme does not
style at all. That is the privacy choice, not an oversight.

Switch off with `mavo_for_you_mark_read`; soften by overriding the two opacity values in
`assets/css/mavo-for-you.css`; change where marks may go with
`mavo_for_you_mark_read_selectors`.

The script therefore loads on ordinary pages too (archives, the homepage), where it does
nothing but mark — no request, no tracking. `mavo_for_you_mark_read_everywhere` confines
it to pages that already run the recommendation request.

## Clearing the history

The block ends with a quiet text button — *Effacer mon historique / Clear my history /
Verlauf löschen* — right-aligned, small, muted. No confirmation dialog: there is
nothing to lose but a few post IDs in this browser, and a modal would block the page.
The block is replaced by a one-line acknowledgement.

On a `[geo_related]` page the impersonal block comes **back** when the history is
cleared. The personalised block was only ever standing in its place, so removing the
personalization reveals what was underneath rather than leaving a hole. The controller
keeps the server-rendered section as a live node — detached by the swap, never
destroyed — so restoring it needs no second request and nothing to re-parse. On a page
with nothing underneath, the block simply goes.

Resetting has to do more than drop the storage key. The tracker holds the whole
profile in memory, and its save triggers — the periodic save, hiding the tab, leaving
the page — would each write it straight back. So a reset stops the tracker first, and
tracking does not resume for the rest of that page view: a reset the visitor has to
ask for twice is not a reset. The next page load starts a fresh, empty profile.
`tests/test-frontend.js` covers all three resurrection paths, and both reset outcomes.

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

Caching: `mavo_for_you_pool_cache_ttl`, `mavo_for_you_pool_overfetch`,
`mavo_for_you_shortcode_cache_ttl`.

Shortcode: `mavo_for_you_shortcode_limit`, `mavo_for_you_shortcode_max_limit`,
`mavo_for_you_shortcode_cache_ttl`, `mavo_for_you_shortcode_html`.

Hubs: `mavo_for_you_max_hub_recommendations`, `mavo_for_you_hub_max_depth`,
`mavo_for_you_hub_ancestor_decay`, `mavo_for_you_hub_points`,
`mavo_for_you_hub_child_points`, `mavo_for_you_hub_child_hubs`,
`mavo_for_you_hub_child_pool_size`, `mavo_for_you_hub_labels`.

Geography: `mavo_for_you_geo_levels`, `mavo_for_you_geo_points`,
`mavo_for_you_geo_focus_threshold`, `mavo_for_you_geo_reserved_ratio`,
`mavo_for_you_geo_reserved_slots`, `mavo_for_you_geo_pool_size`.

The /pour-vous/ page: `mavo_for_you_page_langs`, `mavo_for_you_page_slugs`,
`mavo_for_you_page_id`, `mavo_for_you_page_row_size`, `mavo_for_you_page_row_min`,
`mavo_for_you_page_max_rows`, `mavo_for_you_page_rows_per_kind`,
`mavo_for_you_page_geo_places_per_level`, `mavo_for_you_page_link_min_rows`,
`mavo_for_you_page_fallback_filters`, `mavo_for_you_page_fallback_rows`,
`mavo_for_you_page_fallback_cache_ttl`, `mavo_for_you_page_labels`,
`mavo_for_you_page_placeholder_html`.

Already read: `mavo_for_you_mark_read`, `mavo_for_you_mark_read_everywhere`,
`mavo_for_you_mark_read_selectors`.

Behaviour: `mavo_for_you_track_post`, `mavo_for_you_show_block`,
`mavo_for_you_excluded_page_slugs`,
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
php tests/test-utility-pages.php # contact/privacy/legal pages stay out of the profile
php tests/test-rows.php          # /pour-vous/ rows: kinds, order, minimums, redundancy
php tests/test-page.php          # the link's conditions, the page endpoint, the placeholder
php tests/test-shortcode.php     # [geo_related]: impersonal ranking, markup, handover
php tests/test-render.php        # where the placeholder goes, and where it stands down
php tests/test-tuner.php         # the scoring instrument's percentiles and sweep
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
- Read enough for three rows: the block should gain a "Voir toutes vos suggestions"
  link. Read only two articles about one place: no link, because the page behind it
  would be one strip.
- On /pour-vous/: rows appear under their own headings, articles already read are dimmed
  with a check, "Consultés récemment" is last and quieter.
- Narrow the window until a strip overflows: the arrows appear. Widen it until it fits:
  they disappear. Press next at the end of a strip: it returns to the start.
- Open /pour-vous/ in a fresh private window: rows from the catalogue, and copy that
  does not claim they are personal.
- View the source of /pour-vous/ with JS off: one placeholder, one line of copy, no
  tiles and no post IDs.
- Compare the cached HTML of two anonymous visitors: byte-identical, placeholder empty.
- Recommendation and recently-viewed links carry `data-mavo-post-id`.
- Click "Clear my history" on a plain post: the block goes, leaving one line of
  acknowledgement. Reload: nothing until two new meaningful views accumulate.
- Click it on a `[geo_related]` post: the section should turn back into "À lire aussi",
  with the same cards the cached page shipped.
- Read two articles, then open an archive page: tiles for both should be dimmed with a
  check. Links to them inside another article should be muted, with the paragraph
  wrapping exactly as before.
- Clear the history: every mark disappears without a reload.
- On a post with `[geo_related]`: the block is in the cached HTML (view source with JS
  off), sits where the shortcode is, and there is no second block after the content.
- Read two more articles, reload that post: the same section should now be headed
  "Pour vous", same number of cards, with "Consultés récemment" added below.

## Deliberately not in V0

Long-term or cross-device profiles, site-wide "already read" link
rewriting, AI/embeddings/external services, a behavioural analytics table, A/B
testing, affiliate weighting. The architecture leaves room for them; the code does
not anticipate them.
