# Snapsbrew Design System — "Atomic Diner"

A 1950s café meets retro-futurism (Googie / Atomic Age) visual language.
Think roadside diner signage, Jetsons optimism, starbursts, boomerangs, chrome,
hard-edged print shadows, and a warm pastel palette anchored by bold ink.

The goal: nothing here should read as a stock Tailwind template. Every surface
is opinionated — chunky borders, hard offset shadows, geometric display type.

## Voice

Confident, upbeat, mid-century advertising. Short declarative lines. The product
is "the future of marketing assets, today."

Diner metaphors are *garnish, not the message*. Every headline must state real
product value — what the app does, how fast, what it costs. A diner word
(`open 24 hours`, `on the house`, an order ticket, a jukebox crown) may flavour
a section, but never at the cost of clarity. If a line is a pun and nothing
else, cut it.

## Color

Defined as Tailwind theme colors under the `atomic` namespace.

| Token            | Hex       | Role                                            |
|------------------|-----------|-------------------------------------------------|
| `atomic-cream`   | `#f5ecd7` | Default page background — warm aged paper        |
| `atomic-ink`     | `#211d1a` | Text, borders, hard shadows — near-black brown   |
| `atomic-aqua`    | `#1fb5b8` | Primary brand — turquoise diner tile             |
| `atomic-aqua-dk` | `#0c7e85` | Aqua hover / depth                               |
| `atomic-coral`   | `#f0573f` | Accent + primary CTA — atomic tomato red         |
| `atomic-coral-dk`| `#c63d2a` | Coral hover                                      |
| `atomic-mustard` | `#f2b134` | Secondary accent — butterscotch / starburst      |
| `atomic-pink`    | `#f4a9b8` | Soft surface tint — bubblegum                    |
| `atomic-mint`    | `#bfe3cf` | Soft surface tint — pistachio                    |
| `atomic-chrome`  | `#dfe3e6` | Cool metallic surface                            |

**Rules**
- Backgrounds alternate between `atomic-cream` and a saturated band
  (`atomic-aqua`, `atomic-ink`). Never stack two cream sections plain.
- `atomic-coral` is reserved for primary actions and one hero accent. Do not
  scatter it.
- Borders and shadows are always `atomic-ink`. No gray borders, no blurred
  drop shadows.
- Light text only on `atomic-ink`, `atomic-aqua`, `atomic-coral`,
  `atomic-aqua-dk`.

## Typography

Three families, loaded from Google Fonts in `layouts/guest.blade.php`.

| Family            | Tailwind class | Use                                          |
|-------------------|----------------|----------------------------------------------|
| Righteous         | `font-display` | Headings, eyebrows, stat numbers — geometric |
| Pacifico          | `font-script`  | Accent words, the diner-sign flourish        |
| Poppins           | `font-sans`    | Body copy, UI, buttons                       |

**Rules**
- Headings: `font-display`, `tracking-tight`, ink or light. Righteous is
  all-caps-friendly geometric — let it carry the retro weight.
- Use `font-script` sparingly — one or two words per section max, usually the
  emotional word ("*one click*", "*today*"). Always slightly tilted is wrong —
  keep it upright; the font already has character.
- Body is Poppins 400/500. Buttons Poppins 600–700.
- No small uppercase "eyebrow" labels above headings. The display headline
  carries the section on its own; a kicker line just adds template noise.

## Shape & Depth

The signature look. Mid-century print + signage, not soft web UI.

- **Borders:** `border-[3px] border-atomic-ink` on cards, buttons, images,
  framed panels. Thin gray borders are banned.
- **Hard shadows:** offset, zero blur. Utility: `shadow-atomic` =
  `6px 6px 0 0 #211d1a`. Large feature panels may use `shadow-atomic-lg`
  (`10px 10px 0 0 #211d1a`).
