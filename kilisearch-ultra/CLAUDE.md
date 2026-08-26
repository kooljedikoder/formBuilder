# Killi (KiliSearch Ultra) — codebase guide

Auto-loaded context for any Claude Code session opened in this folder —
this is what lets a fresh session (any account, any machine) pick up
mid-project without re-explaining everything. For the full narrative
changelog, read `KILLI_BUILD_STATUS.md`. For a structured, machine-readable
commit history, read `BUILD_HISTORY.json`. For a human-facing project
overview, read `TEAM.md`.

## What this is

A zero-required-database search + chat + memory + CRUD platform in plain
PHP 8 / vanilla JS — no framework, no LLM. Every chat reply is rule-based
and template-driven, never generated. Ships with Free/Standard/Ultra tiers
gated by `killi_has_feature()`, and an optional live database connection
(MySQL/PostgreSQL) as an alternative to the default JSON-file storage.

## Where things live

- `bootstrap.php` — the app's function library: sessions, auth, entitlements,
  JSON I/O helpers, CSRF, audit log. Almost every `killi_*()` helper lives here.
- `core/` — the engines: `SearchEngine`, `ConversationEngine`, `MemoryEngine`,
  `CrudEngine`, `DataSourceEngine`, `TaxonomyEngine`, `LocationEngine`,
  `EntitlementManager`, `LicenseManager`, `SchemaDetector`, `ConnectionManager`,
  `SentimentEngine` (offline lexicon-based sentiment, no ML — see below).
- `Adapters/` (namespace `Killi\Adapters`) — `StorageInterface` with two
  implementations: `JsonAdapter` (default) and `DbAdapter` (live DB).
- `portal/` — the customer-facing chat app (`index.php`), the try-as-guest
  demo (`demo.php`), PWA manifest/service worker.
- `admin/` — every admin screen shares one chrome (`_chrome.php` +
  `assets/css/admin.css` + `assets/js/admin.js`): dashboard, records, faq,
  feedback, backup, connections, setup. Two pre-auth mini-pages (first-run
  admin creation, login) live inline in `connections.php`, styled but
  intentionally separate from the shared nav (nothing to navigate to before
  logging in).
- `assets/js/killi.js` / `assets/css/killi.css` — the customer chat's
  entire client-side behavior and styling in one file each.
- `api/` — JSON endpoints the chat/admin JS calls (`chat.php`, `upload.php`,
  `feedback.php`, `session_feedback.php`, `records.php`, `export.php`).
- `data/*.json` — the zero-DB storage. Always start empty/demo-seeded on a
  fresh checkout; never commit real customer data here.
- `samples/*.sample.json` — downloadable starter templates for the
  Business Profile / Menu & Catalog result layouts.
- `chat-demo.html` — the stakeholder demo (self-contained, no backend,
  mock data). Published as a Claude Artifact — the file itself has the
  current URL and republish instructions in its header comment. Keep it
  in sync with `portal/`+`killi.js` (see the convention below).

## Conventions that matter

- **No LLM, ever — including the sentiment scorer.** Conversation replies
  are rule-based and template-driven. `SentimentEngine` (added later,
  see below) is also rule-based — a small offline word-list lookup with
  Levenshtein/Soundex typo tolerance, not a model. If a feature needs
  free-text generation, it's out of scope for this codebase as-is (see the
  deferred "AI rephraser" spec in `KILLI_BUILD_STATUS.md` for the one
  exception that was deliberately scoped narrow and never built).
