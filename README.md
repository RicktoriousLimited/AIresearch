# AIresearch Data Preparation Studio

An end-to-end environment for transforming unstructured bios, research notes,
transcripts, and customer updates into structured analytics and AI-ready
training rows. The project now ships with three fully-supported experiences
built on top of the same semantic core:

1. **Data Preparation Studio UI** – A guided web workflow for ingesting messy
   text, reviewing the cleaned output, exploring extracted entities, and
   exporting ready-to-use prompt/response pairs in JSON or CSV.
2. **JSON API** – `/api/analyse.php` accepts POST payloads and returns structured
   extraction data for automation and integrations.
3. **Command Line Interface** – The original `php index.php` utility remains for
   batch processing, scripting, and CSV/JSON exports.
4. **Markets Intelligence** – `web/markets.php` autonomously discovers capital
   markets coverage for any company, analyses sentiment across investor
   personas, and renders highlights suitable for trading desks.

Under the hood every surface uses the same `SemanticEngine`, making it easy to
swap between manual exploration, automated pipelines, and command-line
experiments without sacrificing consistency.

## Quick start

### Web studio

```bash
php -S 0.0.0.0:8000 -t web
```

Open `http://localhost:8000/index.php` and paste a few biographies or company
summaries into the textarea. Click **Run extraction** to see:

- A summary of processed documents, triple count, and unique entities.
- Relation and entity frequency breakdowns for rapid insight discovery.
- Detailed triples and synonym groups with export controls.
- A training dataset preview with prompt/response pairs, task hints, and
  one-click JSON/CSV export buttons.

Use the **Download extraction JSON** action to save the raw result, the
dedicated dataset download buttons for structured AI training rows, or
**Copy summary** to move quick stats into other tools.

### Discovery search

The new discovery interface exposes the shared knowledge graph as a public
search engine.

```bash
php -S 0.0.0.0:8000 -t web
```

Open `http://localhost:8000/search.php` to:

- Query people, organisations, technologies, or relations across every
  extracted triple.
- Browse entity matches with confidence scores, synonym clusters, and fact
  counts.
- Inspect relation histograms, synonym groups, and knowledge triples that
  mention your query in real time.
- Drill into an entity to see its supporting facts and relation highlights,
  with direct links back to the original sources.

- **Markets intelligence dashboard**

`php -S 0.0.0.0:8000 -t web`

Open `http://localhost:8000/markets.php` to generate an autonomous investor
sentiment report for any company or ticker. The dashboard:

- Crawls trusted financial news sources and falls back to curated samples when
  offline.
- Segments tone across retail, institutional, analyst, and insider personas
  using the semantic engine.
- Generates shareable highlights, per-segment metrics, and a timeline dataset
  that can be plotted to reveal sentiment drift.
- Lists the underlying articles so researchers can drill into the supporting
  evidence without leaving the workspace.

### Scrape public URLs into the shared knowledge graph

- From the studio, paste a URL into the new **Scrape from URL** field and
  click **Fetch & analyse**. The app downloads the page, extracts clean text,
  and enriches the persistent graph stored in `storage/graphs/`.
- Visit `http://localhost:8000/knowledge-graph.php` to browse the combined
  triples, relations, and source list that everyone can see.
- Automate ingestion by POSTing `{ "url": "https://example.com/article" }` to
  `/api/scrape.php`; the endpoint merges new entities into the existing graph
  snapshot and returns the updated state.

### API

`POST /api/analyse.php`

```json
{
  "text": "Alice Smith is a Senior Data Scientist. Alice Smith aka Ally Smith."
}
```

Response (truncated):

```json
{
  "data": {
    "triples": [
      { "subject": "alice smith", "relation": "isa", "object": "senior data scientist" },
      { "subject": "alice smith", "relation": "synonym", "object": "ally smith" }
    ],
    "synonyms": [
      { "entity": "alice smith", "synonyms": ["ally smith"] }
    ],
    "relations": { "isa": 1, "synonym": 2 },
    "entities": { "alice smith": 3, "senior data scientist": 1, "ally smith": 1 },
    "summary": {
      "documents_received": 1,
      "documents_processed": 1,
      "triples": 4,
      "synonym_groups": 1,
      "unique_entities": 3,
      "generated_at": "2024-05-30T12:34:56+00:00"
    },
    "state": {
      "graph": { "isa": { "alice smith": { "senior data scientist": true } } },
      "synonyms": { "alice smith": { "ally smith": true } }
      // ...
    }
  },
  "meta": {
    "documents": 1,
    "documents_processed": 1,
    "processing_time_ms": 4
  }
}
```

Supply an array via `documents` to analyse multiple snippets at once. Use the
optional `include` field with any of `triples`, `synonyms`, `relations`,
`entities`, `summary`, or `state` to limit the response payload. Provide the
`state` field from a previous response to incrementally extend the same
knowledge graph with new documents over time.

