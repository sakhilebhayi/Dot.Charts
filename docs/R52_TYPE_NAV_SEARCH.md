# R-52 in Dot.Charts: Roboto, one header, a real search, responsive fixes

Scope: `frontend/` (static Vite pages, vanilla JS) and one new read endpoint in `backend/`. The R-51 Home dashboard keeps its dark-only
identity, its full disclaimer row and its validated chart colour (`--series: #2f8fe6`); only its header, its table at small widths and its
type changed.

## Typography

- Roboto, self-hosted, from dot-ui 0.5.0 (`src/dot-ui/css/dot-roboto.css`, `src/dot-ui/fonts/roboto/`, OFL licence shipped). `instrument.css`
  imports `dot-roboto.css`, `dot-type.css`, the search, switcher and header CSS first, so every page (the landing included) loads them
  in one place; every page has `class="dot-roboto"` on `<html>`.
- Removed: IBM Plex Sans, IBM Plex Sans Condensed and IBM Plex Mono (three Google Fonts files per page), Archivo and Big Shoulders Stencil
  (pulled in by the old switcher mount). Nothing on the frontend contacts a font CDN.
- Kept, on purpose: a monospace stack for data readouts only (`--font-mono` = dot-ui's `--dot-font-mono`: figures, symbols, run status,
  the signal readout). It is the platform's instrument voice and equal-width glyphs keep columns of numbers aligned. It is a system stack,
  so no font file is added. The uppercase, lightly tracked labels, square corners and the amber signal colour stay: that is the identity.
- Retuned for Roboto (it is wider than the condensed face): the landing headline is `clamp(34px, 7.2vw, 76px)` with -0.015em tracking
  (it fits two lines at 320), labels are 12px with .08em tracking (were 10.5 to 11px with .12 to .18em), page titles .01em.
- Placeholders and small quiet text use `--quiet` (#6b7c99, 4.6:1) instead of `--ghost` (about 2:1), which stays for decoration.
- The backend Blade error pages still load the Material Symbols icon font from Google (an icon font, which is allowed), so the
  Content-Security-Policy keeps `fonts.googleapis.com` / `fonts.gstatic.com`. It can be dropped when those icons are inlined.

## Header

`<div id="app-header" data-page="..." data-skip="...">` on seven pages, one module (`src/app-header.js`) that prints the shared dot-ui header
with the static renderer. Nine hand-copied headers (and a second copy of the design system inline in `index.html`) are gone.

- Brand, then Home, Chart read, **Tools** (Backtest, Strategy Builder, Options Vol), **Records** (History, Journal): four visible entries, not
  seven loose links. dot-ui's "More" still catches overflow on narrow desktops.
- Centred search pill (InfoDot's, from dot-ui), then the Dot Apps switcher, then the account menu (signed in) or a "Log in" link (signed out).
- Below 861px: one compact row (logo, search, switcher, menu), a right-hand drawer with the same nav (groups as disclosures), the account
  and "Log out" inside it (signed out: "Log in" and "Create account"), and a full-screen search sheet. This replaces three different
  behaviours (a 3-row wrap, a hidden-scrollbar strip, a 2-row wrap).
- No bell: Dot.Charts has no notifications, so there is nothing real to show.
- Log in and Register stay the plain centred cards (no header): they are the focused sign-in step.
- Switcher: unchanged honest "own sign-in" mode (Charts has no InfoDot session or company, so it cannot know entitlements); icon-only on
  narrow screens, 44px target, scroll-locked panel from dot-ui.

## Search

Signed in, the pill searches the account's own data through `GET /api/search?q=` (`SearchController`, `auth:sanctum`, 40 requests a minute
per account): backtests (symbol, asset class, strategy name as the Backtest page words it), journal entries (title, body, symbol) and saved
strategies (name, description). Every query is `user_id = <token user>`, uses bound LIKE parameters with `%`, `_` and the escape character
escaped, returns at most five rows per group, and selects only light columns (never `results` or the journal body). The pages of the
platform are matched locally. Results open `/home.html?run=ID`, `/journal.html?entry=ID`, `/strategy-builder.html?strategy=ID`; each page
fetches that one record if it is not in the first page it loaded.

Signed out there is no account data to search: the pill says "Search the pages and tools", finds only the pages, makes no request, and the
dropdown says "Log in to search your backtests, journal entries and strategies". The endpoint answers 401 without a token.

States are InfoDot's: empty box (Go to chips and a hint), typed (grouped rows, highlighted match), the loading line in the dropdown only,
no match, plus honest "could not be searched" and "session has expired" lines. Arrow keys move a highlight, Enter opens it, Escape clears
and closes, Ctrl K focuses.

## Responsive

- Home runs table: five columns down to 761px; at 760px and below it becomes stacked run cards with labelled Return, Win rate, Max DD and
  Trades (nothing is dropped), the sort buttons stay as a row on top.
- Equity curves on Backtest, History, Strategy Builder: drawn in a fixed box that the SVG scales to its panel (viewBox, non-scaling stroke):
  no redraw needed on resize or rotation. Home's own chart already redraws on resize.
- Forms: 16px fields under 561px (no iOS zoom), 44px controls, condition cards in the strategy builder reflow to a grid.
- Run and journal action rows wrap and become full-width 44px buttons on a phone.
- Image lightbox: dvh sizing, safe-area close button (44px), scroll lock, Escape, focus returns to the opener.
- `100vh` is paired with `100dvh`; the landing's inline `<style>` and every `style=""` attribute in the pages moved to CSS classes.
