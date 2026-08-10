# KilliSearch Ultra — Build Status

## Context

There was no existing KilliSearch / KilliGoogle codebase or `kiligoogle-master-bundle.md`
attached to this session's repos (`formbuilder`, `brizy-project-22672745` — the latter
is a single exported Brizy HTML page, the former is the unrelated `kevinchappell/formBuilder`
jQuery plugin). Per direction, this build lives fresh in **`formbuilder/kilisearch-ultra/`**
as a self-contained proof of concept, isolated from the existing formBuilder plugin code.

This build was rescoped after the initial PoC to the revised **V1 plan**: search +
location + source + sector foundation first, conversational/forms/admin layers later.
It proves the core mechanism end to end (search engine → location/taxonomy/source
engines → API → conversational UI → business actions) so we have a real base to
iterate on, rather than a disconnected mockup.

### Three-pillar architecture

The engine layer is organized around three named pillars, not a growing pile of
similarly-named-but-separate features:

1. **Search Engine** (`core/SearchEngine.php`) — finds and ranks records. Backed by
   supporting engines under the same pillar: **Location** (`LocationEngine`), **Taxonomy**
   (`TaxonomyEngine`), and **Source** (`SourceRegistry`) provide the context (where,
   what kind, from where) that Search ranks against.
2. **Conversational Engine** (`core/ConversationEngine.php`) — deterministic intent
   detection (greeting/thanks/help/find_service/start_enquiry/unknown) and templated
   replies, no AI/LLM.
3. **Memory Engine** (`core/MemoryEngine.php`) — "has this been asked before?" Recalls
   curated answers (`data/faq.json`) via token-overlap similarity, and remembers every
   other question (`data/query_log.json`) so repeated ones become visible for an admin
   to promote into a curated answer.

The **Form Engine** (`core/FormEngine.php`) is a fourth, separate piece — it's a state
machine for multi-step conversational forms, not a search/knowledge concern.

Everything operates against **one connected data source at a time** — which local
dataset that is is now configurable and swappable (see **Data Source Engine** below),
not hardcoded to a single file. There is still no web crawler and no general/open-domain
search. It's a site-search-and-chatbot engine over data you load into it, not a
Google-style engine that indexes the internet.

### Data Source Engine

"Import" isn't just a one-off CSV upload tool — it's one capability of a broader **Data
Source Engine** concept: managing *where* Killi's data comes from right now, and how new
data gets brought in.

- **`core/DataSourceEngine.php`** — picks which pre-configured local dataset backs
  Search. `config/data_sources.json` lists them (`{id, name, type, file, description}`)
  and records which one is `active`. `killi_storage()` in `bootstrap.php` reads whichever
  file is active instead of a hardcoded path — switching sources takes effect on the
  very next request, no re-index step (same as everywhere else in this zero-DB design).
  `api/data_sources.php` lists sources / activates one by id.