- **Radius:** chunky and asymmetric. Buttons and chips use `rounded-full`
  (pill) — diner-booth piping. Cards use `rounded-2xl`. Some hero panels use
  a boomerang radius (`rounded-[2.5rem_2.5rem_2.5rem_0.5rem]`).
- **Hover:** the press effect — translate the element by its shadow offset and
  shrink the shadow, so it looks pressed down:
  `hover:translate-x-[3px] hover:translate-y-[3px] hover:shadow-[3px_3px_0_0_#211d1a]`.
  Always pair with `transition`.
- **Focus:** `focus-visible:outline focus-visible:outline-[3px]
  focus-visible:outline-offset-2 focus-visible:outline-atomic-ink`.

## Motifs

Decorative atomic-age elements. Use as section accents, never as noise.

- **Starburst:** an asterisk / sparkle in `atomic-mustard` behind or beside a
  heading. SVG or a large `✦` glyph.
- **Boomerang corners:** the asymmetric radius above.
- **Diner stripe:** a thin repeating diagonal band of `atomic-coral` /
  `atomic-cream` as a section divider.
- **Concentric rings / atom:** behind hero, low opacity, ink stroke.

## Components

### Button — primary
```html
<a class="inline-flex items-center gap-2 rounded-full border-[3px] border-atomic-ink
          bg-atomic-coral px-7 py-3.5 font-sans font-bold text-lg text-atomic-cream
          shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px]
          hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a]
          focus-visible:outline focus-visible:outline-[3px]
          focus-visible:outline-offset-2 focus-visible:outline-atomic-ink">
  Start free trial
</a>
```

### Button — secondary
Same shell, `bg-atomic-cream text-atomic-ink` (or `bg-atomic-mustard`).

### Card / framed panel
```html
<div class="rounded-2xl border-[3px] border-atomic-ink bg-atomic-cream
            p-8 shadow-atomic">
  ...
</div>
```

## Diner-Scene Composition

The landing page is staged as a roadside diner, not a stacked SaaS template.
Each section is a recognizable diner object. Reuse these, don't invent generic
"feature rows".

- **Neon sign** (hero): dark `atomic-ink` panel, `5px atomic-chrome` border,
  the brand word glowing via layered `text-shadow` (white core + coral/aqua
  halo). Script sub-line glows mustard.
- **Menu board**: `atomic-ink` chalkboard card, dotted-leader rows
  (`border-b-2 border-dotted`) pairing a feature with a price word
  (`on the house` / `never`). Used for the no-gatekeeping pitch.
- **Order ticket**: cream receipt card, `2px dashed` ink divider rules,
  `Order #` header + endpoint, slightly rotated (`-rotate-1`). Wraps API code.
- **Jukebox** (closing CTA): arched crown (`rounded-t-[9rem]`) in `atomic-aqua`
  with a row of bordered "bulb" dots, ink body, a circular `▶` glyph in the
  button. On arched/organic shapes use `[filter:drop-shadow(9px_9px_0_#211d1a)]`
  on a wrapper, not `box-shadow` — `box-shadow` squares off the arch.
- **Photo wall**: gallery images each given a small `rotate-1` / `-rotate-2`
  tilt so they read as pinned snapshots, not a grid.

Sections alternate saturated bands (ink, aqua, coral, mint) separated by
`border-y-[3px] border-atomic-ink`. Never two plain cream sections in a row.

## Spacing & Layout

- Section vertical rhythm: `py-24 sm:py-32`.
- Containers: `mx-auto max-w-7xl px-6 lg:px-8`.
- Generous whitespace inside framed panels — the borders need room to breathe.
- Asymmetric / bento composition encouraged over uniform card grids.

## Banned

- Blurred drop shadows, gradient blobs, `blur-3xl` decoration.
- Gray-on-white "clean minimal" sections.
- Uniform card grids with equal emphasis.
- Default Tailwind `rose-*` / `indigo-*` accents on guest pages.
- Generic centered-hero + gradient + CTA layout.
