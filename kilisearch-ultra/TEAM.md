# Team overview — Killi

For anyone (or any account) picking this project up: what it is, where it
stands, and where to go for more detail.

## The pitch

Killi is a search box that talks back. A visitor asks a question, Killi
searches your data, replies conversationally, and remembers frequently-asked
questions well enough to answer them instantly next time — no LLM, no
required database, embeddable in any host app. Free/Standard/Ultra tiers.

## Status right now

- **Branch:** `claude/kilisearch-ultra-build-qsuo20`
- **Open PR:** [#3](https://github.com/kooljedikoder/formBuilder/pull/3) —
  FAQ tied/untied source, feedback ratings, result-detail layouts, plus
  everything pushed on top since (admin overhaul, chat polish, bug fixes,
  taxonomy synonyms, sentiment analysis, voice notes, icon sweep).
- **Merged:** [#1](https://github.com/kooljedikoder/formBuilder/pull/1)
  (core engine, licensing, admin, PWA),
  [#2](https://github.com/kooljedikoder/formBuilder/pull/2) (docs, rename cleanup)
- All four pillars (Search, Conversation, Memory, CRUD) are built and
  tested. Admin has a real dashboard, mobile-responsive nav, dark mode.
- Customer chat also has: Saved/bookmarks and Profile panels, an Advanced
  Search filter panel (moved into the bottom nav), voice-note recording
  (record + upload real audio, distinct from search's speech-to-text
  keyboard dictation), taxonomy/location synonym matching ("cars" →
  Automotive), and offline lexicon-based sentiment detection that reacts
  to frustrated messages with an apology + "Raise a request" / "Try again"
  quick replies. The whole UI is emoji-free — outline SVG icons only.
- A standalone, backend-free stakeholder demo (`chat-demo.html`) mirrors
  the real chat UI and is published as a Claude Artifact for click-through
  walkthroughs without a server.

## Where to look for what

| Question | File |
|---|---|
| "What are the coding conventions / gotchas?" | `CLAUDE.md` |
| "What's the detailed story of every change, and how was it tested?" | `KILLI_BUILD_STATUS.md` |
| "Give me a structured/machine-readable commit history" | `BUILD_HISTORY.json` |
| "How do deployments work?" | `DEPLOYMENT.md` |
| "What do the Free/Standard/Ultra tiers actually unlock?" | `PACKAGES.md` |
| "How do I run this locally?" | `CLAUDE.md` → *Local run*, or below |

## Running it

```
cd kilisearch-ultra
php -S localhost:8000
```
Then open `http://localhost:8000/portal/index.php` (the chat) or
`http://localhost:8000/admin/connections.php` (admin — walks you through
creating the first account). No database needed for the default JSON
demo data; a live MySQL/PostgreSQL connection is optional, configured
from the admin Connections screen.

For a full XAMPP-on-Windows setup instead of PHP's built-in server, see
`DEPLOYMENT.md`.

## Deliberately not built

- **AI-generated replies of any kind.** Every chat response is rule-based
  and template-driven — a hard architectural choice, not a gap. An
  opt-in, narrowly-scoped rephraser was designed but never built; the full
  spec is in `KILLI_BUILD_STATUS.md` if this changes later.
- **ML/LLM-based sentiment analysis.** Built, but as a small offline
  word-list lookup (`SentimentEngine`) with Levenshtein/Soundex typo
  tolerance — not a model. It flags frustrated messages and triggers an
  apology + actionable quick replies; it doesn't generate any text itself,
  so it stays within the "no LLM" rule. A full third-party lexicon
  (AFINN/VADER-style) and an explicit "did you mean 'not'?" clarifying
  question were both considered and rejected — see `BUILD_HISTORY.json` →
  `deferred_or_rejected` for the reasoning.

## Picking this up under a different account or machine

1. Clone or pull the branch above.
2. Any Claude Code session opened in this folder auto-reads `CLAUDE.md`
   for conventions — no separate handoff step needed.
3. `KILLI_BUILD_STATUS.md` and `BUILD_HISTORY.json` are the source of
   truth for "what's already been done" — check them before re-building
   something that already exists.
