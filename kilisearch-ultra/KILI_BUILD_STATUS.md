# KiliSearch Ultra — Build Status

## Context

There was no existing KiliSearch / KiliGoogle codebase or `kiligoogle-master-bundle.md`
attached to this session's repos (`formbuilder`, `brizy-project-22672745` — the latter
is a single exported Brizy HTML page, the former is the unrelated `kevinchappell/formBuilder`
jQuery plugin). Per direction, this build lives fresh in **`formbuilder/kilisearch-ultra/`**
as a self-contained proof of concept, isolated from the existing formBuilder plugin code.

This build was rescoped after the initial PoC to the revised **V1 plan**: search +
location + source + sector foundation first, conversational/forms/admin layers later.
It proves the core mechanism end to end (search engine → location/taxonomy/source
engines → API → conversational UI → business actions) so we have a real base to
iterate on, rather than a disconnected mockup.

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
| KiliGoogle.ai portal | `portal/index.php`, `assets/css/kili.css`, `assets/js/kili.js` — mobile-first conversational search: welcome message, sector chips, debounced autocomplete, result cards (Call/WhatsApp/Website actions, source caption, distance when available), detected sector/category/location breadcrumb, "Highest rated" / "Verified only" / "Near me" (real browser geolocation) quick replies | Done |
| Entry point / hosting | `index.php` (redirect), `.htaccess` (deflate, deny direct JSON access, cPanel-friendly) | Done |

### This session's additions — Schema Detector + rule-based Conversation Engine

| Area | File(s) | Status |
|---|---|---|
| **Schema detector** | `core/SchemaDetector.php` — infers column types (email/phone/url/date/number/boolean) from sample values and suggests a mapping onto the universal record fields, matching header names ("Business Name", "Phone Number", "Web Address", ...) against known aliases first, falling back to type-based guesses. `api/import.php` — `?action=detect` (CSV upload as multipart `file`, or JSON `{"rows":[...]}`) returns detected columns/types/mapping; `?action=preview` applies a (possibly admin-edited) mapping to the first 5 rows so you can see the resulting Kili records before committing to an import. No import execution/indexing yet — detect+map+preview only, matching the "Upload → Detect → Map → Preview" workflow up to the point where you'd hit Import | Done |
| **Conversation engine (no AI/LLM)** | `core/ConversationEngine.php`, `config/conversation.json` — deterministic keyword-pattern intent detection (`greeting`, `thanks`, `help`, `find_service`, `unknown`) and templated replies with multiple phrasings per intent (varied, not robotic-repeating) selected by `array_rand`; count-bucketed replies for `find_service` (zero/one/many results). All wording lives in the JSON config, editable without touching PHP — that's the "admin handles this" part. `api/chat.php` — new endpoint: small talk gets a templated reply with no search; anything else runs through `kili_extract_context()` (same Location/Taxonomy engines as search.php) → `SearchEngine::search()` → source resolution → a `find_service` reply | Done |
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
  and `?action=preview` produces correctly-shaped Kili records. Playwright re-run of the
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

### Minimal Customer Form + FAQ/memory engine

| Area | File(s) | Status |
|---|---|---|
| **Form engine** | `core/FormEngine.php`, `config/forms.json` — one template ("Enquiry / Support Request": name, contact, message), one question per chat turn, required-field validation with re-ask, `{placeholder}` fill-in on the success message. No auth, no dynamic/cascading fields — deliberately minimal | Done |
| **Form state** | `api/chat.php` — PHP session (`$_SESSION['kili_form']`) holds `{template_id, step, data}` across separate HTTP requests, so the form survives without a database. Started via the `start_enquiry` intent ("raise a request", "make an enquiry", ...) or the zero-result "Raise a request" quick reply | Done |
| **Submissions storage** | `data/submissions.json` via the existing `JsonAdapter` (reused as-is — same zero-DB pattern as listings), `api/submissions.php` to view them | Done |
| **FAQ / "has this been asked before" engine** | `core/FaqEngine.php` — deterministic token-overlap (Jaccard) similarity against `data/faq.json`, no AI/LLM. A match skips the search entirely, returns the curated answer, bumps `hit_count`, and can link back to specific listing records | Done |
| **Query memory (the "learning" half)** | `kili_log_query()` in `bootstrap.php` writes every non-FAQ, non-small-talk message to `data/query_log.json` with a normalized form and a running count. Nothing here writes to `faq.json` automatically — an admin reviews frequent entries and promotes the good ones into a curated FAQ, which avoids ever "confidently" serving a wrong stored answer | Done |
| **Zero-result → ticket bridge** | `api/search.php`'s `find_service` "zero" reply now offers to log a request; `api/chat.php` sets `offer_ticket: true` on zero-result searches; the UI shows a "Raise a request" quick reply that starts the same enquiry form | Done |

## Explicitly NOT built yet (postponed)

Remote DB connections / `.env` config / connection manager, API connector with response
mapping, authentication-in-chat, dynamic/cascading form fields, additional form
templates (quote/booking/vendor/etc.), an admin UI for reviewing `query_log.json` and
promoting entries to `faq.json` (currently a manual JSON edit), admin console generally,
widget/SDK, PWA (`manifest.json` + `sw.js`), SQLite/MySQL/PostgreSQL adapters,
multilingual packs, analytics, security hardening (CSRF/rate limiting/roles), installer
wizard. Each should land as its own reviewed, tested slice.

## Suggested next phase

Two natural candidates:
1. **Admin FAQ-promotion screen** — a small page listing `query_log.json` sorted by
   count, with a "Save as FAQ" button that writes a curated answer into `faq.json`.
   Closes the loop on the memory engine built this session; genuinely small.
2. **Authentication-in-chat** — the form engine currently assumes guest submissions;
   the master plan's harder requirement (form state must survive a login prompt without
   being lost) is now easier to attempt since the session-based form state already
   exists and just needs to survive a redirect/login step rather than being invented
   from scratch.

## How to run locally

```bash
cd kilisearch-ultra
php -S 127.0.0.1:8811
# open http://127.0.0.1:8811/portal/index.php
```