- **No emoji anywhere in the UI — outline SVG icons only.** Swept the
  whole project for this once already (portal, admin, both fully clean).
  Every icon (including the customer chat's theme toggle, attach sheet,
  send button, star ratings) is a stroke-based inline `<svg>` matching
  the existing style (`viewBox="0 0 24 24"`, `stroke: currentColor`,
  `fill: none` unless it's a filled-shape icon like the star/heart).
  `killi.js`'s `ICONS` object is the canonical set — add new icons there,
  don't reach for an emoji character or HTML entity as a shortcut.
- **Every admin page shares `admin/_chrome.php` + `admin.css`.** Don't
  reintroduce a page-local `<style>` block for the base vocabulary
  (`.card`, `.notice`, `button`, `table`, `a.link`, `form.inline`, `label`)
  — it's already themed (light/dark) in `admin.css`. Page-specific extras
  (e.g. feedback's rating rows, setup's step bar) stay local, re-pointed at
  the shared `var(--admin-*)` tokens.
- **Dark mode is token-driven**, not a second stylesheet: `:root` defines
  light, `@media (prefers-color-scheme: dark)` guarded by
  `:not([data-theme="light"])`, then `[data-theme="dark"]` for the explicit
  toggle. Both `killi.css` and `admin.css` follow this pattern — match it
  in anything new.
- **`[hidden]` + a class that sets `display` = broken**, unless you add
  `.your-class[hidden] { display: none; }` explicitly, AND that rule comes
  *after* the base `.your-class { display: ... }` rule in source order
  (equal specificity — last one wins). Getting the order backwards
  silently reintroduces the same bug. Has bitten this codebase repeatedly
  — see `BUILD_HISTORY.json` → `bugs_found_and_fixed`. Check for it
  whenever adding a new `hidden`-toggled panel (the voice-note recording
  bar and the searchbar it replaces both needed this).
- **Word-boundary / Unicode-safe text matching, not raw `str_contains()`.**
  `ConversationEngine::detectIntent()`, `TaxonomyEngine::extractTaxonomy()`,
  and `LocationEngine::extractLocation()` all pad the normalized text with
  spaces and match `' phrase '`, so a short pattern (e.g. "hi") can't
  match *inside* an unrelated word (e.g. "this"). Punctuation stripping
  uses a `\p{L}\p{N}` Unicode class, not `[a-z0-9]` — the latter silently
  deletes every capital letter if it runs before `mb_strtolower()` (real
  bug, real fix, see `bugs_found_and_fixed`). Any new free-text matcher
  should follow this same pattern, not reinvent substring matching.
- **Fuzzy/typo tolerance is layered: exact → Levenshtein → Soundex**,
  gated by a minimum word length (avoid false positives on short words)
  and a max edit distance. `SearchEngine` established this for search
  terms; `SentimentEngine` reuses the identical technique for lexicon
  words. Follow this, don't invent a different fuzzy-matching approach
  for a new feature.
- **Feature gates**: `killi_has_feature('feature_name')` against
  `config/packages.json`. Never hardcode tier checks against a package id.
- **CSRF**: every state-changing admin form needs `killi_csrf_field()` /
  `killi_verify_csrf()`. Every `$_SERVER['REQUEST_METHOD'] === 'POST'`
  branch in `admin/*.php` checks it first.
- **Owner vs. editor**: `killi_is_admin_owner()` gates install-level actions
  (licensing, app password, DB connections, managing other admins).
- **Any UI change also belongs in `chat-demo.html`.** The standalone demo
  (published as a Claude Artifact for stakeholders to click through
  without a server) mirrors the portal chat's structure/behavior in one
  self-contained file with no backend — client-side mock data instead of
  `api/*.php`. When adding a feature to `portal/`+`assets/js/killi.js`,
  port the same UI/behavior into `chat-demo.html` too (fake the backend
  call with local logic — see `sendMessage()`/`replyTo()`/`searchMock()`
  for the existing pattern) and republish the artifact. Skipping this
  leaves the demo visibly behind what the real app can do.

## Testing discipline (non-negotiable — see every entry in KILLI_BUILD_STATUS.md)

Never ship a change on "looks right." For anything touching PHP or JS:
1. `php -l` every touched `.php` file, `node -c` every touched `.js` file.
2. Start a real `php -S localhost:PORT` server from `kilisearch-ultra/`
   and drive it with real headless Chromium (Playwright), not a mocked
   DOM — this codebase has repeatedly shipped bugs that only a real
   render+interaction catches (see `bugs_found_and_fixed` in
   `BUILD_HISTORY.json` — none of those entries would have been caught by
   a syntax check alone).
3. Test both light and dark, and both desktop and mobile widths (admin's
   breakpoint is 860px) when touching anything visual.
4. **Revert every test-mutated file before committing** — `data/*.json`,
   `config/*.json`, anything under `storage/`. Check `git status` and
   `git checkout --` anything you touched only for testing. This has
   caught real accidental leaks of test admin accounts / license overrides
   in this session — always verify before `git add`.

## Working branch

`claude/kilisearch-ultra-build-qsuo20` in `kooljedikoder/formBuilder`.
PR #3 is open and covers everything from the FAQ tied/untied phase onward —
keep pushing to this same branch/PR rather than opening a new one unless
told otherwise. Repo root is one level up from this file; this folder
(`kilisearch-ultra/`) is a subfolder of a larger monorepo, not the repo root.

## Local run

```
php -S localhost:8000
```
from inside this folder, then visit `/portal/index.php` (customer chat)
or `/admin/connections.php` (admin — creates the first admin account on
first visit). No database required unless testing the live-DB feature.
