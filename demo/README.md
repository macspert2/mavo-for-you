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

Open either directly; they need no server and fetch nothing.

## Why they are worth keeping

Each page carries constraints that are easy to rediscover the hard way:

- `:visited` accepts only colour-family properties, ignores alpha, and lies to
  `getComputedStyle()`.
- `input[type="submit"]` cannot have `::before` or `::after`, which rules out a
  large share of button styles for the comment and search submits.
- A gradient-drawn underline needs `box-decoration-break: clone`, or it breaks
  on any link that wraps to a second line.
- Rotation scales with width: charming on a chip, broken on a full-width CTA.

## Deploying

They are harmless if deployed (~60 KB of inert files) but serve no purpose in
production. Exclude the `demo/` directory if your deploy has a mechanism for it.
