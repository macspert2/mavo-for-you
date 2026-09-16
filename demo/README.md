# Demo pages

Two standalone HTML/CSS pairs used to choose the site's link and button styles.
**Neither is loaded by the plugin** — nothing here is enqueued, required or
referenced by any PHP. They exist so a visual decision can be made by looking
rather than by imagining, and kept so the reasoning behind the chosen styles
survives.

| File | What it shows |
|---|---|
| `link-styles.html` | Twelve link treatments with all four LVHA states, plus the three proposals — one of which now styles content links in `mv-custom.css`. |
| `button-styles.html` | Twelve button styles with rest / hover / active / focus / disabled shown frozen side by side, plus the three-tier set. Cards 11–12 are the ticket–stamp combination now live on the site's action buttons. |
| `panel-styles.html` | Panel corner radius from 4px to 16px, with real tiles and buttons at 4px inside, at the site's real padding. Shows why corner *proximity* decides this more than the numbers do. |
| `tile-styles.html` | The stamp applied to every real tile variant — media, overlay, compact, result, text, utility — at three strengths, with controls that freeze the whole page at hover or click and switch the corner radius between 4 / 6 / 8 / 14px. |

Open any of them directly; they need no server and fetch nothing. `tile-styles.html`
uses four real thumbnails from the site, saved into `demo/img/` rather than
hotlinked, so it keeps working offline and does not depend on those posts
staying put.

## Why they are worth keeping

Each page carries constraints that are easy to rediscover the hard way:

- `:visited` accepts only colour-family properties, ignores alpha, and lies to
  `getComputedStyle()`.
- `input[type="submit"]` cannot have `::before` or `::after`, which rules out a
  large share of button styles for the comment and search submits.
- A gradient-drawn underline needs `box-decoration-break: clone`, or it breaks
  on any link that wraps to a second line.
- Rotation scales with width: charming on a chip, broken on a full-width CTA.
- A corner travels `(width ÷ 2) × sin(angle)` — 3.4px on a 320px card at 1.2°,
  nearer 8px on a full-width row.
- Not every tile is a button: the utility/TOC tile is a container, and hover
  motion on it promises a click that does not exist.
- Nested radii are governed by the gap between the two corners, not by their
  values: at 40px of padding the eye cannot compare them at all, so a roomy
  panel can carry almost any radius. It is the tight boxes that clash.
- Read token *definitions*, never `var()` fallbacks: `--mv-tile-pad` is
  `clamp(1rem, 2vw, 1.25rem)`, while the fallback written beside it everywhere
  says `1.1rem`. Both these demos got that wrong once.

## Deploying

They are harmless if deployed (~60 KB of inert files) but serve no purpose in
production. Exclude the `demo/` directory if your deploy has a mechanism for it.
