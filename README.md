# AIresearch Semantic Workbench

An end-to-end environment for transforming unstructured bios, research notes, and
company updates into lightweight knowledge graph triples. The project now ships
with three fully-supported experiences built on top of the same semantic core:

1. **Semantic Workbench UI** – A modern web experience for pasting text,
   exploring the extracted triples, reviewing synonym clusters, and exporting
   results.
2. **JSON API** – `/api/analyse.php` accepts POST payloads and returns structured
   extraction data for automation and integrations.
3. **Command Line Interface** – The original `php index.php` utility remains for
   batch processing, scripting, and CSV/JSON exports.

Under the hood every surface uses the same `SemanticEngine`, making it easy to
swap between manual exploration, automated pipelines, and command-line
experiments without sacrificing consistency.

## Quick start

### Web workbench

```bash
php -S 0.0.0.0:8000 -t web
```

Open `http://localhost:8000/index.php` and paste a few biographies or company
summaries into the textarea. Click **Run extraction** to see:

- A summary of processed documents, triple count, and unique entities.
- Relation and entity frequency breakdowns for rapid insight discovery.
- Detailed triples and synonym groups with export controls.

Use the **Download JSON** action to save the raw result, or **Copy summary** to
move quick stats into other tools.

### Scrape public URLs into the shared knowledge graph

- From the workbench, paste a URL into the new **Scrape from URL** field and
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

### CLI

```bash
php index.php sample.txt -f json -e triples -e synonyms
```

The CLI continues to support JSON, CSV, and text exports, snapshot persistence,
and STDIN piping. Run `php index.php --help` for the full option list.

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
  `web/ricktorious.php` for archival purposes but is no longer part of the
  primary product experience.

Contributions and feature ideas are welcome—open an issue or submit a pull
request if you build additional relation detectors, data exporters, or UI
modules.