Every response now also contains a `dataset` object with:

- `rows` – prompt/response pairs covering cleaning, summarisation, keyword
  extraction, and entity graph reconstruction workflows.
- `schema` – machine-readable field descriptions, now enriched with canonical
  relation metadata (type, confidence, status, and provenance hints) to plug
  directly into downstream tooling.
- `statistics` – record counts, average length, task distribution, and
  per-relation breakdowns to help size annotation or fine-tuning jobs while
  spotting extraction skew early.

Download the rows directly from the studio UI (JSON or CSV) or consume them via
the API for automated dataset generation.

### CLI

```bash
php index.php sample.txt -f json -e triples -e synonyms
```

The CLI continues to support JSON, CSV, and text exports, snapshot persistence,
and STDIN piping. Run `php index.php --help` for the full option list.

### Research CLI

```bash
php research.php --list
php research.php --entity "Alice Smith"
php research.php --refresh --max-age=24
```

The dedicated research helper reads the shared graph snapshot from
`storage/graphs/scraped-graph.json` (or a custom path supplied via
`--graph`). Use `--list` to surface the highest-ranked entities and
`--entity` to inspect detailed facts, synonyms, relation histograms, and
supporting signals for a specific person, organisation, or concept. Combine
flags to list the graph and immediately drill into an entity, and adjust
`--facts` or `--limit` to control the breadth of the output. Run with
`--refresh` to re-verify every stored source, rebuild the knowledge base, and
automatically prune pages that have disappeared or no longer resolve. Pair it
with `--max-age` to only re-scrape sources older than a set number of hours.
Supply `--crawl` alongside comma or newline separated seed URLs to auto-scrape
new pages, follow discovered links, and stream them straight into the shared
graph. Combine with `--crawl-limit`, `--crawl-depth`, and
`--crawl-cross-domain` to fine-tune how aggressively the crawler expands across
each site.

### Research service API

The `/api/research.php` endpoint exposes the most common research
workflows:

- `GET /api/research.php?action=list&limit=20` – top ranked entities plus the
  current source list.
- `GET /api/research.php?action=summary&entity=alice%20smith` – full entity
  summary including relation histograms and sample facts.
- `POST /api/research.php` with `{ "action": "refresh", "max_age_hours": 72 }`
  – re-scrape stored sources, drop unreachable pages, and rebuild the graph
  from the surviving content in a single request.
- `POST /api/research.php` with
  `{ "action": "crawl", "seeds": ["https://example.com"], "limit": 6 }` –
  orchestrate an automated crawl from the supplied seed URLs, capture outbound
  links, and merge every scraped page into the shared knowledge graph.

Responses always include timing metadata and omit raw page content, keeping the
API lightweight while guaranteeing the on-disk snapshot stays in sync with the
live sources.

## Data model

The unified extraction result returned by the UI, API, and CLI consists of:

- `triples` – array of `{subject, relation, object}`.
- `synonyms` – array of `{entity, synonyms[]}`.
- `relations` – relation frequency histogram.
- `entities` – entity frequency histogram.
- `summary` – metadata about the run including timestamps and document counts.
- `state` – serialised `SemanticEngine` snapshot for continuing ingestion.

All entity names are normalised and lowercased to ensure consistent matching.

## English lexicon support

The semantic engine now ships with a comprehensive English lexicon sourced from
the `resources/lexicon/english_words.txt` dataset. The lexicon is consulted when
evaluating candidate subject and object spans to ensure they include meaningful
language rather than navigation chrome or random character sequences. The
dictionary also powers new heuristics for rejecting gibberish sentences while
preserving capitalised proper nouns and acronyms.

## Document refinement toolkit

Every extraction run now bundles a per-document cleanup report alongside the
knowledge graph payload. The new toolkit:

- Normalises whitespace and bullet lists to make messy notes readable.
- Produces a plain-language rewrite with consistent casing and sentence breaks.
- Surfaces top keywords for quick data mining and topic clustering.
- Flags misspellings with dictionary-backed suggestions for rapid editing.

The workbench UI exposes these insights in the **Document cleanup** panel, and
the JSON API includes them under the `documents` field for downstream
automation.

## Development

- The shared service layer lives under `src/App/Extraction` and wraps the core
  `SemanticEngine` for stable results across every interface.
- Frontend assets for the workbench are in `web/assets/workbench.*`.
- The ecommerce prototype that previously shipped with the project now lives at
  `web/ricktorious.php` for archival purposes, while the new markets module
  powering `web/markets.php` focuses the semantic stack on autonomous news and
  sentiment analytics.

Contributions and feature ideas are welcome—open an issue or submit a pull
request if you build additional relation detectors, data exporters, or UI
modules.
