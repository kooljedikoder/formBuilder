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
| REST API | `api/search.php` (now extracts location/sector context from free text, accepts `lat`/`lng`/`radius_km` for near-me distance sort, attaches resolved source per result), `api/suggest.php`, `api/categories.php`, `api/taxonomy.php`, `api/locations.php`, `api/config.php`, `api/health.php` | Done |
| KiliGoogle.ai portal | `portal/index.php`, `assets/css/kili.css`, `assets/js/kili.js` — mobile-first conversational search: welcome message, category chips, debounced autocomplete, result cards (Call/WhatsApp/Website actions, source caption, distance when available), detected sector/category/location breadcrumb, "Highest rated" / "Verified only" / "Near me" (real browser geolocation) quick replies | Done |
| Entry point / hosting | `index.php` (redirect), `.htaccess` (deflate, deny direct JSON access, cPanel-friendly) | Done |

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

## Explicitly NOT built yet (postponed per the revised V1 plan)

Remote DB connections / `.env` config / connection manager, CSV/XLSX import, API
connector with response mapping, conversational forms/journeys, authentication-in-chat,
dynamic/cascading form fields, admin console, widget/SDK, PWA (`manifest.json` + `sw.js`),
SQLite/MySQL/PostgreSQL adapters, multilingual packs, analytics, security hardening
(CSRF/rate limiting/roles), installer wizard. Each should land as its own reviewed,
tested slice.

## Suggested next phase

V1 Phase 2 (KiliGoogle.ai screens) or Phase 4 (Customer Forms) — now that search,
location, sector and source are solid, the next highest-value slice is either the
Category/Location picker screens (G05/G06, which can reuse `api/taxonomy.php` and
`api/locations.php` directly) or a single conversational form ("Request a Quote")
proving state retention through the chat. Recommend forms next, since that's the
transactional feature the plan calls "the really valuable" one — but the taxonomy/
location pickers are a smaller, lower-risk slice if you'd rather de-risk incrementally.

## How to run locally

```bash
cd kilisearch-ultra
php -S 127.0.0.1:8811
# open http://127.0.0.1:8811/portal/index.php
```