- **Two demo datasets ship** to prove swapping genuinely works, not just in theory: the
  original 15-listing Nigeria business directory, and a second, different 6-listing UK
  Home Services demo (plumbers/electricians/cleaners across four London areas). Taxonomy
  (`data/taxonomy.json`) and Location (`data/locations.json`) are **shared reference
  data** across both — only the listings themselves get swapped, which is the correct
  design (a sector tree and a location hierarchy aren't really per-dataset concerns).
- **`SchemaDetector` feeds into it**: `api/import.php?action=publish` takes rows +
  a (possibly admin-edited) field mapping, saves them as a **brand-new, independently
  switchable data source** (registered in `config/data_sources.json`, optionally
  activated immediately) — rather than the existing `action=import`, which merges rows
  into whatever's *currently* active. So there are now two distinct import behaviors:
  "add a few rows to what's already loaded" vs. "this is a whole new dataset, save it
  as its own thing I can switch to later."
- **Not yet included**: remote database or API connections, `.env`/credentials config —
  this slice is local-JSON-file sources only. That's the next piece, per your explicit
  choice to build this smaller part first.

## What's built

### Foundation (V1 Phase 1 — Search + Location + Source + Taxonomy)

| Area | File(s) | Status |
|---|---|---|
| Universal record model | `data/data.json` (15 demo listings with sector/category/subcategory + country/state/city/area + source_id) | Done |
| Zero-DB JSON storage adapter | `adapters/StorageInterface.php`, `adapters/JsonAdapter.php` | Done |
| Core search engine | `core/SearchEngine.php` — exact/partial/token/sector scoring, synonym expansion, Levenshtein fuzzy typo correction, Soundex phonetic fallback, ranking, pagination, autocomplete. Substring matching is gated to tokens ≥3 chars so short aliases ("vi") don't false-positive against unrelated words ("ser**vi**cing") — exact-word matches are unaffected | Done |
| **Location engine** | `core/LocationEngine.php`, `data/locations.json` — Country > State > City > Area hierarchy with aliases (e.g. "vi" → Victoria Island), phrase extraction from free text, haversine distance for "near me" | Done |
| **Taxonomy engine** | `core/TaxonomyEngine.php`, `data/taxonomy.json` — universal Sector > Category > Subcategory tree (12 sectors: Automotive, Hospitality, Healthcare, Technology, ...), phrase extraction from free text | Done |
| **Source engine** | `core/SourceRegistry.php`, `data/sources.json` — every record has a `source_id`; API resolves it to `{id, type, name, url, last_synced}` so results/UI show provenance | Done |
| Config | `config/config.json`, `config/search.json` (synonyms, stop words, scoring weights incl. context bonuses), `config/branding.json` | Done |
| REST API | `api/search.php` (extracts location/sector context from free text, accepts `lat`/`lng`/`radius_km` for near-me distance sort, attaches resolved source per result), `api/suggest.php`, `api/categories.php` (now lists sectors), `api/taxonomy.php`, `api/locations.php`, `api/config.php`, `api/health.php` | Done |
| KilliGoogle.ai portal | `portal/index.php`, `assets/css/killi.css`, `assets/js/killi.js` — mobile-first conversational search: welcome message, sector chips, debounced autocomplete, result cards (Call/WhatsApp/Website actions, source caption, distance when available), detected sector/category/location breadcrumb, "Highest rated" / "Verified only" / "Near me" (real browser geolocation) quick replies | Done |
| Entry point / hosting | `index.php` (redirect), `.htaccess` (deflate, deny direct JSON access, cPanel-friendly) | Done |

### This session's additions — Schema Detector + rule-based Conversation Engine

| Area | File(s) | Status |
|---|---|---|
| **Schema detector** | `core/SchemaDetector.php` — infers column types (email/phone/url/date/number/boolean) from sample values and suggests a mapping onto the universal record fields, matching header names ("Business Name", "Phone Number", "Web Address", ...) against known aliases first, falling back to type-based guesses. `api/import.php` — `?action=detect` (CSV upload as multipart `file`, or JSON `{"rows":[...]}`) returns detected columns/types/mapping; `?action=preview` applies a (possibly admin-edited) mapping to the first 5 rows so you can see the resulting Killi records before committing to an import. No import execution/indexing yet — detect+map+preview only, matching the "Upload → Detect → Map → Preview" workflow up to the point where you'd hit Import | Done |
| **Conversation engine (no AI/LLM)** | `core/ConversationEngine.php`, `config/conversation.json` — deterministic keyword-pattern intent detection (`greeting`, `thanks`, `help`, `find_service`, `unknown`) and templated replies with multiple phrasings per intent (varied, not robotic-repeating) selected by `array_rand`; count-bucketed replies for `find_service` (zero/one/many results). All wording lives in the JSON config, editable without touching PHP — that's the "admin handles this" part. `api/chat.php` — new endpoint: small talk gets a templated reply with no search; anything else runs through `killi_extract_context()` (same Location/Taxonomy engines as search.php) → `SearchEngine::search()` → source resolution → a `find_service` reply | Done |
| **Response consistency** | `api/search.php` now also generates its `meta.reply` from the same `ConversationEngine`, so chip clicks and free-text chat messages produce consistently-worded responses from one place instead of duplicated hardcoded strings in JS | Done |
| **Relevance-gate fix** | `SearchEngine::scoreRecord()` — without an exact phrase match, at least half the query's tokens must match *something*, or the record scores 0. Found via testing: "submarine repair in antarctica" was returning 5 results because "repair" alone matched unrelated tags; regression-checked against every existing test (typo correction, location ranking, alias resolution) before/after | Done |
| **Regression fix** | `data/categories.json` (flat, stale after the sector/category remap) was deleted; chips now render from `TaxonomyEngine`'s sector list and filter via the `sector` param — clicking a chip previously would have returned zero results | Done |

## Verified (this session)

- `php -l` clean on all PHP files; all JSON config/data files parse.
- curl checks: `q=mechanic near vi` correctly resolves the "vi" alias to Victoria Island
  and detects sector=Automotive/category=Vehicle Repair/subcategory=Mechanic, ranking
  actual mechanics above unrelated VI businesses (graceful degradation, not a hard filter
  that would return zero results). `lat`/`lng`/`radius_km` params filter and sort by real
  haversine distance. `mekanik`/`restarant` typo correction still works after the scoring
  change (regression-checked). `api/taxonomy.php` returns 12 sectors, `api/locations.php`
  returns the full area hierarchy.
- Playwright browser run (390×800 mobile viewport, geolocation permission granted, no
  console/page errors): breadcrumb ("Sector: Automotive · Category: Mechanic · Location:
  Victoria Island") renders above the summary; every card shows "Source: Imported
  Directory"; clicking the "Near me" quick reply triggers real browser geolocation,
  re-queries, and re-renders cards sorted by distance ("0.2 km away", "5.6 km away", ...).
- This session: curl-verified `api/chat.php` for greeting/thanks (templated small talk,
  no search call) and `find_service` (reply + detected context + results, matching
  `api/search.php`'s shape); curl-verified `api/import.php?action=detect` correctly maps
  messy headers ("Business Name", "Phone Number", "Web Address") to the universal fields,
  and `?action=preview` produces correctly-shaped Killi records. Playwright re-run of the
  full chat flow: typed "hello" gets a small-talk reply with no cards; "I need a good
  mechanic in Lekki" gets the breadcrumb + server-generated reply + 3 mechanic cards; the
  "Hospitality" sector chip (previously broken) now returns Ocean Basket Lekki; "submarine
  repair in antarctica" adds zero new cards and gets a graceful "no matches" reply — no
  console errors throughout.
- `api/import.php?action=import`: imported 2 test rows end to end — record count went
  15 → 17, the new source ("Test CSV Import") was auto-registered in `sources.json`, and
  the imported record was immediately searchable by title with correct source resolution
  on the very next request (no separate re-index step, since the JSON file is the index).
  Test data was reverted afterward so the shipped demo dataset stays clean.

### Minimal Customer Form + Memory Engine

| Area | File(s) | Status |
|---|---|---|
| **Form engine** | `core/FormEngine.php`, `config/forms.json` — one template ("Enquiry / Support Request": name, contact, message), one question per chat turn, required-field validation with re-ask, `{placeholder}` fill-in on the success message. No auth, no dynamic/cascading fields — deliberately minimal | Done |
| **Form state** | `api/chat.php` — PHP session (`$_SESSION['killi_form']`) holds `{template_id, step, data}` across separate HTTP requests, so the form survives without a database. Started via the `start_enquiry` intent ("raise a request", "make an enquiry", ...) or the zero-result "Raise a request" quick reply | Done |
| **Submissions storage** | `data/submissions.json` via the existing `JsonAdapter` (reused as-is — same zero-DB pattern as listings), `api/submissions.php` to view them | Done |
| **Memory Engine — recall** | `core/MemoryEngine.php` (`killi_memory_engine()`) — deterministic token-overlap (Jaccard) similarity against `data/faq.json`, no AI/LLM. A match skips the search entirely, returns the curated answer, bumps `hit_count`, and can link back to specific listing records | Done |
| **Memory Engine — remember** | `killi_memory_remember_query()` in `bootstrap.php` writes every non-recalled, non-small-talk message to `data/query_log.json` with a normalized form and a running count. Nothing here writes to `faq.json` automatically — an admin reviews frequent entries and promotes the good ones into a curated answer, which avoids ever "confidently" serving a wrong stored answer | Done |
| **Zero-result → ticket bridge** | `api/search.php`'s `find_service` "zero" reply now offers to log a request; `api/chat.php` sets `offer_ticket: true` on zero-result searches; the UI shows a "Raise a request" quick reply that starts the same enquiry form | Done |

*(Originally built as a separate `FaqEngine` + a loose query-logging function; consolidated into one `MemoryEngine` pillar alongside Search and Conversational — same behavior, cleaner architecture.)*

### Chat UX: reactions, attachments, voice, media (this session)

| Area | File(s) | Status |
|---|---|---|
| **Emoji reactions** | `assets/js/killi.js` (reaction row under "final answer" bubbles: find_service/FAQ/small-talk, not breadcrumbs or form questions), `api/feedback.php` + `killi_record_feedback()` — append-only log, same shape as query logging | Done |
| **File attachment** | `api/upload.php` — validates by **sniffed content** (`finfo`), not client-claimed type/filename (prevents a renamed `.php` posing as `.jpg`); allowlist is JPG/PNG/GIF/WEBP/PDF only (SVG/HTML excluded — script-content risk); stores under a random filename in `storage/uploads/`, which has its own `.htaccess` disabling script execution and denying HTML/SVG/JS. `api/chat.php` acknowledges an attachment as its own turn, ahead of form-state/intent handling | Done |
| **Voice input** | `assets/js/killi.js` — Web Speech API, feature-detected (mic button stays `hidden` if unsupported). Fills the input rather than auto-submitting, same "review before sending" pattern as chip-fill, since misheard transcripts are common | Done — **with a caveat**, see Verified below |
| **Bot media responses + card images** | `renderCard()` now shows `record.image` as a card thumbnail; FAQ entries in `data/faq.json` can carry an `image` shown alongside the answer. One demo listing (ABC Auto Services) and one FAQ entry ("what areas do you cover") were given real inline-generated SVG images (data URIs, no external fetch) to prove both paths render, not just in theory | Done |

### Database credentials + real remote connections (this session)

The `.env`-backed Connection Manager postponed earlier now exists, and was tested
against an **actual local PostgreSQL server** started in this environment — not mocked.

| Area | File(s) | Status |
|---|---|---|
| **Connection Manager** | `core/ConnectionManager.php` — named profiles loaded from `.env` via `killi_load_env()`/`killi_connection_manager()` in `bootstrap.php`. `profile()` returns everything except the password (safe for a browser); `credentials()` (internal only) includes it. `testConnection()`, `listTables()`, `fetchRows()` (table name validated as a plain identifier — no SQL injection surface) | Done |
| **Storing credentials safely** | `.env` (gitignored — see `kilisearch-ultra/.gitignore`) + `.env.example` template committed instead. Root `.htaccess` now denies any `.env*` request. `killi_save_env_profile()` writes/updates one profile's keys without disturbing the rest of the file; an empty password field on update means "keep the existing one," never "clear it" | Done |
| **Admin screen** | `admin/connections.php` — plain server-rendered PHP (no JS framework, no build step, consistent with the rest of the app): list profiles (masked) with a Test button, an Add-connection form, per-profile table listing, and a Detect → Preview → Publish flow for turning a live DB table into a new switchable data source | Done |
| **JSON API** | `api/connections.php` (list/test/save/tables) for programmatic use | Done |
| **DB rows feed the existing pipeline** | `api/import.php` now accepts `{"connection": "...", "table": "..."}` as a third row source alongside file upload and JSON rows — same `SchemaDetector` detect/preview/publish flow either way. Published sources are tagged with real provenance (`type: "postgres"`, not generic `"import"`) | Done |

**Verified against a real database, not mocked**: created a throwaway Postgres user/
database/table in this environment, then via `admin/connections.php` in an actual
browser: listed the saved profile (password never shown), tested the connection
(success), tested again with a **deliberately wrong password** (failed cleanly with a
real PDO error message, no crash), listed real tables, detected the real schema of a
2-row table (`business_name→title`, `phone_number→phone`, ...), previewed the mapped
result, and published it as a new active data source — then confirmed via `search.php`
that the live-DB-sourced rows were immediately searchable with correct source
provenance. The test database/user were dropped afterward; nothing DB-specific ships
in the repo, only the mechanism.

**Explicit scope limit — "index & cache" only, not "live query"**: Killi pulls rows
from the database once (via Detect/Publish) into a local JSON dataset and searches
that, the same as every other data source. It does **not** query the database on every
search — that "live query" mode from the original plan would need a PDO-backed
`SearchEngine` adapter, which isn't built. For data that changes constantly, re-running
Publish is currently the refresh mechanism; there's no scheduled sync.

**Voice input caveat**: this sandboxed environment has no real microphone and no
network path to the browser's speech-recognition backend, so live transcription itself
could not be tested end-to-end. What *was* verified: feature detection (the mic button
correctly appears only when `SpeechRecognition` exists in the browser) and that
`recognition.start()` doesn't throw and the error path correctly resets the UI when the
speech service is unreachable. The actual "hear speech → get text" round trip needs
testing in a real browser with microphone access, which this environment cannot provide.

## Explicitly NOT built yet (postponed)

API connector with response mapping (a generic REST API as a data source — separate
from the DB connection work above), authentication-in-chat, dynamic/cascading form
fields, additional form templates (quote/booking/vendor/etc.), an admin UI for
reviewing `query_log.json` and promoting entries to `faq.json` (currently a manual JSON
edit), a broader admin console, widget/SDK, PWA (`manifest.json` + `sw.js`), a
PDO-backed "live query" SearchEngine adapter (see above), multilingual packs,
analytics, security hardening beyond what's described above (CSRF, rate limiting,
roles/auth on the admin screen itself — `admin/connections.php` has no login gate yet,
which is fine for local dev but must be addressed before any real deployment),
installer wizard.

### Chat UI polish (dark mode, typing indicator, ticks, animation)

| Area | File(s) | Status |
|---|---|---|
| **Dark / light theme toggle** | `portal/index.php` (toggle button in header), `assets/css/killi.css` — CSS variables (`--killi-surface`, `--killi-border`, `--killi-muted` added alongside the existing branding-driven vars) redefined under `@media (prefers-color-scheme: dark)` for automatic OS-following, and again under `:root[data-theme="dark"]` for the explicit toggle (persisted via `localStorage`, wins in both directions) | Done |
| **Chip behavior change** | `assets/js/killi.js` — sector chips now **fill the search input** (e.g. "Automotive ") instead of firing a search immediately, so the input is the single "search prompt" surface and chip text can be extended before sending (e.g. "Automotive mechanic in lekki"). Verified this still returns correct results via the existing sector-field token matching, not a behavior regression | Done |
| **Typing/loading indicator** | A bouncing-dots bubble shown during every `search.php`/`chat.php` fetch, removed when the response (or an error) arrives | Done |
| **Two-tick delivery indicator** | Every user message shows a single tick immediately ("sent"); it upgrades to a double tick the moment a reply arrives ("delivered") — cosmetic, since this is a single user↔bot exchange with no real multi-party delivery state | Done |
| **Entrance animation** | Bubbles, quick-reply rows and result cards fade + slide up as they're added, via a CSS keyframe (`killi-rise`) | Done |

Verified via Playwright: chip click fills the input without auto-searching, the
follow-up search still returns correct results; typing indicator appears and is gone
by the time results render; tick upgrades from single to double after a response;
theme toggle changes the background color, and the choice survives a page reload.
Screenshots taken in both themes — chips, cards, quick replies and the search bar all
read cleanly in both.

### Two-tier authentication (this session)

Two deliberately different credentials, matching how each surface should behave:

| Area | File(s) | Status |
|---|---|---|
| **Admin auth — always mandatory** | `bootstrap.php` (`killi_admin_password_configured()`, `killi_is_admin_authenticated()`, `killi_verify_admin_password()`, `killi_set_admin_authenticated()`, `killi_require_admin_auth_json()`). No configured password means "not set up yet," never "wide open" — `admin/connections.php` shows a one-time mandatory setup form (min 8 chars, confirm field) until one exists, then a login form every session after. Hash lives in `.env` as `KILLI_ADMIN_PASSWORD_HASH` (bcrypt via `password_hash()`), never returned by any response | Done |
| **App-wide password — optional, off by default** | Same pattern (`killi_app_password_configured()`, etc., `KILLI_APP_PASSWORD_HASH`), but absent by default so the customer-facing demo stays zero-friction/"plug and play." An admin turns it on from the new "App access" card in `admin/connections.php`. Gates *access* to the whole app instance, not individual features — consistent with the original "no artificial feature-lock passwords" rule | Done |
| **Gated surfaces** | `portal/index.php` shows a password gate page (styled, branding-aware) before rendering the chat when the app password is set. Customer-facing APIs (search/chat/suggest/categories/taxonomy/locations/config/health/upload/feedback) call `killi_require_app_auth_json()` and return a clean 401 JSON error, not a crash, when locked | Done |
| **Admin-only endpoints reclassified** | `api/connections.php`, `api/import.php`, `api/data_sources.php` and `api/submissions.php` (this one exposes submitters' names/contact info — genuinely sensitive) now require admin auth, not app auth — confirmed the customer-facing JS never calls any of these, so this reclassification has zero effect on the chat UI | Done |

**Verified end to end via Playwright** covering the full lifecycle in one run: first-run
admin setup → logout → wrong password rejected → correct login → setting an app
password from the admin UI → a **fresh, unauthenticated browser context** hitting the
locked customer portal (gate page shown, wrong password rejected, correct password
unlocks the real chat) → a separate fresh context hitting an API directly without ever
unlocking (clean 401, not a crash). All 9 checks passed. `.env` was restored to its
password-free state afterward so the shipped default remains fully open, matching the
existing demo experience — nothing changes for anyone who doesn't turn this on.

### License/package entitlements — the "plugged into a main app" hook (this session)

Feature access is package-based (a license bundles a fixed feature set), not arbitrary
per-feature passwords — and critically, **who decides the package is pluggable**: a
host application's own auth system can hand Killi an identity, or Killi falls back to a
configured default when running standalone.

| Area | File(s) | Status |
|---|---|---|
| **Package definitions** | `config/packages.json` — `basic` (search only), `pro` (+ forms, memory, attachments), `enterprise` (+ import, db_connections, multi_source). `default_package: "enterprise"` so nothing is gated in standalone/demo mode unless something explicitly says otherwise | Done |
| **Entitlement checks** | `core/EntitlementManager.php` (package → feature list, pure lookup) + `bootstrap.php` (`killi_current_package()`, `killi_has_feature()`, `killi_require_feature_json()`) | Done |
| **The pluggable hook itself** | `killi_current_package()` reads `$_SESSION['killi_host_user']['package']` first — this is the integration point: a host app authenticates its own user, then sets that session value before handing off to Killi, and Killi trusts it instead of running its own login for this purpose. No host identity present → falls back to `packages.json`'s `default_package` | Done |
| **Hard-gated (admin operations)** | `api/connections.php` requires `db_connections`, `api/data_sources.php` requires `multi_source`, `api/import.php` requires `import` generally and `db_connections` specifically for DB-sourced rows — all return a clean `403 FEATURE_NOT_LICENSED`, checked *in addition to* (not instead of) admin authentication. Confirmed these are genuinely independent axes: an authenticated admin can still be blocked by their package | Done |
| **Gracefully degraded (customer chat)** | `api/chat.php` — starting a form without `forms`, or sending an attachment without `attachments`, gets a plain "not included in your plan" reply rather than an error; missing `memory` silently skips FAQ recall and falls through to ordinary search. Nothing crashes, nothing looks broken — a Basic-tier user just doesn't see the extra capabilities | Done |

**Verified**: default behavior (no host identity) is unchanged — forms/memory/search
all work exactly as before. Simulated a host app injecting `{package: "basic"}` into
the session (the realistic integration shape — set server-side by trusted code, not
exposed via any public endpoint) and confirmed: search still works, starting a form
gets the graceful decline message, and asking a question with a known FAQ match
correctly skips the memory engine and falls through to ordinary search with no error.
Separately, with an authenticated admin session also carrying the `basic` package,
confirmed `data_sources.php` and `connections.php` both return `403` — proving
admin-auth ("are you allowed to administer") and entitlements ("does your plan include
this") are checked independently, exactly as a real licensing model needs.

**Not built**: an actual reference host-app integration (there's no real "main app" to
test against yet — only the hook and a simulated session), and no UI for assigning
users to packages (that's presumably the host app's job, or a future admin screen if
Killi needs to manage packages itself in fully-standalone deployments).

### Reference host-app integration + default-package control (this session)

Both follow-ups from the entitlement work above, closing the two gaps just noted.

| Area | File(s) | Status |
|---|---|---|
| **Reference host-app demo** | `examples/host-app-demo.php` — a small standalone page simulating an external application: "logging in" as a demo user with a chosen package sets `$_SESSION['killi_host_user']` exactly as a real integration's server-side code would, then hands off to the real portal. Clearly commented as illustrative, not a feature | Done |
| **Default-package admin control** | New "Licensing / packages" card in `admin/connections.php` (admin-gated) — lists each package with its features and a dropdown to change `config/packages.json`'s `default_package`, for standalone deployments with no host app to delegate to | Done |

**Verified with a real browser, not just curl**: opened the host-app demo, confirmed
"not signed in" state, logged in as a `basic` user, opened the real Killi portal in the
*same browser context* (shared cookies, exactly like a real handoff), asked it to
"raise a request" and got the graceful decline. Then — without touching Killi at all —
went back to the host-app tab, switched the same session to `enterprise`, returned to
the already-open Killi tab, asked again, and it started the real form. That's the
integration contract working live: the host app is the only thing that changed, and
Killi's behavior followed. Separately verified the admin default-package dropdown:
changed it to `basic`, confirmed `config/packages.json` updated, confirmed a completely
fresh session (no host identity at all) picked up the new default and got forms
blocked. Both test artifacts (`.env`, `config/packages.json`) reverted to their
pre-test state afterward.

### License key activation (this session)

Per explicit direction: "enter your license key to unlock Ultra features," stored
simply in JSON, admin-managed, no login-to-use-the-app conflation. This is a friendlier
front door onto the entitlement system above, not a separate mechanism.

| Area | File(s) | Status |
|---|---|---|
| **Top package renamed** | `enterprise` → **`ultra`** everywhere in `config/packages.json`, matching the product's own name. Nothing in PHP code hardcoded the old id, so this was a safe rename | Done |
| **⚠️ Default package changed: `basic`, not `ultra`** | This is a deliberate behavior change, not a bug: the whole point of a license-key-gated product is that the *unlicensed* state is the free/Basic tier. A fresh install now starts with search only — forms, memory, attachments, imports and DB connections are locked until a valid key is entered. Anyone testing this standalone from here on will see that, unlike every prior session which defaulted to everything unlocked | Done — **flagging clearly since it changes what "out of the box" means** |
| **License list** | `data/licenses.json` — a flat list of `{key, package, status}`, checked with `hash_equals()` (no timing side-channel). No remote activation server, no network call — consistent with the zero-DB philosophy everywhere else in this build. Two demo keys ship for testing (`KILI-PRO-DEMO-0001`, `KILI-ULTRA-DEMO-0001`) | Done |
| **Activation** | `core/LicenseManager.php` (validate a key → package) + `killi_activate_license()` in `bootstrap.php`, which — on a valid key — calls the *same* `killi_set_default_package()` the admin dropdown already used, and records the active key in `.env` as `KILLI_ACTIVE_LICENSE_KEY` (not a secret, but instance-specific activation state, so it lives alongside other per-install config rather than in version-controlled JSON) | Done |
| **Admin UI** | New "Activate a license key" section in the existing "Licensing / packages" card — shows the currently activated key (or "none"), one field, one button. Invalid keys get a clear rejection message | Done |

**Verified end to end**: fresh/unactivated install — search works, forms don't. An
invalid key is rejected cleanly. Activating the demo **Pro** key unlocks forms/memory
but `data_sources.php` (a `multi_source`/db-tier feature) still correctly 403s.
Activating the demo **Ultra** key on top of that unlocks it. All through the real admin
UI, not just the underlying functions. Test artifacts (`.env`, `config/packages.json`)
reverted to their shipped state (unactivated, `basic` default) afterward.

### Naming correction + tier rename (this session)

Per explicit direction:

- The product name is **Killi**, not Killi (e.g. `KilliGoogle.ai`, `KilliSearch Ultra`).
  Corrected everywhere it's user-visible — `config/branding.json`, page titles/headings
  in `portal/index.php`, `admin/connections.php`, `examples/host-app-demo.php`. Left
  internal code identifiers unchanged (the `Killi\Core` PHP namespace, `killi_*` function
  names, CSS classes) since those are invisible implementation details with no
  user-facing effect — renaming ~40 files' namespace/function names would be a large,
  high-risk, purely-cosmetic-internally change. Flagged for confirmation before doing
  that deeper rename, since it's a one-way, hard-to-partially-revert change.
- Tiers renamed to match direction: `basic`→**`free`**, `pro`→**`standard`**,
  `enterprise`→**`ultra`** (done in the previous entry). `default_package` is now
  `"free"`. Demo license keys renamed to `KILLI-STANDARD-DEMO-0001` /
  `KILLI-ULTRA-DEMO-0001`. Verified end to end again after the rename: fresh install →
  Free (search only) → activate Standard demo key → forms unlock, DB features still
  403 — same behavior as before the rename, just correctly named now.
- **`PACKAGES.md`** — a Free/Standard/Ultra comparison table (accurate to what's
  actually gated in code, not aspirational) plus license activation instructions,
  requested as a deliverable in its own right.
- **Guided standalone setup wizard** (`admin/setup.php`) — so a fresh install needs no
  documentation to configure, just "next, next, done." A 3-step linear flow gated
  behind the same admin auth as `connections.php`:
  1. **License** — activate a Standard/Ultra key inline (`killi_activate_license()`),
     or "Continue with Free" to skip.
  2. **App access** — optionally set the app-wide password, or skip to leave it open.
  3. **Done** — a summary (current package, app-access status, active data source)
     with links into the full admin panel and the live app.

  Completion is tracked via a new `KILLI_SETUP_COMPLETE=true` flag in `.env`
  (`killi_setup_complete()` / `killi_mark_setup_complete()` in `bootstrap.php`). Both of
  `connections.php`'s post-auth redirects (`admin_setup` and `admin_login`) now check
  this flag: first-ever login sends you into the wizard, everything after sends you
  straight to `connections.php`. A "Re-run setup wizard" link was added next to "Log
  out" on `connections.php` so it's never a one-shot dead end — you can revisit it
  (e.g. to activate a license bought later) without hand-editing `.env`.

  Verified end-to-end with curl against a live PHP server: fresh admin password setup
  → redirected into the wizard → activated `KILLI-STANDARD-DEMO-0001` at step 1 →
  skipped app password at step 2 → step 3 correctly summarized "Standard / Open (no
  password) / Nigeria Business Directory (Demo)" → "Finish setup" wrote
  `KILLI_SETUP_COMPLETE=true` and returned to `connections.php` → a subsequent login
  went straight to `connections.php`, skipping the wizard, confirming the flag sticks.
  All test-mutated `.env` and `config/packages.json` state was reverted to the clean
  shipped defaults afterward (this repo ships with no admin password set and
  `default_package: "free"`, exactly as before this feature).
- **Tier-representativeness audit** — went back through every chat/branding feature
  built so far (`PACKAGES.md`, chat UX, `admin/connections.php`, `config/branding.json`)
  and checked what's actually gated vs. what a Free/Standard/Ultra licensing model
  should gate. Two findings, both addressed:
  1. **Bug**: `attachments` was gated server-side in `api/chat.php` (a Free install got
     a "feature unavailable" reply) but the 📎 button and `api/upload.php` had no gate
     at all — a Free visitor could pick a file, it would upload and sit in
     `storage/uploads/` on disk, *then* get rejected at the chat-reply step. Fixed by
     hiding the attach button in `portal/index.php` when `!killi_has_feature('attachments')`
     and adding `killi_require_feature_json('attachments')` to `api/upload.php` itself, so
     the endpoint refuses the upload outright rather than accepting-then-discarding.
  2. **Gap**: branding was entirely ungated — a Free install could fully customize
     `config/branding.json` (name, colors, welcome message) with zero indication it's
     running on a licensed platform, which undercuts the whole point of a paid tier in
     a real product. Added a `white_label` feature (Ultra-only) and a small "Powered by
     Killi" line under the search bar in `portal/index.php`, shown on Free and Standard,
     removed on Ultra. This is the first feature that differentiates the *branding*
     itself rather than functionality — Ultra buyers get to look like their own product,
     not just get more capability.

  Deliberately left alone: dark/light mode, voice input, and emoji reactions stay free
  on every tier — they're client-side/negligible-cost UX polish with no real
  business-value differentiation, so gating them would annoy users without giving Ultra
  a meaningful reason to exist. The tier story instead rests on capture/memory
  (Standard) and data ownership + brand (Ultra), which is the stronger differentiator.

  Verified with a live PHP server across all three tiers: Free renders no attach button
  and shows the "Powered by Killi" line; Standard renders the attach button and still
  shows the line; Ultra renders the attach button with no line. Confirmed `api/upload.php`
  rejects a real PNG on Free with `FEATURE_NOT_LICENSED` before any file-type check runs,
  and accepts it once switched to Standard. `config/packages.json` and `.env` were
  restored to clean shipped state afterward.
- **CRUD Engine — the fourth pillar** (Search / Conversational / Memory / **CRUD**).
  Until now every piece of the product only *read* its data — Search finds it, the
  Conversational engine talks about it, Memory remembers what was asked about it — but
  changing a listing meant hand-editing a JSON file or re-running the import flow. This
  closes the loop: full create/read/update/delete over any configured data source,
  from the admin panel.

  - **`core/CrudEngine.php`** — wraps a data source's existing `JsonAdapter` (which
    already did create/update/delete at the storage layer) with the parts a real admin
    tool needs on top: required-field validation (title, valid email format, status
    enum), search/pagination over the listing, and stamping every write with the
    correct `source_id`.
  - **`admin/records.php`** — pick a data source, search/paginate its records, add or
    edit one through a form covering the common fields (title, description, taxonomy,
    location, contact details, tags, rating, verified, status) plus an "additional
    fields (JSON)" textarea so source-specific fields (e.g. `latitude`/`longitude`)
    round-trip without needing a dedicated input for every possible column. Shows an
    upsell notice instead of the tool on Free.
  - **`api/records.php`** — the same engine as a JSON API (`list`/`get`/`create`/
    `update`/`delete`), admin-auth + `crud`-feature gated, for anything that wants to
    manage Killi's data programmatically rather than through the browser.
  - **`api/export.php`** — download any source as JSON or CSV on demand.
  - **`admin/backup.php`** — one-click zip of `data/` + `config/` (never `.env` —
    credentials are never in scope) into `storage/backups/`, with list/download/delete.
    Downloads are served through the script itself (admin-auth gated, filename
    validated against a strict `backup-YYYYMMDD-HHMMSS.zip` pattern) rather than as
    static files — `storage/backups/.htaccess` denies direct access outright for
    deployments where that matters (Apache/Nginx; the PHP built-in dev server used for
    testing doesn't honor `.htaccess`, so this specific rule is asserted, not
    re-verified live, this session).
  - **Audit trail** — every create/update/delete writes an entry to
    `data/audit_log.json` (action, source, record id, summary, admin, timestamp),
    append-only like the existing query/feedback logs.
  - **Gating** — new `crud` feature key, Standard + Ultra (Free stays search-only, no
    change to that boundary).

  Verified end-to-end with a live PHP server: Free shows the upsell notice on both
  `admin/records.php` and `admin/backup.php`; Standard can create/edit/delete a test
  record via both the admin UI and the JSON API, with the change visible in
  `api/search.php` on the very next request (no cache to invalidate — `JsonAdapter`
  reads fresh per request); validation rejects a missing title and malformed
  "additional fields" JSON without saving anything; deleting via the API and the UI
  both work and both audit-log correctly; export produces valid JSON and CSV; backup
  creates a zip containing exactly `data/` + `config/` (confirmed via `unzip -l`),
  downloads correctly, and rejects a `../../bootstrap.php` path-traversal attempt with
  a 404. All test-created records, backups, and mutated `.env`/`config/*.json`/
  `data/*.json` state were removed/reverted to the clean shipped defaults afterward.

## Security, multi-admin, FAQ, chat-auth and live-query phase

Closed out every item in the previous "Suggested next phase" list in one pass.

- **CSRF protection** on every admin form (`connections.php`, `setup.php`,
  `records.php`, `backup.php`, `faq.php`) — a per-session token (`killi_csrf_token()`
  in `bootstrap.php`) rendered as a hidden field and checked on every POST before any
  `do=` handler runs; a mismatch or missing token shows "Form expired" instead of
  silently proceeding.
- **Admin login rate limiting** — 5 failed attempts locks that IP out for 15 minutes
  (`data/login_attempts.json`), checked *before* password verification so even a
  correct password is rejected while locked. The login form disables its own inputs
  while locked rather than just showing an error.
- **Role-based admin access** — replaced the single shared `KILLI_ADMIN_PASSWORD_HASH`
  in `.env` with named accounts in `data/admins.json` (`killi_create_admin()`,
  `killi_verify_admin_login()`, `killi_delete_admin()`, `killi_change_admin_password()`).
  The setup wizard's first step now creates the first named admin instead of a bare
  password; `connections.php` gained an "Admin accounts" card to add/remove admins
  (can't delete yourself, can't delete the last remaining admin) and change your own
  password. The audit log's `admin` field is now the real logged-in username
  (`killi_current_admin_username()`) instead of the hardcoded string `"admin"`.
- **FAQ-promotion screen** (`admin/faq.php`) — lists `query_log.json` sorted by
  ask-count with a one-click "Save as FAQ" link that pre-fills the promotion form;
  also lists and lets you edit/delete existing `faq.json` entries, and dismiss a
  logged query without promoting it. Gated on the `memory` feature (Standard/Ultra),
  matching the Memory engine it curates for.
- **Auth-in-chat** — the app-wide password could previously be turned on by an admin
  *while* a visitor was mid-conversation (possibly mid-way through the multi-turn
  Enquiry form), and the next message would just fail. Added `api/app_auth.php` (a
  JSON unlock endpoint) and an inline unlock prompt rendered directly into the chat
  transcript (`showUnlockPrompt()` in `assets/js/killi.js`) whenever any chat/upload
  call comes back `AUTH_REQUIRED` — no page reload, so the rendered transcript is
  never lost, and after unlocking the exact same message is resent automatically. The
  server-side form state was never actually at risk (it lives in the PHP session,
  independent of the app-password flag) — the fix is entirely about not losing
  client-side chat history to a full-page redirect.
- **Backup restore** — `admin/backup.php` can now restore from an existing stored
  backup or an uploaded zip. Treated as untrusted input throughout: only
  `data/<name>.json` / `config/<name>.json` entries (flat, no `..`, allowlisted
  extension) are considered, and each one's content must itself parse as valid JSON
  before it's written — anything else in the archive is silently skipped, not merged
  or executed. Overwrites matching live files; doesn't delete files added since the
  backup.
- **Live-query DB mode — the other half of "index & cache."** Until now a database
  table could only become a data source by copying its rows into a JSON snapshot
  (`killi_publish_data_source()`); changes in the live table needed a manual
  re-publish to show up in search. Added `core/DbAdapter.php`, a `StorageInterface`
  that calls `ConnectionManager::fetchRows()` fresh on every `all()` — no snapshot,
  no persisted cache, so a row inserted directly in the database appears in search on
  the very next request. `admin/connections.php`'s publish form gained a "Live
  query" checkbox (`killi_publish_live_source()` registers the connection/table/
  mapping in `config/data_sources.json` under `type: "live_db"`, copying zero rows).
  Deliberately read-only: `DbAdapter::save()`/`delete()` throw rather than attempting
  a generic reverse-mapped `UPDATE`/`DELETE` against an arbitrary table schema, which
  is a materially bigger and riskier feature than "make search see live data" —
  `admin/records.php` hides its add/edit/delete controls for a live source and shows
  "read-only" instead, and `api/records.php` surfaces the same restriction as a clean
  422 rather than a crash.
  - **Bug found and fixed while wiring this in**: `CrudEngine`'s constructor was
    type-hinted to the concrete `JsonAdapter` class, not the `StorageInterface` it
    actually only calls methods from — passing it a `DbAdapter` threw a `TypeError`
    (fatal 500) instead of the intended clean "read-only" error. Widened the
    type-hint; this also quietly fixes the (until-now theoretical) constraint that
    `CrudEngine` could only ever wrap a `JsonAdapter`.

  Verified live-query mode against a real Postgres table (not mocked): connected,
  previewed/mapped a custom-column table (`biz_name` → `title`, etc.), published it
  as a live source, confirmed search returned its rows with correct source
  attribution, then inserted a new row directly via `psql` with zero interaction with
  Killi and confirmed it appeared in search on the next request. Confirmed the
  read-only guard end-to-end (UI hides the controls, API returns 422, no 500) and
  confirmed backups correctly capture the live source's *configuration*
  (connection/table/mapping) without attempting to snapshot its data.

  **Second bug found and fixed while testing this**: `admin/connections.php` — the
  page where connection profiles are added, tested, and tables previewed/published —
  had no `db_connections` feature check anywhere. The JSON API endpoints it's paired
  with (`api/connections.php`, `api/import.php`) were correctly gated, but the admin
  UI page itself was not, so a Free install could still add a DB connection and
  publish a source (cached *or* live) directly through the browser, bypassing the
  Ultra tier entirely. Gated the `save`/`test`/`preview`/`publish` actions and the
  "Configured profiles"/"Add a connection"/"Preview" cards behind
  `killi_has_feature('db_connections')`, with the same upsell-notice pattern used on
  `records.php`/`backup.php`/`faq.php`. Verified: Free sees only an upsell card and a
  `save` POST is rejected with a clear message; switching to Ultra restores full
  functionality with no other change.

  All other items verified the same way as prior phases: CSRF rejection, login
  lockout (including "correct password still rejected while locked" and "unlocks
  after a clean attempt"), multi-admin add/self-delete-blocked/delete, the full
  FAQ promotion→recall loop, the auth-in-chat unlock preserving an in-progress
  Enquiry form's exact position, and backup restore's rejection of a hand-crafted
  malicious zip (path traversal, non-JSON, and invalid-JSON entries all skipped;
  only the one legitimate entry was written). Postgres and all test data were torn
  down and every mutated `.env`/`data/*.json`/`config/*.json` file was restored to
  its clean shipped state afterward.

## Permission levels, live-query CRUD, and PWA phase

Closed out the three remaining roadmap items from the previous phase. (The fourth,
the deeper Kili→Killi internal identifier rename, was deliberately skipped — it's
purely cosmetic-internal with no user-visible benefit and a large, hard-to-partially-
revert blast radius across ~40+ files, and stayed unconfirmed.)

- **Per-admin permission levels** — admin accounts now carry a `role`: `owner` or
  `editor`. Owners can manage other admins, licensing, app-access, database
  connections, and backup delete/restore. Editors get the day-to-day surfaces —
  records, FAQ, backup create/download — without those. The very first admin
  (created during setup) is always `owner` regardless of what's passed to
  `killi_create_admin()`, since there's no one yet to have granted them a lesser
  role; `killi_delete_admin()` now also refuses to remove the last remaining owner
  (not just the last remaining admin). `admin/connections.php` gates every
  owner-only `do=` action through one blanket check (mirroring the CSRF check
  pattern) and hides the corresponding UI cards/buttons for editors rather than
  just erroring after the fact. `admin/setup.php` redirects editors away once
  setup is already complete, since re-running the wizard only touches owner-only
  settings.
- **Live-query CRUD** — the live-query mode built last phase was read-only; a
  source can now be marked `writable` at publish time (an explicit opt-in
  checkbox, since writing to someone's live table is a bigger commitment than
  reading from it) to get real `INSERT`/`UPDATE`/`DELETE` through
  `ConnectionManager::insertRow()`/`updateRow()`/`deleteRow()`. `DbAdapter`
  reverse-maps the column→field mapping to translate a canonical record back into
  column names, writing only the fields that *are* mapped to a column — anything
  else in the record has nowhere in the table to go and is silently not persisted.
  Requires the mapping to include a column mapped to `id`; without one there's no
  reliable way to target a row, so `save()`/`delete()` throw a clear error instead
  of guessing. Every table/column name that ends up concatenated into SQL (PDO
  can't parameterize identifiers, only values) is re-validated against a strict
  `[A-Za-z_][A-Za-z0-9_]*` pattern at the point of use — not just trusted from
  stored config — since values still go through prepared-statement placeholders
  but identifiers can't.
- **PWA** — `portal/manifest.php` (dynamic, reads current branding/colors) and
  `portal/icon.php` (a GD-generated PNG icon, brand-color background with a plain
  concentric-ring mark — deliberately no bundled font file, since a TTF is real
  weight/licensing baggage for a two-letter icon and there's no guarantee an
  arbitrary deployment server has one installed) plus `portal/sw.js`, a minimal
  service worker that caches only the static CSS/JS shell — never `index.php`
  itself or any `/api/*.php` call, since those carry live session/branding/search
  state a stale cache would get wrong. An install button appears in the header via
  the standard `beforeinstallprompt` flow. Confirmed standalone-only per direction:
  a `?embed=1` query param (for a host app that's genuinely iframing the page, as
  opposed to opening it in its own tab the way `examples/host-app-demo.php` does)
  suppresses the manifest link, icons, service-worker registration, and install
  button entirely — a host app has its own wrapper story and shouldn't have Killi
  offering to install itself as a separate app on top of it.

Verified end-to-end: an owner created an editor account, logged in as that editor,
and confirmed the Database-connections/Licensing/App-access/Admin-management cards
all show "Owner-only" and the corresponding `do=` actions are rejected even called
directly; confirmed the setup wizard redirects an editor away once setup is marked
complete but not before. Live-query CRUD was tested against a real Postgres table
with custom column names (`item_title`, `item_status`) mapped to canonical fields:
created, updated, and deleted rows through `admin/records.php`, confirming each
change via a direct `psql` query against the same database — not mocked. Confirmed
a read-only (non-writable) live source still rejects writes with a clean error, and
confirmed the identifier-validation defense-in-depth by hand-editing
`data_sources.json` to inject a `"; DROP TABLE items; --"`-style column name into a
mapping and verifying the write was rejected before reaching SQL, with the table
intact afterward. For the PWA, verified with a real Chromium browser (Playwright):
the manifest link and a registered service worker are present on the standalone
page, both are absent with `?embed=1`, and the service worker's cache actually
contains the CSS/JS shell files. All test databases, admin accounts, and mutated
`.env`/`data/*.json`/`config/*.json` files were torn down/reverted afterward.

## PWA polish

Picked the two concrete, well-scoped items off the previous "Suggested next phase"
list — offline fallback page and manifest shortcuts — and left the vaguer/unconfirmed
ones (role granularity beyond owner/editor, live-query CRUD's transaction/concurrency
gaps, the Kili→Killi rename) for later, since none of them had a specific enough
shape yet to build without more direction.

- **Offline fallback page** — `portal/offline.html`, a small brand-neutral static
  page (deliberately not branding-aware, since it must render correctly even with
  zero network access to fetch current branding). `portal/sw.js`'s navigation
  handler now does network-first with `cache: 'no-store'` (so the browser's own
  HTTP cache can't quietly satisfy a "network" fetch that should be failing) and
  falls back to the cached offline page only when the network genuinely can't be
  reached.
- **Manifest shortcuts** — `portal/manifest.php` now lists the first four sectors
  from the taxonomy as installable-app shortcuts (long-press the icon → jump
  straight to Automotive/Hospitality/etc.). Each links to `index.php?sector=X`;
  `assets/js/killi.js` reads that param on load and fills the search input exactly
  the way clicking the sector's chip would — not auto-submitted, consistent with
  the existing "chips are prompts, not direct actions" design.

Verifying the offline fallback surfaced a real test-harness bug worth noting: my
first several attempts used Playwright's `context.setOffline(true)` and consistently
found the *original* page still rendered instead of the fallback. That led down a
genuine dead end (network-first fetch calls can be silently satisfied by the
browser's own HTTP cache instead of failing, which is why `cache: 'no-store'` got
added — a real, worthwhile fix even though it wasn't the actual cause here) before
finding the actual explanation: `setOffline()` didn't reliably block the service
worker's own execution context in this Chromium/Playwright combination. Switching
to killing the real PHP server — with the timing bug in my own test script fixed
(the external kill was landing *after* the reload had already completed against the
still-running server, not before) — reproduced a genuine, unrecoverable network
failure, and the offline page rendered exactly as designed. Also reconfirmed the
manifest's shortcuts array and the `?sector=` deep-link both work as intended in a
live Chromium session.

## Internal Kili→Killi rename

The internal identifier rename flagged as needing explicit confirmation in every
previous entry finally got one — applied across the whole codebase in one pass:
the PHP namespace (`Kili\Core`/`Kili\Adapters` → `Killi\Core`/`Killi\Adapters`),
every `kili_*` global function (~30 of them, e.g. `kili_read_json()` →
`killi_read_json()`), every `kili-`-prefixed CSS class/id/data-attribute in
`assets/css/kili.css` and `assets/js/kili.js`, every `KILI_*` env var key and the
`window.KILI_BRANDING` global, and the general prose "Kili" → "Killi" in comments
and docs. Two files were renamed to match: `assets/js/kili.js` →
`assets/js/killi.js`, `assets/css/kili.css` → `assets/css/killi.css` (with every
reference to them updated), and this file itself, `KILI_BUILD_STATUS.md` →
`KILLI_BUILD_STATUS.md`.

Deliberately **not** renamed: the top-level `kilisearch-ultra/` project folder and
the git branch name. Both are structural/deployment-path concerns rather than
in-code identifiers, carry a much bigger blast radius for zero functional benefit,
and weren't what was specifically flagged as needing confirmation.

Mechanically, this was a single ordered set of find/replace patterns applied across
every `.php`/`.js`/`.css`/`.md` file plus `.env`/`.env.example` (four non-overlapping
character-class patterns — `kili_`, `kili-`, `KILI_`, and the generic word `Kili` —
run in that order, verified not to collide with each other or with the correctly-
spelled `KilliGoogle.ai`/`Killi` text already in place), rather than hand-editing
each file, to eliminate the far larger risk of missing a reference by hand across
~50 files.

Verified: `php -l` clean on every PHP file, `node -c` clean on both JS files, and an
exhaustive grep sweep confirmed zero remaining `kili_`, `kili-`, `Kili\`, or bare
`KILI_` occurrences anywhere in the codebase. Then a full functional regression:
fresh admin creation, login, the complete setup wizard, search, chat, the admin
Records/FAQ/Backups pages, a create-then-delete CRUD round-trip, and the renamed
`assets/css/killi.css`/`assets/js/killi.js` both resolving correctly from the
rendered page — plus a live Chromium/Playwright pass confirming zero console/page
errors and a working chat exchange against the renamed DOM ids/classes
(`#killi-input`, `.killi-bubble`, etc.). All test-mutated `.env`/`data/*.json`/
`config/*.json` state was reverted to the clean shipped defaults afterward.

## Suggested next phase

1. **Role granularity beyond owner/editor** — e.g. a role that can view but not
   modify records, or per-data-source permissions (this editor can touch source A
   but not source B). Still just a sketch, not a scoped feature.
2. **Live-query CRUD's remaining gaps** — no transactions (a failed write mid-batch
   isn't rolled back), no optimistic-concurrency check (two admins editing the same
   live row can silently clobber each other), and tags/booleans are converted with
   a fixed convention (comma-joined string, 1/0) that may not match every schema.

## How to run locally

```bash
cd kilisearch-ultra
php -S 127.0.0.1:8811
# open http://127.0.0.1:8811/portal/index.php
```

## Help documentation

Added `help.html` — a single self-contained page covering all three audiences
(Users, Admins, Developers) behind a sticky top-bar switcher, with a
scroll-spy'd section rail per audience (collapsing to a horizontal chip nav
on mobile). Covers: for **users**, searching/chatting, sectors, voice/
attachments, PWA install, offline behavior, dark mode, and locked-app unlock;
for **admins**, the setup wizard, admin roles, login security, licensing
tiers, app access, data sources (local/import/cached/live), records, FAQ
promotion, and backup/restore; for **developers**, the storage abstraction,
the four pillars, key `bootstrap.php` helpers, API endpoints, host-app
integration, the security model, and PWA internals. Verified with `php -l`,
a Node syntax check on the extracted inline script, and a real headless
Playwright pass (default pane, click-to-switch, hash updates, scroll-spy
highlighting, and the mobile chip-nav breakpoint) — zero console errors.
Also published as a standalone Claude Artifact for quick sharing outside
the repo.

## FAQ: tied vs. untied source

Raised in review: if a live database backs Search/CRUD anyway, is there any
real reason for the Memory pillar's FAQ table to be its own separate,
hardcoded local store? The answer landed on "the matching logic and the
promotion feedback loop are the differentiators, not the storage" — so FAQ
storage itself should collapse into the same `StorageInterface` abstraction
Search/CRUD already use, without losing the ability to run fully standalone
on JSON-only installs. Implemented as an explicit toggle rather than forcing
one model:

- **Untied** (default, unchanged behavior) — FAQ reads/writes its own
  dedicated `data/faq.json`, independent of whatever backs Search/CRUD.
- **Tied** — FAQ reads/writes its own table via the *same named connection*
  as an existing configured profile — one set of DB credentials serving
  every pillar, no separate FAQ connection to maintain. Configured from a
  new "FAQ source" card on the connections page: pick a table from an
  already-configured profile ("Use for FAQ"), map which detected column is
  the id/question/answer, optionally mark it writable so promoting a
  question writes a real row. Untying is one click and keeps the last tied
  connection/table/mapping remembered for re-tying later without re-entering
  them.

`core/MemoryEngine.php` needed zero changes — it already only ever wanted a
plain array of entries, storage-agnostic from the start. The actual gap was
one line in `bootstrap.php`'s `killi_memory_engine()`, hardcoded to
`killi_read_json('data/faq.json')` instead of resolving storage the way
`killi_crud_engine()` already did. Added `killi_faq_storage()` (mirrors
`killi_build_storage()`'s JSON-vs-`DbAdapter` branch, but reads its
connection/table/mapping from a new `faq` block in `data_sources.json`
rather than from whichever source happens to be "active") and rewired
`killi_memory_engine()`/`killi_memory_record_hit()` and every read/write in
`admin/faq.php` (previously raw `file_put_contents` calls) through it.

Caught one real bug before shipping: the first pass built the FAQ mapping as
canonical-field → column, but `DbAdapter`/`SchemaDetector::applyMapping()`
actually expect column → canonical-field — the exact opposite direction.
Untested, this would have silently rendered every tied FAQ's question/answer
as blank. Found immediately by testing tied mode against a **real** local
Postgres 16 database (a throwaway `killi_faq_test` DB with a deliberately
oddly-named `knowledge_base` table — columns `kb_id`/`kb_question`/
`kb_answer`, not the obvious `id`/`question`/`answer` — specifically to
prove the column-mapping direction end to end), not just PHP's own linter:
tying, chat recall against the live DB, promoting a new question (a real
`INSERT`), deleting one (a real `DELETE`), and untying back to the local
store (confirmed the original local `faq.json` was never touched while
tied) were all exercised through actual HTTP requests against a running
`php -S` server, plus a real headless Playwright pass over the admin UI
(login → preview → column-pick → tie → verify status card → untie) with
zero console errors. Postgres and all `.env`/`config/data_sources.json`/
`data/*.json` test state were torn down/reverted to the clean shipped
defaults afterward.

## End-of-conversation ratings + a feedback review screen

Raised in review: visitors could already react to an individual reply
(the existing 😍/👍/😐/👎 emoji row), but nothing let them rate the
conversation as a whole, and — checked while investigating — nothing let
an *admin* review either kind of feedback at all. `data/feedback.json` was
being written to by `api/feedback.php` but had no admin screen reading it
back; it was a write-only sink.

- **Rate this conversation** — a star icon appears in the chat header once
  a visitor has had at least one finished exchange (there's no reliable way
  to detect "the user is done" in a stateless page, so this is a persistent
  affordance rather than an auto-triggered end-of-session popup). Opens a
  small panel: 1-5 stars, an optional comment, Submit. Posts to the new
  `api/session_feedback.php`, which validates the rating (1-5) and stores it
  via `killi_record_session_rating()` in `data/session_feedback.json`,
  alongside the actual transcript being rated (capped to the last 20
  exchanges, 500 characters each, since this comes straight from an
  unauthenticated browser).
- **`admin/feedback.php`** — new admin screen, linked from every other admin
  page's nav row. Two cards: conversation ratings (worst-first, so the
  conversations actually worth reviewing surface on their own, each with an
  expandable transcript) and reply reactions (most recent first, with an
  "😐/👎 only" filter to skip straight to the negative ones). Either kind can
  be dismissed once reviewed.
- Neither collection point nor the review screen is tier-gated — feedback is
  treated as a core operational signal, not a premium feature, matching how
  the existing emoji-reaction endpoint was already ungated.

Verified against a real running `php -S` server with a real headless
Chromium session (not mocked): confirmed the star icon stays hidden until
an exchange completes, submitted an actual 👍 reaction and a real 4-star
rating with a comment through the live chat UI, confirmed both landed in
their JSON files with the exact transcript text, logged into the admin
screen and confirmed the rating/reaction rendered correctly (stars,
comment, transcript, filter), exercised the dismiss action on both and
confirmed the files emptied, and hit the API directly with an out-of-range
and a non-numeric rating to confirm both are rejected with 422. All
test-created admin accounts and mutated `data/*.json` state were reverted
to the clean shipped defaults afterward.

## Result-detail layouts (View → expanded card)

Raised in review, working from a real Google Business Profile screenshot:
Killi's result cards are one fixed shape regardless of what the underlying
data actually is — a coffee shop and a mechanic and a product listing all
render the same title/rating/Call/WhatsApp/Website card. Discussed several
shapes this could take (an admin-authored template language, a slot-picker,
literal custom markup) before converging on the smallest version that's
still real: **3 fixed layouts we build, admins pick one per data source and
supply data — never markup, never new rendering code per install.**

- **Simple** (default, unchanged) — today's compact card only.
- **Business Profile** — photo strip, hours, an "Order online" CTA, a
  Menu/Reviews info-tile pair with a 5-bar rating histogram, address/hours
  footer, and 4 real tabs (Overview/Reviews/Photos/Menu) that actually
  switch visible content, not just styling.
- **Menu & Catalog** — photo strip, price, stock status, and a variant
  list — aimed at product/inventory data rather than a business directory,
  proving the same 3-layout mechanism generalizes past "business listing."

Each layout's fields are entirely optional — a record missing `photos` or
`menu_items` or `order_online_url` just skips that slot, nothing errors.
`DataSourceEngine::layoutFor()` resolves which layout a source uses
(default `"simple"`); `killi_resolve_sources()` stamps the resolved layout
onto every record as `_layout` once per batch (search always queries a
single active source per request, never a merge, so this is safe to do
once rather than per record). A "View" button (real inline SVG icon, not
emoji — matches earlier icon-audit feedback) only renders on cards whose
layout isn't `"simple"`, opening a modal built from small reusable
functions (`buildPhotoStrip()`, `buildActionRow()`, `buildRatingBars()`,
`buildTabs()`) shared across both rich layouts — deliberately written as
separate functions rather than one monolithic renderer per layout, so a
future "compose your own from these slots" option (raised as a phase-2
idea, not built here) recombines what already exists instead of a rewrite.
Every action link is real: `tel:`, a Google Maps address link, the Web
Share API (clipboard fallback), and the actual website URL — each hidden
outright when its field is absent, not shown disabled.

An owner picks the layout per data source from a new dropdown on
`admin/records.php`, next to two downloadable starter files —
`samples/business-profile.sample.json` and `samples/menu-catalog.sample.json`
— so filling in a layout's fields means editing a known-good example, not
guessing key names from scratch.

Caught one real bug before shipping: `killi_set_source_layout()`'s first
draft wrote `foreach ($config['sources'] ?? [] as &$source)` — the `??`
produces a temporary value, so a by-reference foreach over it silently
never mutates the real array. Every layout change would have looked
successful (no error, a success notice) while writing nothing at all.
Caught immediately by testing the actual persisted file after calling the
function, not just checking for a thrown exception — fixed by guarding
emptiness separately and iterating the real array directly.

Verified against a real running `php -S` server and real headless
Chromium (not mocked): set a live demo source to Business Profile,
created an actual record through `CrudEngine` with photos/hours/menu/
rating-breakdown fields, confirmed the View button appears, opened the
modal, confirmed the header/subline/Call-href/CTA-href render the real
data, clicked through all 4 tabs and confirmed each swaps visible content
(Reviews shows the rating, Photos shows both images, Menu shows both
items), closed via Escape. Repeated for Menu & Catalog with a product
record (price/stock/variants, no tabs). Confirmed zero regression on the
untouched default Simple layout — same inline rating, no View button.
Verified the admin dropdown persists a real change via an actual HTTP POST
with a real CSRF token, and that both sample files are reachable at their
real URLs. All test records, the test admin account, and every mutated
`data/*.json`/`config/*.json` file were reverted to the clean shipped
defaults afterward.

## Free-tier query-log gating fix

A real asymmetry, flagged in review: FAQ **recall** was already gated
behind the Standard/Ultra `memory` feature, but query **logging**
(`killi_memory_remember_query()`, called from `api/chat.php`) ran
unconditionally — a Free install was quietly accumulating a query log it
has no admin screen to see (the FAQ page that displays it is itself
memory-gated). Fixed by wrapping that one call in the same
`killi_has_feature('memory')` check recall already uses. One line, no
behavior change for anyone already on Standard/Ultra.

## Phase 2: a 4th "Custom" layout — a slot palette, not a template language

Raised in review: the 3 fixed layouts cover a business directory and a
product/inventory catalog, but not everything — and adding a 5th, 6th,
7th fixed layout for every future vertical doesn't scale. Generalized the
*method* instead: a 4th layout option, `"custom"`, built from the same 6
components the 2 fixed rich layouts already render with — `photos`,
`pricing` (price/price_range + stock_status), `rating` (+ breakdown bars),
`hours`, `items` (menu_items or variants, whichever is present), and `cta`
(order_online_url) — plus the always-on action row every layout gets.
`KILLI_CUSTOM_SLOT_PALETTE` in `bootstrap.php` is the single source of
truth for the 6 valid slot names, checked by both
`killi_set_source_layout()`'s validation and `killi.js`'s
`CUSTOM_SLOT_BUILDERS` map.

Deliberately **not** drag-and-drop reorderable — an admin picks which of
the 6 checkboxes apply on `admin/records.php`, and they always render in
one fixed canonical order (the palette's own order), regardless of the
order the form happened to submit them in. That's what "we don't do a lot
on our side, strict rules" meant in practice: no new rendering code per
admin, no ordering logic to validate, just recomposing the exact same
`buildPhotoStrip()`/`buildRatingBars()`/`buildActionRow()`/etc. functions
the fixed layouts already use — the reuse groundwork laid down when those
were first built paid for itself immediately here, with zero rewrite.

Verified against a real running server and real headless Chromium: set a
source to `custom` with `photos`/`rating`/`items` selected (deliberately
*not* `pricing`/`hours`/`cta`), created a real record via `CrudEngine`,
confirmed the modal rendered exactly those 3 slots and nothing else — no
pricing line, no hours line, no CTA button — while the always-on action
row (a real `tel:` link) still appeared. Submitted a real admin POST with
the checkboxes in `cta, hours, pricing` order and confirmed the persisted
`custom_slots` array came back in the canonical `pricing, hours, cta`
order regardless. All test records/admin/config state reverted afterward.

## Deferred idea, not built: an opt-in AI rephraser

Raised in review — should Killi support plugging in a real AI provider
(model + API key), given the whole point of Killi is answering from a
gated dataset, not the open world? Discussed and deliberately **not
implemented**; recorded here as a scoped handoff for whoever (human or
another AI) picks this up next, since the user may take development to a
different AI IDE before coming back.

**The core tension**: a general LLM's value is world knowledge; Killi's
entire trust promise is the opposite — "I only answer from what you gave
me." Wiring in an AI module to *generate* answers risks hallucinating
specifics about the org's own business that were never in its data — the
one thing Killi is explicitly built not to do.

**The version that's actually worth building**: an AI layer that only
**rephrases an answer SearchEngine/MemoryEngine already retrieved** —
never given free rein to add facts, never shown anything beyond the
matched record(s)/FAQ entry already decided on. Concretely, that means:

- A new opt-in setting, **Ultra-gated**, off by default — matches the
  existing tier pattern (`db_connections` is the closest precedent: an
  Ultra-only capability with its own admin config card).
- A settings screen (provider, model, API key) analogous to
  `admin/connections.php`'s DB connection-profile card — credentials
  belong in `.env`, never in `config/*.json`, same rule as every other
  credential in this app.
- The integration point is narrow and late: in `api/chat.php`, *after*
  `killi_search_engine()->search()` or `killi_memory_engine()->recall()`
  has already produced a reply and result set — never before. The AI call
  receives only that already-decided reply text (and maybe the matched
  record's fields) and returns a reworded version of the same reply; it
  never sees the raw query against open-world knowledge and never gets to
  introduce a fact that wasn't already in the retrieved data.
- Falls back to the plain rule-based reply on any AI-call failure/timeout
  — the feature being off (network error, bad key, timeout) must never
  break the underlying answer, only skip the rewording.

**Known tradeoffs to accept, not solve**: real per-message latency and
cost, a new failure mode (the AI provider being down) the current
zero-dependency rule-based engine doesn't have, and non-determinism (an
LLM can still occasionally ignore a "don't add facts" instruction even
when constrained — this reduces that risk, it doesn't eliminate it).

**Not started**: no code, no settings screen, no provider abstraction.
This section is the spec, not a stub — implement from here.

## Try-it-as-guest demo page

Requested: a way to let evaluators/prospective buyers try each tier
without a real setup. Scoped deliberately as its own page rather than
buttons on the real customer-facing portal — a live deployment's actual
visitors have no reason to see "Try Free/Standard/Ultra," they're there
to search a specific business's data, not shop for Killi itself.

- **`portal/demo.php`** — a standalone, unauthenticated page listing the 3
  tiers with their real feature sets (read live from
  `config/packages.json`, not hand-copied text, so it can't drift out of
  sync). "Try as guest" reuses the *existing* host-app identity hook —
  sets `$_SESSION['killi_host_user'] = ['user_id' => 'demo-guest',
  'package' => $tier]` and redirects into the real `portal/index.php` — so
  a demo run exercises the actual feature gates a real host-app
  integration would, not a separately mocked-up experience. An
  unrecognized `?tier=` value is checked against
  `EntitlementManager::packageIds()` and silently falls through to the
  picker instead of setting anything.
- **`portal/index.php`** — shows a small "Demo mode — browsing as X ·
  Exit demo" banner whenever that session flag is present, so it's never
  ambiguous whether you're looking at a real customer session or a demo
  one. "Exit demo" clears the flag and returns to `demo.php`. Nothing
  about this touches real admin accounts, licensing, or the app-password
  gate — a password-protected deployment still gates a demo guest exactly
  like a real visitor.

Verified against a real running server: fetched `demo.php` and confirmed
all 3 tiers list the right feature counts; clicked "Try Standard" and
confirmed the attachment button appears (Standard+ only) while it's
absent under "Try Free"; confirmed the banner shows the correct tier
name; clicked "Exit demo" and confirmed the banner disappears and the
session flag is actually cleared; confirmed `?tier=admin` (not a real
package id) is rejected and just re-shows the picker rather than setting
anything.

## Bug fix: rating panel visible on every page load

Found while building a standalone chat-preview mockup for the user (not
shipped — a one-off HTML file, not part of this repo) and ported the real
`killi.css` into it for accuracy. Doing that surfaced a real bug in the
shipped CSS: `.killi-rate-panel` sets `display: flex` unconditionally,
which — per how the CSS cascade weighs origins — overrides the browser's
own `[hidden] { display: none }` rule even though `portal/index.php`
renders the panel with the `hidden` attribute. Every fresh page load
showed the 5-star panel floating over the welcome message, before any
conversation happened and before the star button that's supposed to
reveal it.

Fix: added `.killi-rate-panel[hidden] { display: none; }` (the same
pattern already used for `.killi-unlock-error[hidden]`) so the attribute
wins again.

Verified against a real running server: confirmed the panel is
`isVisible() === false` on a fresh load, ran a search so the star button
appears, clicked it, confirmed the panel opens correctly, submitted a
rating, confirmed it still saves to `data/session_feedback.json` as
before.

## Admin interface overhaul: dashboard, pills, mobile bottom nav, dark mode

Requested: a real dashboard, pill-style buttons, and a mobile-responsive
admin — specifically a bottom icon tab bar on small screens, matching the
dark/light polish already in the customer-facing chat and the Interactive
Help Guide.

Every existing `admin/*.php` screen had grown its own copy-pasted
`<style>` block, but they'd all converged on the *same* class vocabulary
(`.card`, `.notice`, `.upsell`, `button`/`button.secondary`/
`button.danger`, `table`, `a.link`, `form.inline`, `label`, `.nav a`) —
so instead of a rewrite, this defines that vocabulary once and swaps only
the outer chrome per page:

- **`assets/css/admin.css`** — one design system for every admin screen.
  Light/dark tokens follow the exact pattern `killi.css` already uses
  (`:root` → `@media (prefers-color-scheme: dark)` guarded
  `:not([data-theme="light"])` → `[data-theme="dark"]` override), so admin
  and the customer chat now share one visual language. Buttons became
  pill-shaped (`border-radius: 999px`). New pieces: a desktop top pill-nav,
  a fixed bottom icon tab bar for ≤860px screens, a slide-up "More" sheet
  for the screens that don't fit the bottom bar, and dashboard-only
  widgets (stat cards, tier pill, source-count rows, quick-action pills).
- **`assets/js/admin.js`** — theme toggle (localStorage `killi-admin-theme`,
  same on/off-system-preference logic as the customer app's toggle) and
  the mobile "More" sheet open/close.
- **`admin/_chrome.php`** (new) — `killi_admin_head()` /
  `killi_admin_body_open($active)` / `killi_admin_body_close()` plus a
  small inline-SVG icon set. One place owns the nav item list
  (`KILLI_ADMIN_NAV_ITEMS`) instead of every page hand-rolling its own
  `<span class="nav">`. The 4 most-used screens (Dashboard, Records, FAQ,
  Feedback) get bottom-bar icons directly; Connections/Backups/Setup live
  under "More" so the bar stays at 5 items on a phone. Also adds a global
  logout icon button, since every page now shares one header.
- **`admin/dashboard.php`** (new) — the tier + feature count, per-source
  record counts (each wrapped in try/catch so an unreachable live-DB
  source shows "—" instead of a fatal), FAQ count, average end-of-chat
  rating, a "negative reactions to review" counter, quick-action pills
  (add a record, import, review feedback, try as guest), and a "latest
  feedback" preview. Every widget has a zero-state string for a fresh
  install instead of a blank card.
- **records/connections/faq/feedback/backup/setup.php** — swapped each
  page's `<html>/<style>/<body>` boilerplate and old `<span class="nav">`
  for the shared chrome; kept every form, CSRF field, and business-logic
  branch byte-for-byte. Page-specific styles that don't belong in the
  shared vocabulary (feedback's rating/reaction/transcript styles, setup's
  step-progress bar) stayed local, just re-pointed at the shared color
  tokens. `connections.php`'s two *pre-authentication* mini-pages (first-run
  admin creation, login form) were deliberately left as their own
  standalone inline-styled pages — they render before any nav would make
  sense, and touching them was outside what was asked.

Verified against a real running server, logged in as a real owner
account: every one of the 7 admin pages renders inside the shared chrome
with the correct active nav pill; the desktop top-nav and mobile bottom-nav
are mutually exclusive at the 860px breakpoint on every page; the mobile
"More" sheet opens/closes and its links navigate correctly; dark mode
toggles and persists across a full page navigation; a real FAQ add, a
real backup creation, and a real login → logout round-trip all still work
through the new chrome; the two pre-auth connections.php gates (admin
creation, login) render exactly as before. All test-created admin
accounts, license overrides, FAQ entries, and backup files were reverted
after testing.

## Chat polish batch: no per-reply prompts, always-on View, camera/video, timestamps

Requested together: (1) stop asking for a rating on every single reply —
only at the end of the session; (2) every listing response should get a
View button, not just ones with rich fields; (3) the attach button should
offer camera photo/video capture, not just a file picker; (4) a visible
timestamp per message. Applied to the real app (`assets/js/killi.js`,
`assets/css/killi.css`, `portal/index.php`, `api/upload.php`) and mirrored
into the standalone chat-demo artifact for parity.

- **Per-reply reactions removed.** `buildReactionRow()`/`REACTION_EMOJI`
  and their CSS are gone; `addBubble()` no longer renders them. The
  end-of-session star panel (`#killi-rate-panel`) is now the only feedback
  prompt — unchanged otherwise, still reveals after the first "final
  answer" AI turn. `admin/feedback.php`'s reaction-review section is left
  in place (harmless; it just stops receiving new rows) rather than torn
  out, since only the chat-side prompting was in scope.
- **View button on every listing.** `renderCard()` no longer gates the
  button behind `record._layout !== 'simple'` — every result gets one.
  `buildBusinessProfileBody()` was already written to skip any field a
  record doesn't have, so a Simple-layout record just opens a sparser
  modal (title, rating, category, call/WhatsApp/website) instead of not
  expanding at all. The now-unused `.killi-card-rating` inline-rating
  style was removed as dead code.
- **Attach → action sheet.** Clicking attach now opens a small bottom
  sheet (Take Photo / Record Video / Choose File) instead of firing a
  single file picker — same slide-up-card pattern as the admin's mobile
  "More" sheet. Camera options use `capture="environment"` on dedicated
  hidden inputs; all three funnel into the same `uploadAttachment()` call.
  `api/upload.php` now accepts `video/mp4`, `video/quicktime`,
  `video/webm` with its own 25MB ceiling (images/PDF stay at 5MB) — an
  early size check at the larger limit runs before MIME sniffing, then a
  type-specific check after. `addAttachmentBubble()` renders a
  `<video controls>` for video attachments.
- **Timestamps.** Every bubble now shows a small `h:mm AM/PM` stamp
  (`toLocaleTimeString`, rendered client-side at paint time — never
  server time, so it always matches the visitor's clock). Restructured
  the tick/timestamp markup into a shared `.killi-bubble-meta` row so
  both sit on one line instead of the tick's old block-level styling;
  `addAttachmentBubble()` (which builds its own bubble outside
  `addBubble()`) gets the same meta row so attachments aren't the one
  bubble type missing a timestamp.

Verified against a real running server: searched for a Simple-layout
listing and confirmed its View button now opens a (sparser) modal that
was previously not expandable at all; confirmed zero `.killi-reaction`
elements render after a search, and that the star rate-button still
reveals after the first answer; opened the attach sheet, picked "Take
Photo," and completed a real upload+render round-trip with a synthetic
image; did the same for "Record Video" with a synthetic minimal MP4
(confirmed `video/mp4` sniffed correctly and rendered with `<video
controls>`); confirmed timestamps render on user, AI, and attachment
bubbles alike, and persist correctly through a dark-mode toggle. All
synthetic uploads were deleted from `storage/uploads/` afterward (already
gitignored, so nothing to revert in git).

## Sentiment analysis: considered, not built

Asked whether to add a sentiment-analysis library to make the chat feel
more responsive to frustration. Recommended against it for now: Killi's
replies are template-driven, not generated, so a real sentiment model
adds a dependency and per-request latency for very little payoff — there's
no free-text generation for it to steer. The data already collected (a
1-2★ end-session rating, a repeated zero-result query) is a cheaper,
zero-dependency signal for the same goal, if a "detect frustration and
soften the fallback reply" feature is wanted later.

## Bug fix: modal height followed whichever tab was tallest

Reported: switching between Overview/Reviews/Photos/Menu resized the
modal itself instead of just the content — jarring since a short tab
(Menu with no items) collapsed the modal small, then Photos popped it
back open tall.

Cause: `.killi-modal` used `max-height: 88vh; overflow-y: auto` as a
single scroll container around header + tabs + all four tab-sections —
so the modal's own box height always matched its currently-*visible*
content, and toggling `.killi-tab-section.current` changed what content
that was.

Fix: `.killi-modal` is now a fixed-height (`min(600px, 88vh)`) flex
column that never resizes. `openRecordModal()` moves everything except
the close button, header, and tabs bar into a new `.killi-modal-body`
wrapper (`flex:1; overflow-y:auto`) — so header/tabs stay pinned and only
the current tab's content scrolls, inside a box that's always the same
size regardless of which tab is showing. `.killi-modal-close` moved from
`position:sticky;float:right` (which doesn't apply the same way inside a
flex column) to `position:absolute` pinned to the modal's top-right
corner; `.killi-modal-header` got right-padding so a long title doesn't
run underneath it. Mirrored the identical fix into the standalone
chat-demo artifact.

Verified against a real running server: seeded a business-profile-layout
record with photos/hours/reviews/menu fields, opened its modal, and
measured `getBoundingClientRect().height` while clicking through all 4
tabs — constant across every one, where it previously varied with each
tab's content. Re-verified for `menu_catalog` layout (single flat body,
no tabs) to confirm the same wrapping logic doesn't break records that
have no `.killi-modal-tabs` at all. Close button still closes the modal
in both cases. All seeded test data reverted afterward.

## Follow-up fix: the fixed height above created a new problem

Reported (with a screenshot) right after the fix above shipped: opening a
sparse record — one with barely any fields, like "Femi's Garage" with
only a phone number and rating — left a huge dead void under the little
content it had. Looked like something was missing; it wasn't, the modal
was just always 600px regardless of how little there was to show.

The literal "fixed height" fix traded one problem for another: no resize
*while switching tabs*, but the same tall box for every record regardless
of how much content it actually has. The right fix locks the height once,
per record, to whatever its tallest tab actually needs — not a global
constant:

- On open, each `.killi-tab-section` is briefly marked `.current` (one at
  a time) purely to read its real `scrollHeight`, then restored — this
  needs the overlay already attached to the document, since a detached
  node reports 0 for any layout measurement.
- The modal's height is set once, inline, to
  `header height + tabs-bar height + tallest tab's content + a few px`,
  capped at `min(600px, 88vh)` (real app) / `min(560px, phone-height × 0.85)`
  (demo) and floored so it's never absurdly short.
- Because this happens once at open — not on every tab click — switching
  tabs still never resizes the modal. A rich record gets a tall box that
  fits its tallest tab; a sparse one gets a short box that fits what it
  actually has.

Verified against a real running server and the demo artifact alike:
measured modal height for a sparse record (324px real app / 280px demo,
down from a flat 600px) and a rich one (unchanged tall height, confirmed
constant across all 4 tabs again). Screenshotted both in dark mode to
match how the bug was originally reported.

## Matching a real Google Business Profile card: dynamic tab label + map thumbnail

Requested against a reference screenshot (a real Google Business Profile
card for an HVAC company): two concrete gaps between that and Killi's
result modal.

- **The 4th tab always said "Menu," even for a mechanic.** Fixed by
  deriving the label from the record's own `category`/`sector`/
  `subcategory` text — `itemsLabel()` checks for food/drink keywords
  (restaurant, bar, cafe, catering, bakery, diner, bistro, food, drink)
  and returns `Menu` only when one matches, `Services` otherwise.
  Deliberately **not** a new admin-set field: one less thing to configure
  per record, and it can't drift out of sync with what the business
  actually is (a restaurant renamed to a bar keeps the right label for
  free). Applied everywhere "Menu" was hardcoded: the tab name, the
  Overview info-tile label, the section lookup key, and the empty-state
  fallback text ("No menu yet." vs "No services listed yet.").
- **No map visual**, just a text "Directions" link. Added
  `buildMapThumb()` — a small (52-56px) self-contained SVG next to the
  address in Overview: a light grid background (suggesting street lines)
  with a red pin drop, styled after Google's own map-pin look. It's a
  real link to the same Google Maps search URL as the Directions button,
  not just decorative. No external map tiles or API calls — stays
  consistent with the rest of the app's zero-external-dependency
  approach, and avoids a Maps API key requirement for a simple visual
  cue. Paired with restructuring the address+hours footer into a proper
  `.killi-modal-location` row (address text + map thumb side by side)
  instead of two plain lines of text.

Mirrored into the standalone chat-demo artifact identically (using
`listing.location` in place of `record.address`, since the demo's mock
data doesn't model a full street address separately from area name).

Verified against a real running server: confirmed an Automotive record
(ABC Auto Services) shows "Services" and a Hospitality/Restaurants
record (Ocean Basket Lekki) shows "Menu," each with a working map
thumbnail linking to the correct Google Maps search. One methodology
trap worth noting for future testing here: `.killi-view-btn` locators
without scoping to a specific card will grab the *first* View button
across the whole accumulated chat history, not the most recent search's
result — cost real time chasing a phantom bug (a second search appeared
to inherit the first search's label) that was actually just clicking the
wrong card. Scope Playwright locators to `.killi-card` with the record's
title text, always.
